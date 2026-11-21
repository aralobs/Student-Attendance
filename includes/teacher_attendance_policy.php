<?php
declare(strict_types=1);

/** Manual entry policy only; scanning and scheduled finalization use their own guards. */
function requireTeacherAttendanceDate(PDO $db, array $actor, string $date, ?DateTimeImmutable $now=null): void {
    if (($actor['role']??'')==='admin' && (int)($actor['id']??0)>0) return;
    if (($actor['role']??'')!=='teacher' || (int)($actor['id']??0)<=0) throw new DomainException('Attendance editing access denied.');
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
    if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Invalid attendance date.');
    $q=$db->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('teacher_attendance_edit_days','attendance_locked_through')".($db->inTransaction()?' LOCK IN SHARE MODE':''));
    $settings=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    $days=filter_var($settings['teacher_attendance_edit_days']??7,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>365]]);
    if ($days===false) $days=7;
    $today=$now->setTime(0,0);
    if ($date>$today->format('Y-m-d') || $date<$today->modify('-'.$days.' days')->format('Y-m-d')
        || $date<=($settings['attendance_locked_through']??'')) {
        throw new DomainException('This date is outside the teacher editing window or has been finalized. Submit an attendance change request for administrator review.');
    }
}

/** Legacy manual writes must not trust a roster read before their transaction. */
function lockLegacyTeacherAttendanceRoster(PDO $db, array $actor, int $sectionId, array $studentIds): void {
    if (!$db->inTransaction()) throw new LogicException('Roster validation requires a transaction.');
    if (!in_array($actor['role']??'',['admin','teacher'],true) || (int)($actor['id']??0)<=0) throw new DomainException('Attendance editing access denied.');
    $teacher=($actor['role']==='teacher');
    $q=$db->prepare('SELECT sec.id FROM sections sec WHERE sec.id=? AND sec.is_active=1'.($teacher?' AND sec.adviser_id=?':'').' LOCK IN SHARE MODE');
    $q->execute($teacher?[$sectionId,(int)$actor['id']]:[$sectionId]);
    if (!$q->fetchColumn()) throw new DomainException('Section access has changed. Reload before saving.');
    $ids=array_values(array_unique(array_map('intval',$studentIds)));sort($ids,SORT_NUMERIC);
    if (!$ids || $ids[0]<=0) throw new InvalidArgumentException('Invalid learner roster.');
    $q=$db->prepare('SELECT id FROM students WHERE section_id=? AND is_active=1 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');
    $q->execute(array_merge([$sectionId],$ids));
    $allowed=array_map('intval',array_column($q->fetchAll(PDO::FETCH_ASSOC),'id'));
    if (array_diff($ids,$allowed)) throw new DomainException('Learner placement has changed. Reload before saving.');
}
