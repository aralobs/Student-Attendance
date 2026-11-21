<?php
declare(strict_types=1);
require_once __DIR__.'/delivery_modes.php';

/** Page-level controls; each scan still rechecks its learner's dated section under lock. */
function scannerDeliveryAvailability(PDO $db, array $actor, string $date): array {
    validatedSchoolYearDate($date);
    if (empty($actor['id']) || !in_array($actor['role']??'', ['admin','teacher','scanner_operator'], true)) {
        return ['enabled'=>false,'message'=>'Scanner access is unavailable.'];
    }
    if (!enrollmentModelEnabled($db)) {
        // Preserve the legacy calendar rules until annual enrollment is enabled.
        // Its unspecified dates are face-to-face; explicit closures still block scanning.
        $q=$db->prepare('SELECT type FROM school_calendar WHERE date=?'); $q->execute([$date]);
        if (in_array($q->fetchColumn(),['holiday','no_class'],true)) {
            return ['enabled'=>false,'message'=>'QR scanning is disabled today: holiday or no class.'];
        }
        return ['enabled'=>true,'message'=>'QR attendance is available for face-to-face classes.'];
    }
    if (!deliveryCalendarEligible($db,$date)) {
        return ['enabled'=>false,'message'=>'QR scanning is disabled today: holiday, no class, or a non-school day.'];
    }
    $year=getActiveSchoolYear($db);
    if (!$year || $date<$year['starts_on'] || $date>$year['ends_on']) {
        return ['enabled'=>false,'message'=>'QR scanning is disabled: today is outside the active school year.'];
    }
    $teacher=($actor['role']==='teacher');
    $q=$db->prepare('SELECT id FROM sections WHERE school_year_id=? AND is_active=1'.($teacher?' AND adviser_id=?':''));
    $q->execute($teacher?[(int)$year['id'],(int)$actor['id']]:[(int)$year['id']]);
    $sections=$q->fetchAll(PDO::FETCH_COLUMN);
    $faceToFace=0;
    foreach ($sections as $sectionId) {
        $day=resolveDeliveryDay($db,(int)$year['id'],(int)$sectionId,$date);
        if ($day['eligible'] && $day['mode']==='face_to_face') $faceToFace++;
    }
    if (!$sections) return ['enabled'=>false,'message'=>'QR scanning is disabled: no authorized active sections are available.'];
    if (!$faceToFace) return ['enabled'=>false,'message'=>'QR scanning is disabled: all your available sections are Online or Modular today. Teachers confirm participation through Manual Entry.'];
    return ['enabled'=>true,'message'=>$faceToFace===count($sections)
        ? 'QR attendance is available for face-to-face classes.'
        : 'QR attendance is available only for face-to-face sections. Teachers confirm Online/Modular participation through Manual Entry.'];
}
