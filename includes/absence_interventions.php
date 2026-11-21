<?php
declare(strict_types=1);
require_once __DIR__.'/attendance_decisions.php';

function interventionSchemaSQL(): string {
    return "CREATE TABLE IF NOT EXISTS absence_episodes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,enrollment_id INT NOT NULL,starts_on DATE NOT NULL,ends_on DATE NOT NULL,
        absence_count INT NOT NULL,state VARCHAR(32) NOT NULL,threshold_date DATE NULL,blocked_on DATE NULL,
        notification_owner VARCHAR(64) NOT NULL,active TINYINT NOT NULL DEFAULT 1, is_current TINYINT NOT NULL DEFAULT 1,
        UNIQUE KEY episode_start(enrollment_id,starts_on),KEY episode_enrollment(enrollment_id,active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS absence_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,notification_owner VARCHAR(64) NOT NULL,
        episode_id BIGINT UNSIGNED NOT NULL,enrollment_id INT NOT NULL,threshold_date DATE NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',superseded TINYINT NOT NULL DEFAULT 0,
        attempted_at DATETIME NULL, completed_at DATETIME NULL, UNIQUE KEY intervention_owner(notification_owner)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}
function ensureInterventionSchema(PDO $db):void { $db->exec(interventionSchemaSQL()); }

function buildAbsenceEpisodes(array $days):array {
    usort($days,fn($a,$b)=>$a['date']<=>$b['date']); $episodes=[]; $current=null; $blocked=null;
    foreach($days as $day) {
        $status=$day['status'];
        if(in_array($status,['future','not_eligible'],true)) continue;
        if(in_array($status,['present','late','partial'],true)) {
            if($current) { $current['ends_on']=$day['date']; $current['is_current']=false; $episodes[]=$current; }
            $current=null; $blocked=null; continue;
        }
        if(in_array($status,['pending','review_needed','not_recorded','remarked'],true)) {
            $blocked??=$day['date']; if($current) $current['blocked_on']=$blocked; continue;
        }
        if($status!=='absent_unexcused') continue;
        $current??=['count'=>0,'starts_on'=>$day['date'],'ends_on'=>$day['date'],'threshold_date'=>null,'blocked_on'=>$blocked,'is_current'=>true];
        $current['ends_on']=$day['date'];
        if(!$blocked) { $current['count']++; if($current['count']===5) $current['threshold_date']=$day['date']; }
    }
    if($current) $episodes[]=$current;
    foreach($episodes as &$e) $e['state']=$e['blocked_on']?'needs_review':($e['count']>=5?'intervention_required':($e['count']>=3?'warning':'normal'));
    return $episodes;
}
function evaluateAbsenceStreak(array $orderedDays):array {
    $episodes=buildAbsenceEpisodes($orderedDays); $last=$episodes?end($episodes):null;
    return $last && $last['is_current']?$last:['count'=>0,'state'=>'normal','starts_on'=>null,'threshold_date'=>null,'blocked_on'=>null];
}

/** Rebuild from annual evidence. The enrollment lock serializes writers and dispatch claims. */
function recomputeEnrollmentInterventions(PDO $db, int $enrollmentId, string $fromDate, ?DateTimeImmutable $now=null, ?callable $reserve=null):array {
    $now??=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')); validatedSchoolYearDate($fromDate);
    if($db->inTransaction())throw new LogicException('Intervention evaluation owns its transaction.');
    // Candidate reads are outside the transaction: the later snapshot must start after structural locks.
    $q=$db->prepare('SELECT student_id,school_year_id FROM student_enrollments WHERE id=?');$q->execute([$enrollmentId]);$identity=$q->fetch(PDO::FETCH_ASSOC);
    if(!$identity)throw new DomainException('Enrollment not found.');
    $q=$db->prepare('SELECT DISTINCT section_id FROM enrollment_sections WHERE enrollment_id=? ORDER BY section_id');$q->execute([$enrollmentId]);$sectionIds=$q->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM school_years WHERE id=? LOCK IN SHARE MODE');$q->execute([$identity['school_year_id']]);$year=$q->fetch(PDO::FETCH_ASSOC);
        $sections=[];foreach($sectionIds as $id){$q=$db->prepare('SELECT * FROM sections WHERE id=? LOCK IN SHARE MODE');$q->execute([$id]);$sections[(int)$id]=$q->fetch(PDO::FETCH_ASSOC);}
        $q=$db->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');$q->execute([$identity['student_id']]);$q->fetchColumn();
        $q=$db->prepare('SELECT * FROM student_enrollments WHERE id=? FOR UPDATE');$q->execute([$enrollmentId]);$enrollment=$q->fetch(PDO::FETCH_ASSOC);
        if(!$year||!$enrollment||$enrollment['school_year_id']!=$identity['school_year_id']||$enrollment['student_id']!=$identity['student_id'])throw new DomainException('Enrollment changed; retry evaluation.');
        $q=$db->prepare('SELECT * FROM enrollment_sections WHERE enrollment_id=? ORDER BY starts_on,id FOR UPDATE');$q->execute([$enrollmentId]);$assignments=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($assignments as $a)if(!isset($sections[(int)$a['section_id']]))throw new DomainException('Section ownership changed; retry evaluation.');
        $days=loadEnrollmentAbsenceDays($db,$enrollment,$year,$assignments,$sections,$now);$episodes=buildAbsenceEpisodes($days);
        $q=$db->prepare('SELECT * FROM absence_episodes WHERE enrollment_id=? ORDER BY id FOR UPDATE'); $q->execute([$enrollmentId]); $old=$q->fetchAll(PDO::FETCH_ASSOC);
        $db->prepare('UPDATE absence_episodes SET active=0 WHERE enrollment_id=?')->execute([$enrollmentId]);
        foreach($episodes as $e) {
            $owners=[]; foreach($old as $prior) if($prior['starts_on']<=$e['ends_on'] && $prior['ends_on']>=$e['starts_on']) $owners[]=$prior['notification_owner'];
            $owner=$owners[0]??bin2hex(random_bytes(16));
            // Any already claimed notification in overlapping lineage suppresses a second send.
            foreach($owners as $candidate) {
                $q=$db->prepare("SELECT COUNT(*) FROM absence_notifications WHERE notification_owner=? AND status IN('sending','sent','unknown','failed')"); $q->execute([$candidate]);
                if($q->fetchColumn()) { $owner=$candidate; break; }
            }
            $q=$db->prepare('INSERT INTO absence_episodes(enrollment_id,starts_on,ends_on,absence_count,state,threshold_date,blocked_on,notification_owner,is_current) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ends_on=VALUES(ends_on),absence_count=VALUES(absence_count),state=VALUES(state),threshold_date=VALUES(threshold_date),blocked_on=VALUES(blocked_on),notification_owner=VALUES(notification_owner),active=1,is_current=VALUES(is_current)');
            $q->execute([$enrollmentId,$e['starts_on'],$e['ends_on'],$e['count'],$e['state'],$e['threshold_date'],$e['blocked_on'],$owner,(int)$e['is_current']]);
        }
        $q=$db->prepare("UPDATE absence_notifications n LEFT JOIN absence_episodes e ON e.id=n.episode_id SET n.superseded=1,n.status=IF(n.status='pending','cancelled',n.status) WHERE n.enrollment_id=? AND (e.active=0 OR e.state<>'intervention_required')"); $q->execute([$enrollmentId]);
        $result=evaluateAbsenceStreak($days);
        if($reserve)$result['claim']=$reserve($db,$enrollment);
        $db->prepare('DELETE FROM attendance_recompute_queue WHERE enrollment_id=?')->execute([$enrollmentId]);
        $db->commit(); return $result;
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** Only this enrollment's evidence is loaded: linear annual evaluation rather than a school-wide report per learner. */
function loadEnrollmentAbsenceDays(PDO $db,array $enrollment,array $year,array $assignments,array $sections,DateTimeImmutable $now):array {
    if(!in_array($enrollment['state'],['active','completed'],true))return [];
    $from=max($year['starts_on'],$enrollment['starts_on']);$to=min($year['ends_on'],$enrollment['ends_on'],$now->format('Y-m-d'));if($from>$to)return [];
    $q=$db->prepare('SELECT * FROM attendance WHERE enrollment_id=? AND date BETWEEN ? AND ?');$q->execute([$enrollment['id'],$from,$to]);$scans=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'date');
    $q=$db->prepare('SELECT * FROM attendance_decisions WHERE enrollment_id=? AND learning_date BETWEEN ? AND ?');$q->execute([$enrollment['id'],$from,$to]);$decisions=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'learning_date');
    $q=$db->prepare('SELECT date,type FROM school_calendar WHERE date BETWEEN ? AND ?');$q->execute([$from,$to]);$calendar=array_column($q->fetchAll(PDO::FETCH_ASSOC),'type','date');
    $scopeIds=array_merge([0],array_keys($sections));$scopeMarks=implode(',',array_fill(0,count($scopeIds),'?'));
    $q=$db->prepare("SELECT * FROM delivery_modes WHERE school_year_id=? AND learning_date BETWEEN ? AND ? AND scope_section_id IN($scopeMarks) ORDER BY scope_section_id");$q->execute(array_merge([$year['id'],$from,$to],$scopeIds));$modes=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $mode)$modes[$mode['learning_date']][(int)$mode['scope_section_id']]=$mode;
    $days=[];$assignmentByDate=[];
    foreach($assignments as $a){
        $section=$sections[(int)$a['section_id']];if(!$section||$section['school_year_id']!=$year['id']||$section['grade_level']!==$a['grade_level'])throw new DomainException('Invalid dated section ownership.');
        for($d=new DateTimeImmutable(max($from,$a['starts_on']));$d->format('Y-m-d')<=min($to,$a['ends_on']);$d=$d->modify('+1 day')){
            $date=$d->format('Y-m-d');if(isset($assignmentByDate[$date]))throw new DomainException('Overlapping attendance assignments.');$assignmentByDate[$date]=true;
            $mode=$modes[$date][(int)$a['section_id']]??$modes[$date][0]??['mode'=>'face_to_face','deadline_at'=>null];$type=$calendar[$date]??null;
            $context=['eligible'=>!in_array($type,['holiday','no_class'],true)&&((int)$d->format('N')<=5||$type==='school_day'),'mode'=>$mode['mode'],'deadline_at'=>$mode['deadline_at'],'date'=>$date,'schedule_type'=>$section['schedule_type']];
            $scan=$scans[$date]??null;$decision=$decisions[$date]??null;
            foreach(array_filter([$scan,$decision]) as $record)if((int)$record['assignment_id']!==(int)$a['id'])throw new DomainException('Attendance ownership requires reviewed correction.');
            $days[]=array_merge(classifyAttendanceDay($context,$scan,$decision,$now),['date'=>$date]);
        }
    }
    // Gaps in annual placement must block an otherwise continuous streak.
    for($d=new DateTimeImmutable($from);$d->format('Y-m-d')<=$to;$d=$d->modify('+1 day'))if(!isset($assignmentByDate[$d->format('Y-m-d')])){
        $date=$d->format('Y-m-d');$type=$calendar[$date]??null;
        $eligible=!in_array($type,['holiday','no_class'],true)&&((int)$d->format('N')<=5||$type==='school_day');
        $days[]=['date'=>$date,'status'=>$eligible?'not_recorded':'not_eligible'];
    }
    return $days;
}

