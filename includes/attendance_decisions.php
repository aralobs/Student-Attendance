<?php
declare(strict_types=1);
require_once __DIR__.'/delivery_modes.php';
require_once __DIR__.'/enrollment_reports.php';
require_once __DIR__.'/manual_absence_notifications.php';
require_once __DIR__.'/teacher_attendance_policy.php';

function attendanceDecisionSchemaSQL(): string {
    return "CREATE TABLE IF NOT EXISTS attendance_decisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enrollment_id INT NOT NULL, assignment_id INT NOT NULL,
        learning_date DATE NOT NULL, decision VARCHAR(24) NOT NULL, delivery_mode VARCHAR(16) NOT NULL,
        participation_method VARCHAR(40) NULL, reason_ciphertext TEXT NULL, recorded_by INT NOT NULL,
        revision INT NOT NULL DEFAULT 1, confirmed_at DATETIME NOT NULL,
        UNIQUE KEY decision_day(enrollment_id,learning_date), KEY decision_assignment(assignment_id,learning_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS attendance_recompute_queue (
        enrollment_id INT PRIMARY KEY, from_date DATE NOT NULL, revision BIGINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;".manualAbsenceNoticeSchemaSQL();
}
function ensureAttendanceDecisionSchema(PDO $db): void { $db->exec(attendanceDecisionSchemaSQL()); }
function attendanceDecisionLabels(): array {
    return ['present'=>'Present','late'=>'Late','partial'=>'Partial','absent_unexcused'=>'Unexcused absence',
        'absent_excused'=>'Excused absence','pending'=>'Pending participation','review_needed'=>'Needs review',
        'remarked'=>'Manual remark','not_recorded'=>'Not recorded','not_eligible'=>'No class','future'=>'Future date'];
}

function classifyAttendanceDay(array $context, ?array $scan, ?array $decision, DateTimeImmutable $now): array {
    $status='not_recorded'; $mode=$context['mode']??'face_to_face'; $date=$context['date'];
    if (!$context['eligible']) $status='not_eligible';
    elseif ($date>$now->format('Y-m-d')) $status='future';
    elseif ($decision) $status=$decision['decision'];
    elseif ($mode!=='face_to_face') {
        $cutoff=$mode==='modular'?($context['deadline_at']??$date.' 23:59:59'):$date.' 23:59:59';
        $status=$now->format('Y-m-d H:i:s')>$cutoff?'review_needed':'pending';
    } elseif ($scan) {
        $type=$scan['attendance_type']??'';
        $status=match($type) {
            'full_day'=>in_array('late',[$scan['am_status']??null,$scan['pm_status']??null],true)?'late':'present',
            'partial'=>'partial','absent'=>'absent_unexcused','remarked'=>'remarked',default=>'not_recorded'
        };
    }
    if ($status==='pending') {
        $cutoff=$context['deadline_at']??$date.' 23:59:59';
        if ($now->format('Y-m-d H:i:s')>$cutoff) $status='review_needed';
    }
    $present=match($status){'present','late'=>1.0,'partial'=>0.5,default=>0.0};
    $absent=match($status){'absent_unexcused','absent_excused'=>1.0,'partial'=>0.5,default=>0.0};
    return ['status'=>$status,'present_units'=>$present,'absent_units'=>$absent,'countable_unexcused'=>$status==='absent_unexcused',
        'unresolved'=>in_array($status,['pending','review_needed','remarked','not_recorded'],true)];
}

function queueAttendanceRecompute(PDO $db, int $enrollmentId, string $date): void {
    $db->prepare('INSERT INTO attendance_recompute_queue(enrollment_id,from_date) VALUES(?,?) ON DUPLICATE KEY UPDATE from_date=LEAST(from_date,VALUES(from_date)),revision=revision+1')->execute([$enrollmentId,$date]);
}

function saveAttendanceDecision(PDO $db, array $actor, array $command): array {
    requirePanelFeatures($db);
    if ($db->inTransaction()) throw new LogicException('Decision save owns its transaction.');
    $date=validatedSchoolYearDate($command['date']??null); $status=$command['decision']??'';
    $allowed=['present','late','partial','absent_unexcused','absent_excused','pending','review_needed'];
    if (!in_array($status,$allowed,true)) throw new InvalidArgumentException('Choose a valid attendance status.');
    $reason=trim((string)($command['reason']??''));
    if (strlen($reason)>2000 || ($status==='absent_excused' && $reason==='')) throw new InvalidArgumentException('Excused absence requires a reason (maximum 2000 bytes).');
    $method=$command['participation_method']??null;
    if ($method==='') $method=null;
    if ($method!==null && !in_array($method,['live_class','submitted_work','module_submission','parent_contact','other_activity'],true)) throw new InvalidArgumentException('Select an accepted participation method.');
    $db->beginTransaction();
    try {
        $lock=lockAttendanceEnrollment($db,(int)($command['student_id']??0),$date,$actor,(int)($command['section_id']??0));
        requireTeacherAttendanceDate($db,$actor,$date);
        $ctx=$lock['context']; $day=resolveDeliveryDay($db,$ctx['school_year_id'],$ctx['section_id'],$date);
        if (!$day['eligible']) throw new DomainException('Attendance cannot be entered on a no-class date.');
        if ($day['mode']!=='face_to_face' && in_array($status,['present','late','partial'],true) && !$method) throw new InvalidArgumentException('Select how participation was confirmed.');
        $q=$db->prepare('SELECT * FROM attendance WHERE student_id=? AND date=? FOR UPDATE'); $q->execute([$ctx['student_id'],$date]); $scan=$q->fetch(PDO::FETCH_ASSOC);
        if ($scan) verifyAttendanceEnrollment($scan,$ctx);
        $q=$db->prepare('SELECT * FROM attendance_decisions WHERE enrollment_id=? AND learning_date=? FOR UPDATE');
        $q->execute([$ctx['enrollment_id'],$date]); $before=$q->fetch(PDO::FETCH_ASSOC);
        $revision=(int)($before['revision']??0);
        $previousClassification=classifyAttendanceDay(array_merge($day,['date'=>$date,'schedule_type'=>$lock['section']['schedule_type']]),$scan?:null,$before?:null,new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')));
        if (!isset($command['expected_revision']) || (int)$command['expected_revision']!==$revision) throw new DomainException('Attendance changed. Reload before correcting it.');
        $scanRevision=hash('sha256',json_encode($scan?:null,JSON_THROW_ON_ERROR));
        if (isset($command['scan_revision']) && !hash_equals($scanRevision,(string)$command['scan_revision'])) throw new DomainException('Scan evidence changed. Reload before saving.');
        if (($before || $scan) && $reason==='') throw new InvalidArgumentException('A correction reason is required.');
        $cipher=$reason===''?null:encryptPII($reason,'attendance-decision:'.$ctx['enrollment_id'].':'.$date);
        $q=$db->prepare('INSERT INTO attendance_decisions(enrollment_id,assignment_id,learning_date,decision,delivery_mode,participation_method,reason_ciphertext,recorded_by,revision,confirmed_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE assignment_id=VALUES(assignment_id),decision=VALUES(decision),delivery_mode=VALUES(delivery_mode),participation_method=VALUES(participation_method),reason_ciphertext=VALUES(reason_ciphertext),recorded_by=VALUES(recorded_by),revision=VALUES(revision),confirmed_at=VALUES(confirmed_at)');
        $q->execute([$ctx['enrollment_id'],$ctx['assignment_id'],$date,$status,$day['mode'],$method,$cipher,(int)$actor['id'],$revision+1,date('Y-m-d H:i:s')]);
        $q=$db->prepare('SELECT * FROM attendance_decisions WHERE enrollment_id=? AND learning_date=?'); $q->execute([$ctx['enrollment_id'],$date]); $after=$q->fetch(PDO::FETCH_ASSOC);
        securityAuditContext((int)$actor['id']); securityAuditChange($db,'attendance.decision','attendance',(int)$after['id'],$before?:null,$after);
        queueAttendanceRecompute($db,$ctx['enrollment_id'],$date);
        $noticeId=null;
        if($status==='absent_unexcused' && $previousClassification['status']!=='absent_unexcused'){
            $q=$db->prepare('INSERT IGNORE INTO attendance_absence_notifications(enrollment_id,learning_date) VALUES(?,?)');$q->execute([$ctx['enrollment_id'],$date]);
            if($q->rowCount())$noticeId=(int)$db->lastInsertId();
        }
        $db->commit(); $after['revision']=(int)$after['revision'];$after['absence_notice_id']=$noticeId; return $after;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function loadAttendanceSheet(PDO $db, array $actor, int $yearId, int $sectionId, string $date, ?DateTimeImmutable $now=null): array {
    requirePanelFeatures($db); $now??=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $report=loadEnrollmentReport($db,$actor,$yearId,$sectionId,$date,$date);
    $day=resolveDeliveryDay($db,$yearId,$sectionId,$date); $scans=[];
    foreach($report['attendance'] as $scan) $scans[$scan['enrollment_id']]=$scan;
    $q=$db->prepare('SELECT d.* FROM attendance_decisions d JOIN enrollment_sections es ON es.id=d.assignment_id AND es.enrollment_id=d.enrollment_id JOIN student_enrollments e ON e.id=d.enrollment_id WHERE es.section_id=? AND e.school_year_id=? AND d.learning_date=?');
    $q->execute([$sectionId,$yearId,$date]); $decisions=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $decision) $decisions[(int)$decision['enrollment_id']]=$decision;
    $q=$db->prepare('SELECT enrollment_id,status FROM attendance_absence_notifications WHERE learning_date=?');$q->execute([$date]);$notices=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    $result=[];
    foreach($report['roster'] as $student) {
        foreach($student['assignments'] as $assignment) {
            if ((int)$assignment['section_id']!==$sectionId || $date<$assignment['starts_on'] || $date>$assignment['ends_on']) continue;
            $scan=$scans[$student['enrollment_id']]??null; $decision=$decisions[$student['enrollment_id']]??null;
            if ($decision && (int)$decision['assignment_id']!==(int)$assignment['assignment_id']) throw new DomainException('Attendance decision ownership requires reviewed correction.');
            $context=array_merge($day,['date'=>$date,'schedule_type'=>$assignment['schedule_type']??'full_day']);
            $student['scan']=$scan; $student['decision']=$decision; $student['delivery']=$day; $student['classification']=classifyAttendanceDay($context,$scan,$decision,$now);
            $student['scan_revision']=hash('sha256',json_encode($scan?:null,JSON_THROW_ON_ERROR));
            // The write command hashes the database row, not report-enriched columns.
            if ($scan) { $read=$db->prepare('SELECT * FROM attendance WHERE id=?'); $read->execute([$scan['id']]); $student['scan_revision']=hash('sha256',json_encode($read->fetch(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR)); }
            $student['section_id']=$sectionId; $student['assignment_id']=(int)$assignment['assignment_id'];
            $student['absence_notification_status']=$notices[$student['enrollment_id']]??null;
            $result[]=$student; break;
        }
    }
    return $result;
}

/** Bounded report read: one scan/decision load, then cached section/day resolution. */
function loadAttendanceTimeline(PDO $db, array $actor, int $yearId, ?int $sectionId, string $from, string $to, DateTimeImmutable $now): array {
    requirePanelFeatures($db);
    $report=loadEnrollmentReport($db,$actor,$yearId,$sectionId,$from,$to); $scans=[]; $decisions=[]; $cache=[]; $rows=[];
    foreach($report['attendance'] as $scan) $scans[$scan['enrollment_id'].':'.$scan['date']]=$scan;
    $q=$db->prepare('SELECT d.* FROM attendance_decisions d JOIN student_enrollments e ON e.id=d.enrollment_id WHERE e.school_year_id=? AND d.learning_date BETWEEN ? AND ?');
    $q->execute([$yearId,$from,$to]); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $d) $decisions[$d['enrollment_id'].':'.$d['learning_date']]=$d;
    foreach($report['roster'] as $student) foreach($student['assignments'] as $assignment) {
        if ($sectionId!==null && (int)$assignment['section_id']!==$sectionId) continue;
        $start=max($from,$assignment['starts_on']); $end=min($to,$assignment['ends_on']);
        for($day=new DateTimeImmutable($start); $day->format('Y-m-d')<=$end; $day=$day->modify('+1 day')) {
            $date=$day->format('Y-m-d'); $key=$student['enrollment_id'].':'.$date; $cacheKey=$assignment['section_id'].':'.$date;
            $context=$cache[$cacheKey]??=array_merge(resolveDeliveryDay($db,$yearId,(int)$assignment['section_id'],$date),['date'=>$date,'schedule_type'=>$assignment['schedule_type']??'full_day']);
            $decision=$decisions[$key]??null;
            if($decision && (int)$decision['assignment_id']!==(int)$assignment['assignment_id']) throw new DomainException('Decision ownership needs reviewed correction.');
            $classification=classifyAttendanceDay($context,$scans[$key]??null,$decision,$now);
            $rows[]=array_merge($classification,['date'=>$date,'enrollment_id'=>(int)$student['enrollment_id'],'student_id'=>(int)$student['student_id'],
                'section_id'=>(int)$assignment['section_id'],'mode'=>$context['mode'],'eligible'=>$context['eligible']]);
        }
    }
    usort($rows,fn($a,$b)=>[$a['date'],$a['enrollment_id']]<=>[$b['date'],$b['enrollment_id']]);
    return ['report'=>$report,'days'=>$rows];
}

/** Official forms get decision-aware rows without mutating the original attendance table. */
function overlayAttendanceDecisions(PDO $db,array $report):array {
    $ranges=[];
    foreach($report['roster'] as $student) foreach($student['assignments'] as $a) {
        $ranges[]='(d.enrollment_id='.(int)$student['enrollment_id'].' AND d.assignment_id='.(int)$a['assignment_id'].' AND d.learning_date BETWEEN '.$db->quote($a['starts_on']).' AND '.$db->quote($a['ends_on']).')';
    }
    $where=$ranges?implode(' OR ',$ranges):'1=0';
    $q=$db->query('SELECT d.id FROM attendance_decisions d WHERE '.$where); $decisionIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $ids=implode(',',$decisionIds?:[0]); $scanIds=implode(',',array_map('intval',array_column($report['attendance'],'id'))?:[0]);
    $columns=[];
    foreach($db->query('SHOW COLUMNS FROM attendance')->fetchAll() as $column) {
        $name=$column['Field']; if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$name)) throw new LogicException('Invalid attendance column.');
        $present="CASE WHEN d.decision='late' THEN 'late' WHEN d.decision IN('present','partial') THEN 'present' WHEN d.decision IN('absent_excused','absent_unexcused') THEN 'absent' ELSE NULL END";
        $expr=match($name) {
            'id'=>'COALESCE(a.id,-d.id)','student_id'=>'e.student_id','enrollment_id'=>'d.enrollment_id','assignment_id'=>'d.assignment_id','date'=>'d.learning_date',
            'am_status'=>"IF(sec.schedule_type='pm_only',NULL,$present)",
            'pm_status'=>"IF(sec.schedule_type='am_only',NULL,IF(d.decision='partial' AND sec.schedule_type='full_day','absent',$present))",
            'attendance_type'=>"CASE WHEN d.decision IN('present','late') THEN 'full_day' WHEN d.decision='partial' THEN 'partial' WHEN d.decision IN('absent_excused','absent_unexcused') THEN 'absent' ELSE 'remarked' END",
            'remarks'=>"CASE d.decision WHEN 'absent_excused' THEN 'Excused absence' WHEN 'pending' THEN 'Pending participation' WHEN 'review_needed' THEN 'Needs review' ELSE COALESCE(a.remarks,'') END",
            'recorded_by'=>'d.recorded_by',default=>'a.`'.$name.'`'
        };
        $columns[]=$expr.' AS `'.$name.'`';
    }
    $source='(SELECT a.*,es.section_id AS report_section_id,NULL AS decision_status FROM attendance a JOIN enrollment_sections es ON es.id=a.assignment_id AND es.enrollment_id=a.enrollment_id WHERE a.id IN('.$scanIds.') AND NOT EXISTS(SELECT 1 FROM attendance_decisions d WHERE d.id IN('.$ids.') AND d.enrollment_id=a.enrollment_id AND d.learning_date=a.date)
        UNION ALL SELECT '.implode(',',$columns).',es.section_id AS report_section_id,d.decision AS decision_status FROM attendance_decisions d JOIN student_enrollments e ON e.id=d.enrollment_id JOIN enrollment_sections es ON es.id=d.assignment_id AND es.enrollment_id=d.enrollment_id JOIN sections sec ON sec.id=es.section_id LEFT JOIN attendance a ON a.enrollment_id=d.enrollment_id AND a.assignment_id=d.assignment_id AND a.date=d.learning_date WHERE d.id IN('.$ids.'))';
    $report['attendance']=$db->query('SELECT * FROM '.$source.' a ORDER BY a.date,a.student_id')->fetchAll(PDO::FETCH_ASSOC);
    $report['attendance_source']=$source; return $report;
}
