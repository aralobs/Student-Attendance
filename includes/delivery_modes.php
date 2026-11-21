<?php
declare(strict_types=1);
require_once __DIR__.'/enrollment_operations.php';

function deliverySchemaSQL(): string {
    return "CREATE TABLE IF NOT EXISTS delivery_modes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_year_id INT NOT NULL,
        scope_section_id INT NOT NULL DEFAULT 0, learning_date DATE NOT NULL,
        mode ENUM('face_to_face','online','modular') NOT NULL, deadline_at DATETIME NULL,
        recorded_by INT NOT NULL, revision INT NOT NULL DEFAULT 1,
        UNIQUE KEY delivery_scope(school_year_id,scope_section_id,learning_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

/** Migration only; never called by a page. A section scope of zero means school-wide. */
function ensureDeliverySchema(PDO $db): void { $db->exec(deliverySchemaSQL()); }

function panelTableAvailable(PDO $db, string $table): bool {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]); return (bool)$q->fetchColumn();
}

function requirePanelFeatures(PDO $db): void {
    if (!enrollmentModelEnabled($db) || !panelTableAvailable($db,'delivery_modes')) {
        throw new DomainException('Annual enrollment and the panel revisions migration must be enabled first.');
    }
}

function deliveryLabels(): array { return ['face_to_face'=>'Face-to-face','online'=>'Online','modular'=>'Modular']; }

/** Explicit schedules; optional inactive rows explain saved schedules superseded by closures. */
function getDeliveryCalendarRange(PDO $db, array $actor, string $from, string $through, bool $includeInactive = false): array {
    validatedSchoolYearDate($from); validatedSchoolYearDate($through);
    if (!enrollmentModelEnabled($db) || !panelTableAvailable($db,'delivery_modes')) return [];
    if (!in_array($actor['role']??'', ['admin','teacher'], true) || empty($actor['id'])) return [];
    [$scope,$bindings]=enrollmentSectionScope('sec',$actor,true);
    $eligibilitySQL=$includeInactive?'':"AND (c.type IS NULL OR c.type NOT IN ('holiday','no_class'))
        AND (DAYOFWEEK(d.learning_date) BETWEEN 2 AND 6 OR c.type='school_day')";
    $q=$db->prepare("SELECT d.id,d.school_year_id,d.revision,d.learning_date,d.mode,d.deadline_at,d.scope_section_id,sec.section_name,sec.is_active AS section_active,y.state AS year_state,c.type AS calendar_type
        FROM delivery_modes d
        JOIN school_years y ON y.id=d.school_year_id AND d.learning_date BETWEEN y.starts_on AND y.ends_on
        LEFT JOIN sections sec ON sec.id=d.scope_section_id AND sec.school_year_id=d.school_year_id
        LEFT JOIN school_calendar c ON c.date=d.learning_date
        WHERE d.learning_date BETWEEN ? AND ?
        AND (d.scope_section_id=0 OR (sec.id IS NOT NULL AND ($scope)))
        $eligibilitySQL
        ORDER BY d.learning_date,d.scope_section_id");
    $q->execute(array_merge([$from,$through],$bindings));
    $days=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['eligible']=!in_array($row['calendar_type'],['holiday','no_class'],true)
            && ((int)(new DateTimeImmutable($row['learning_date']))->format('N')<=5 || $row['calendar_type']==='school_day');
        $days[$row['learning_date']][]=$row;
    }
    return $days;
}

function deliveryCalendarEligible(PDO $db, string $date): bool {
    $q=$db->prepare('SELECT type FROM school_calendar WHERE date=?'.($db->inTransaction()?' LOCK IN SHARE MODE':'')); $q->execute([$date]); $type=$q->fetchColumn();
    return !in_array($type,['holiday','no_class'],true) && ((int)(new DateTimeImmutable($date))->format('N')<=5 || $type==='school_day');
}

