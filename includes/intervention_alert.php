<?php
// Read-only staff warning, rendered after authentication. No evaluation or sending on GET.
if (enrollmentModelEnabled($db)) {
    require_once __DIR__.'/delivery_modes.php';
    if (panelTableAvailable($db,'absence_episodes')) {
        [$scope,$bindings]=enrollmentSectionScope('sec',currentUser(),true);
        $q=$db->prepare("SELECT COUNT(DISTINCT ep.id) FROM absence_episodes ep JOIN student_enrollments e ON e.id=ep.enrollment_id JOIN school_years y ON y.id=e.school_year_id JOIN enrollment_sections es ON es.enrollment_id=ep.enrollment_id AND ep.ends_on BETWEEN es.starts_on AND es.ends_on JOIN sections sec ON sec.id=es.section_id WHERE y.state='active' AND e.state='active' AND ep.active=1 AND ep.is_current=1 AND ep.state IN('warning','intervention_required') AND ($scope)");$q->execute($bindings);$count=(int)$q->fetchColumn();
        if($count) echo '<div class="alert alert-warning" role="alert">'.(int)$count.' learner(s) need attendance follow-up in the current school year under the school 3/5 intervention policy. <a href="'.htmlspecialchars(BASE_URL.'attendance/interventions.php?school_year_id='.(int)(getActiveSchoolYear($db)['id']??0),ENT_QUOTES,'UTF-8').'">Review attendance warnings</a></div>';
    }
}