/** All provider I/O occurs after reservation commit. Unknown/failed sends are never automatically retried. */
function processAbsenceInterventions(PDO $db, DateTimeImmutable $now, callable $sender):array {
    $enabled=getenv('ATTENDANCE_INTERVENTION_ENABLED_AT'); $result=['sent'=>0,'failed'=>0,'unknown'=>0,'skipped'=>0];
    if(!$enabled || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$enabled)) return $result;
    // A crash may follow provider acceptance. An abandoned in-flight reservation is unconfirmed, never retried.
    $db->prepare("UPDATE absence_notifications SET status='unknown',completed_at=? WHERE status='sending' AND attempted_at<?")->execute([$now->format('Y-m-d H:i:s'),$now->modify('-15 minutes')->format('Y-m-d H:i:s')]);
    $episodes=$db->query("SELECT id,enrollment_id FROM absence_episodes WHERE active=1 AND state='intervention_required'")->fetchAll(PDO::FETCH_ASSOC);
    foreach($episodes as $candidate) {
        $evaluated=recomputeEnrollmentInterventions($db,(int)$candidate['enrollment_id'],$now->format('Y-m-d'),$now,function(PDO $db,array $annual)use($candidate,$enabled,$now):?array{
            $q=$db->prepare("SELECT e.*,s.id AS student_id,s.first_name,s.last_name,s.parent_contact FROM student_enrollments e JOIN students s ON s.id=e.student_id JOIN school_years y ON y.id=e.school_year_id WHERE e.id=? AND e.state='active' AND y.state='active' AND s.is_active=1"); $q->execute([$candidate['enrollment_id']]); $student=$q->fetch(PDO::FETCH_ASSOC);
            $q=$db->prepare("SELECT * FROM absence_episodes WHERE id=? AND active=1 AND state='intervention_required' FOR UPDATE"); $q->execute([$candidate['id']]); $episode=$q->fetch(PDO::FETCH_ASSOC);
            if(!$student || !$episode || $episode['threshold_date']<substr($enabled,0,10) || $episode['threshold_date']!==$now->format('Y-m-d')) return null;
            $q=$db->prepare('SELECT COUNT(*) FROM attendance_decisions WHERE enrollment_id=? AND learning_date BETWEEN ? AND ? AND (revision>1 OR (confirmed_at>=? AND DATE(confirmed_at)>learning_date))');
            $q->execute([$candidate['enrollment_id'],$episode['starts_on'],$episode['threshold_date'],$enabled]);
            if($q->fetchColumn()) return null;
            $q=$db->prepare("INSERT IGNORE INTO absence_notifications(notification_owner,episode_id,enrollment_id,threshold_date,status,attempted_at) VALUES(?,?,?,?,'sending',?)");
            $q->execute([$episode['notification_owner'],$episode['id'],$candidate['enrollment_id'],$episode['threshold_date'],$now->format('Y-m-d H:i:s')]);
            if(!$q->rowCount()) return null;
            return ['id'=>(int)$db->lastInsertId(),'student'=>$student,'episode'=>$episode];
        });
        $claim=$evaluated['claim']??null;
        if(!$claim){$result['skipped']++;continue;}
        $id=$claim['id'];$student=$claim['student'];$episode=$claim['episode'];
            $phone=preg_replace('/[^0-9+]/','',(string)$student['parent_contact']);
            if(!preg_match('/^(?:09\d{9}|\+?639\d{9})$/D',$phone)) $status='failed';
            else {
                try { $status=$sender($phone,$student,$episode); if(!in_array($status,['sent','failed','unknown'],true)) $status='unknown'; }
                catch(Throwable $e) { $status='unknown'; }
            }
            $db->prepare('UPDATE absence_notifications SET status=?,completed_at=? WHERE id=?')->execute([$status,$now->format('Y-m-d H:i:s'),$id]); $result[$status]++;
    }
    return $result;
}