function resolveDeliveryDay(PDO $db, int $yearId, int $sectionId, string $date): array {
    validatedSchoolYearDate($date);
    // Writers hold structural locks before resolving. Current reads avoid an older
    // repeatable-read snapshot restoring a mode/closure changed while they waited.
    $currentRead=$db->inTransaction()?' LOCK IN SHARE MODE':'';
    $q=$db->prepare('SELECT y.starts_on,y.ends_on FROM school_years y JOIN sections s ON s.school_year_id=y.id WHERE y.id=? AND s.id=?'.$currentRead);
    $q->execute([$yearId,$sectionId]); $year=$q->fetch(PDO::FETCH_ASSOC);
    if (!$year || $date<$year['starts_on'] || $date>$year['ends_on']) throw new DomainException('Date/section does not belong to the school year.');
    $result=['eligible'=>deliveryCalendarEligible($db,$date),'mode'=>'face_to_face','source'=>'default','deadline_at'=>null];
    if (panelTableAvailable($db,'delivery_modes')) {
        $q=$db->prepare('SELECT * FROM delivery_modes WHERE school_year_id=? AND learning_date=? AND scope_section_id IN(0,?) ORDER BY scope_section_id DESC LIMIT 1'.$currentRead);
        $q->execute([$yearId,$date,$sectionId]); $mode=$q->fetch(PDO::FETCH_ASSOC);
        if ($mode) $result=array_merge($result,['mode'=>$mode['mode'],'source'=>(int)$mode['scope_section_id']===0?'school':'section','deadline_at'=>$mode['deadline_at']]);
    }
    if (!$result['eligible']) $result['mode']=null;
    return $result;
}

/** Preview token binds the command, actor and current rows; the server rechecks everything under locks. */
function saveDeliveryModes(PDO $db, array $actor, array $command): array {
    requirePanelFeatures($db);
    if ($db->inTransaction()) throw new LogicException('Delivery save owns its transaction.');
    $from=validatedSchoolYearDate($command['from']??null); $to=validatedSchoolYearDate($command['to']??null);
    if ($to<$from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366) throw new InvalidArgumentException('Select a date range of at most one year.');
    $yearId=(int)($command['year_id']??0); $sectionId=(int)($command['section_id']??0);
    $mode=$command['mode']??'';
    if (!isset(deliveryLabels()[$mode])) throw new InvalidArgumentException('Select a delivery mode.');
    $deadline=$command['deadline_at']??null;
    if ($mode==='modular') {
        $parsed=is_string($deadline)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$deadline,new DateTimeZone('Asia/Manila')):false;
        if (!$parsed || $parsed->format('Y-m-d H:i:s')!==$deadline || $deadline<$to.' 00:00:00') throw new InvalidArgumentException('A modular deadline on or after the last learning date is required.');
    } else $deadline=null;
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM school_years WHERE id=? FOR UPDATE'); $q->execute([$yearId]); $year=$q->fetch(PDO::FETCH_ASSOC);
        $q=$db->prepare('SELECT id,role FROM users WHERE id=? AND is_active=1 LOCK IN SHARE MODE'); $q->execute([(int)($actor['id']??0)]); $real=$q->fetch(PDO::FETCH_ASSOC);
        if (!$real || $real['role']!==($actor['role']??'') || !in_array($real['role'],['admin','teacher'],true)) throw new DomainException('Delivery scheduling access denied.');
        if (!$year || $year['state']!=='active' || $from<$year['starts_on'] || $to>$year['ends_on']) throw new DomainException('Only dates in the active school year may be scheduled.');
        if (!$sectionId && $real['role']!=='admin') throw new DomainException('Only administrators set school-wide modes.');
        [$scope,$params]=enrollmentSectionScope('sec',$actor);
        $q=$db->prepare("SELECT sec.id FROM sections sec WHERE sec.school_year_id=? AND sec.is_active=1 AND ($scope)".($sectionId?' AND sec.id=?':'').' ORDER BY sec.id FOR UPDATE');
        $q->execute(array_merge([$yearId],$params,$sectionId?[$sectionId]:[])); $sectionIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (!$sectionIds) throw new DomainException('Section access denied.');
        // A school-wide change affects every section, including those hidden from a teacher.
        $dates=[]; $skipped=[];
        for ($d=new DateTimeImmutable($from); $d->format('Y-m-d')<=$to; $d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d');
            if (deliveryCalendarEligible($db,$date)) $dates[]=$date; else $skipped[]=$date;
        }
        if (!$dates) throw new DomainException('The range contains no eligible school days.');
        $q=$db->prepare('SELECT * FROM delivery_modes WHERE school_year_id=? AND scope_section_id=? AND learning_date BETWEEN ? AND ? ORDER BY learning_date FOR UPDATE');
        $q->execute([$yearId,$sectionId,$from,$to]); $before=$q->fetchAll(PDO::FETCH_ASSOC);
        $token=hash('sha256',json_encode([$yearId,$sectionId,$from,$to,$mode,$deadline,$real,$before,$dates],JSON_THROW_ON_ERROR));
        if (!hash_equals($token,(string)($command['confirm_token']??''))) {
            $db->rollBack(); return ['saved'=>false,'token'=>$token,'dates'=>$dates,'skipped'=>$skipped,'existing'=>$before];
        }
        $ph=implode(',',array_fill(0,count($sectionIds),'?'));
        $q=$db->prepare("SELECT COUNT(*) FROM attendance a JOIN enrollment_sections es ON es.id=a.assignment_id WHERE es.section_id IN($ph) AND a.date BETWEEN ? AND ?");
        $q->execute(array_merge($sectionIds,[$from,$to]));
        if ($q->fetchColumn()) throw new DomainException('Attendance already exists in this range. Use a reviewed historical correction.');
        if (panelTableAvailable($db,'attendance_decisions')) {
            $q=$db->prepare("SELECT COUNT(*) FROM attendance_decisions d JOIN enrollment_sections es ON es.id=d.assignment_id WHERE es.section_id IN($ph) AND learning_date BETWEEN ? AND ?");
            $q->execute(array_merge($sectionIds,[$from,$to]));
            if ($q->fetchColumn()) throw new DomainException('Attendance decisions already exist in this range.');
        }
        $q=$db->prepare('INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,deadline_at,recorded_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),deadline_at=VALUES(deadline_at),recorded_by=VALUES(recorded_by),revision=revision+1');
        foreach ($dates as $date) $q->execute([$yearId,$sectionId,$date,$mode,$deadline,(int)$real['id']]);
        securityAuditContext((int)$real['id']);
        securityAuditChange($db,'delivery.schedule','school_year',$yearId,$before,['section_id'=>$sectionId,'dates'=>$dates,'mode'=>$mode,'deadline_at'=>$deadline]);
        $db->commit(); return ['saved'=>true,'dates'=>$dates,'skipped'=>$skipped];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Remove one dated scope only after preview; inherit other schedules without rewriting history. */
