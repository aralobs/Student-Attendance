<?php
declare(strict_types=1);
require_once __DIR__.'/attendance_decisions.php';

/** Caller owns the calendar transaction. Year locks serialize eligibility changes with attendance writers. */
function assertCalendarDateMutable(PDO $db,string $date,bool $preserveExisting=false):bool {
    validatedSchoolYearDate($date);
    if(!$db->inTransaction())throw new LogicException('Calendar protection requires a transaction.');
    if(!panelTableAvailable($db,'school_year_control'))return true;
    $db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn();
    // Follow the lifecycle lock order; avoid a stale snapshot before year locks.
    $q=$db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LOCK IN SHARE MODE');$q->execute(['enrollment_model_version']);
    if($q->fetchColumn()!=='1')return true;
    $q=$db->prepare('SELECT id,state FROM school_years WHERE ? BETWEEN starts_on AND ends_on ORDER BY id FOR UPDATE');$q->execute([$date]);
    $years=$q->fetchAll(PDO::FETCH_ASSOC);
    if($preserveExisting){$q=$db->prepare('SELECT id FROM school_calendar WHERE date=?');$q->execute([$date]);if($q->fetchColumn())return false;}
    foreach($years as $year)if($year['state']==='closed')throw new DomainException('Closed-year calendar dates require reviewed correction.');
    $q=$db->prepare('SELECT COUNT(*) FROM attendance WHERE date=?');$q->execute([$date]);
    if($q->fetchColumn())throw new DomainException('Attendance already exists on this date. Use a reviewed calendar correction.');
    if(panelTableAvailable($db,'attendance_decisions')){
        $q=$db->prepare('SELECT COUNT(*) FROM attendance_decisions WHERE learning_date=?');$q->execute([$date]);
        if($q->fetchColumn())throw new DomainException('Attendance decisions already exist on this date. Use a reviewed calendar correction.');
    }
    return true;
}
function saveProtectedCalendarEntry(PDO $db,array $actor,array $entry):void {
    if(($actor['role']??'')!=='admin')throw new DomainException('Administrator access required.');
    $date=validatedSchoolYearDate($entry['date']??null);$delete=!empty($entry['delete']);
    if(!$delete && (!is_string($entry['title']??null)||trim($entry['title'])===''||mb_strlen($entry['title'])>100||!in_array($entry['type']??'',['holiday','no_class','school_day','special_event'],true)))throw new InvalidArgumentException('Enter a valid calendar title and type.');
    if($db->inTransaction())throw new LogicException('Calendar save owns its transaction.');$db->beginTransaction();
    try{
        assertCalendarDateMutable($db,$date);
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1 LOCK IN SHARE MODE");$q->execute([(int)$actor['id']]);if(!$q->fetchColumn())throw new DomainException('Administrator access required.');
        $q=$db->prepare('SELECT * FROM school_calendar WHERE date=? FOR UPDATE');$q->execute([$date]);$before=$q->fetch(PDO::FETCH_ASSOC);
        if($delete)$db->prepare('DELETE FROM school_calendar WHERE date=?')->execute([$date]);
        else $db->prepare('INSERT INTO school_calendar(date,title,type,description,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),type=VALUES(type),description=VALUES(description)')->execute([$date,$entry['title'],$entry['type'],$entry['description']??'',(int)$actor['id']]);
        securityAuditContext((int)$actor['id']);securityAuditChange($db,'calendar.change','route',0,$before?:null,$delete?['date'=>$date,'deleted'=>true]:$entry);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function protectedCalendarImportGuard(PDO $db,string $date):bool {
    return assertCalendarDateMutable($db,$date,true);
}