function deleteDeliveryMode(PDO $db, array $actor, array $command): array {
    requirePanelFeatures($db);
    if ($db->inTransaction()) throw new LogicException('Delivery deletion owns its transaction.');
    $id=filter_var($command['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id===false) throw new InvalidArgumentException('Choose a valid delivery entry.');
    if (isset($command['confirm_token']) && !is_string($command['confirm_token'])) throw new InvalidArgumentException('Reload the delivery entry and try again.');
    $q=$db->prepare('SELECT school_year_id FROM delivery_modes WHERE id=?'); $q->execute([$id]); $yearId=$q->fetchColumn();
    if (!$yearId) throw new DomainException('Delivery entry no longer exists. Reload the calendar.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM school_years WHERE id=? FOR UPDATE'); $q->execute([$yearId]); $year=$q->fetch(PDO::FETCH_ASSOC);
        $q=$db->prepare('SELECT id,role FROM users WHERE id=? AND is_active=1 LOCK IN SHARE MODE'); $q->execute([(int)($actor['id']??0)]); $real=$q->fetch(PDO::FETCH_ASSOC);
        if (!$real || $real['role']!==($actor['role']??'') || !in_array($real['role'],['admin','teacher'],true)) throw new DomainException('Delivery scheduling access denied.');
        if (!$year || $year['state']!=='active') throw new DomainException('Only delivery entries in the active school year may be deleted.');
        $q=$db->prepare('SELECT * FROM delivery_modes WHERE id=? AND school_year_id=? FOR UPDATE'); $q->execute([$id,$yearId]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Delivery entry changed or no longer exists. Reload the calendar.');
        $date=$row['learning_date']; $sectionId=(int)$row['scope_section_id'];
        if ($date<$year['starts_on'] || $date>$year['ends_on']) throw new DomainException('Delivery date does not belong to the active school year.');
        if (!$sectionId && $real['role']!=='admin') throw new DomainException('Only administrators may delete whole-school delivery entries.');
        [$scope,$bindings]=enrollmentSectionScope('sec',$actor);
        // Include inactive sections for administrators so archived attendance is protected too.
        $q=$db->prepare("SELECT sec.id,sec.section_name FROM sections sec WHERE sec.school_year_id=? AND ($scope)".($sectionId?' AND sec.id=?':'').' ORDER BY sec.id FOR UPDATE');
        $q->execute(array_merge([(int)$yearId],$bindings,$sectionId?[$sectionId]:[])); $sections=$q->fetchAll(PDO::FETCH_ASSOC);
        if ($sectionId && !$sections) throw new DomainException('Section access denied.');
        // Unresolved legacy ownership cannot be assumed to belong to a different section.
        $q=$db->prepare('SELECT COUNT(*) FROM attendance a WHERE a.date=?'.($sectionId?' AND (a.assignment_id IS NULL OR EXISTS(SELECT 1 FROM enrollment_sections es WHERE es.id=a.assignment_id AND es.section_id=?))':''));
        $q->execute($sectionId?[$date,$sectionId]:[$date]);
        if ($q->fetchColumn()) throw new DomainException('Attendance already exists on this date. Use a reviewed historical correction.');
        if (panelTableAvailable($db,'attendance_decisions')) {
            $q=$db->prepare('SELECT COUNT(*) FROM attendance_decisions d WHERE d.learning_date=?'.($sectionId?' AND EXISTS(SELECT 1 FROM enrollment_sections es WHERE es.id=d.assignment_id AND es.section_id=?)':''));
            $q->execute($sectionId?[$date,$sectionId]:[$date]);
            if ($q->fetchColumn()) throw new DomainException('Attendance decisions already exist on this date. Use a reviewed historical correction.');
        }
        $q=$db->prepare('SELECT * FROM delivery_modes WHERE school_year_id=? AND learning_date=?'.($sectionId?' AND scope_section_id IN(0,?)':'').' ORDER BY scope_section_id FOR UPDATE');
        $q->execute($sectionId?[(int)$yearId,$date,$sectionId]:[(int)$yearId,$date]); $context=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$db->prepare('SELECT type FROM school_calendar WHERE date=? LOCK IN SHARE MODE'); $q->execute([$date]); $calendarType=$q->fetchColumn();
        $eligible=deliveryCalendarEligible($db,$date);
        if (!$eligible) $fallback='This date remains No class. The school calendar entry is unchanged.';
        elseif (!$sectionId) $fallback='Sections without overrides return to Face-to-face. Existing section overrides remain in effect.';
        else {
            $school=null;
            foreach ($context as $candidate) if ((int)$candidate['scope_section_id']===0) $school=$candidate;
            $fallback=$school ? 'This section returns to the whole-school '.deliveryLabels()[$school['mode']].' schedule'
                .($school['deadline_at']?' (deadline '.$school['deadline_at'].' Manila time)':'').'.'
                : 'This section returns to Face-to-face (default).';
        }
        $token=hash('sha256',json_encode([$year,$real,$row,$context,$calendarType],JSON_THROW_ON_ERROR));
        if (!hash_equals($token,$command['confirm_token']??'')) {
            $db->rollBack();
            return ['deleted'=>false,'token'=>$token,'label'=>deliveryLabels()[$row['mode']].' — '.($sectionId?$sections[0]['section_name']:'Whole school').' — '.$date
                .($row['deadline_at']?' — Deadline '.$row['deadline_at'].' (Manila time)':''),'fallback_message'=>$fallback];
        }
        $db->prepare('DELETE FROM delivery_modes WHERE id=?')->execute([$id]);
        securityAuditContext((int)$real['id']);
        securityAuditChange($db,'delivery.delete','school_year',(int)$yearId,$row,['deleted_id'=>$id,'date'=>$date,'section_id'=>$sectionId,'fallback'=>$fallback]);
        $db->commit(); return ['deleted'=>true];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
