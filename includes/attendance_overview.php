<?php
// Presentation only: the authenticated sheet controller supplies the authorized roster.
$dayCounts = $dayCounts ?? ['total'=>0,'full_day'=>0,'partial'=>0,'absent'=>0,'late'=>0];
$gradeLevel = $gradeLevel ?? '';
$typeFilter = $typeFilter ?? '';
$typeOptions = $typeOptions ?? [];
$grades = $grades ?? [];
$allSections = $allSections ?? [];
$dailyQuery = http_build_query(['date'=>$date,'grade'=>$gradeLevel,'section'=>$sectionId,'school_year_id'=>$yearId]);
$sf2Query = http_build_query(['section'=>$sectionId,'month'=>(int)substr($date,5,2),'year'=>(int)substr($date,0,4),'school_year_id'=>$yearId]);
?>
<div class="attendance-page">
<div class="card attendance-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <h1 class="mb-0"><i class="bi bi-calendar3 me-2 text-primary" aria-hidden="true"></i>Attendance</h1>
            <span class="month-badge"><i class="bi bi-calendar-check" aria-hidden="true"></i><?= sanitize(date('F j, Y', strtotime($date))) ?></span>
        </div>
        <div class="attendance-actions d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="manual.php?<?= sanitize(http_build_query($base)) ?>"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Manual Entry</a>
            <a class="btn btn-sm btn-primary" href="scanner.php"><i class="bi bi-qr-code-scan me-1" aria-hidden="true"></i>Scanner</a>
        </div>
        <form method="get" class="attendance-filters d-flex flex-wrap align-items-end gap-2">
            <input type="hidden" name="school_year_id" value="<?= $yearId ?>">
            <div><label for="attendanceDate">Date</label><input id="attendanceDate" type="date" class="form-control form-control-sm" name="date" value="<?= sanitize($date) ?>" required></div>
            <div><label for="attendanceGrade">Grade</label><select id="attendanceGrade" class="form-select form-select-sm" name="grade"><option value="">All Grades</option><?php foreach ($grades as $grade): ?><option value="<?= sanitize($grade) ?>" <?= $gradeLevel===$grade?'selected':'' ?>><?= sanitize($grade) ?></option><?php endforeach; ?></select></div>
            <div><label for="attendanceSection">Section</label><select id="attendanceSection" class="form-select form-select-sm" name="section"><option value="0" <?= !$sectionId?'selected':'' ?>>All Sections</option><?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$sectionId?'selected':'' ?>><?= sanitize($s['grade_level'].' — '.$s['section_name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="attendanceType">Type</label><select id="attendanceType" class="form-select form-select-sm" name="type"><option value="">All Types</option><?php foreach ($typeOptions as $key=>$label): ?><option value="<?= sanitize($key) ?>" <?= $typeFilter===$key?'selected':'' ?>><?= sanitize($label) ?></option><?php endforeach; ?></select></div>
            <div class="filter-action d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button><a class="btn btn-sm btn-outline-secondary" href="attendance.php?<?= sanitize(http_build_query(['school_year_id'=>$yearId])) ?>">Reset</a></div>
        </form>

    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
        <div class="row g-2 mb-4">
            <?php foreach ([['Total',$dayCounts['total'],'secondary'],['Full Day',$dayCounts['full_day'],'success'],['Partial',$dayCounts['partial'],'warning'],['Absent',$dayCounts['absent'],'danger'],['Late',$dayCounts['late'],'warning']] as [$label,$value,$color]): ?>
            <div class="col-6 col-md"><div class="metric-tile p-3 text-center"><div class="metric-label"><?= $label ?></div><div class="metric-value text-<?= $color ?>"><?= $error ? '—' : number_format($value) ?></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="section-divider"><i class="bi bi-calendar-check" aria-hidden="true"></i>Daily overview</div>
        <?php if ($rows && $sectionId): ?>
        <div class="alert alert-info d-flex align-items-start gap-2 mb-2"><i class="bi bi-info-circle mt-1" aria-hidden="true"></i><div><strong>Delivery: <?= sanitize(deliveryLabels()[$rows[0]['delivery']['mode']]??'No class') ?></strong><?php if ($rows[0]['delivery']['deadline_at']): ?><span class="d-block small">Deadline: <?= sanitize($rows[0]['delivery']['deadline_at']) ?> (Manila).</span><?php endif; ?><span class="d-block small">Pending and review-needed participation do not count as absence.</span></div></div>
        <?php endif; ?>
        <p class="small text-muted mb-0">Totals cover the eligible roster for the selected date, grade and section, before the Type filter. Full Day includes late learners; Late counts each learner once. Pending, unrecorded, future and no-class records are not absences. Original scan evidence is preserved.</p>
    </div>
</div>
<div class="card attendance-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-table me-2 text-primary" aria-hidden="true"></i>Attendance List <span class="badge bg-primary ms-1"><?= count($rows) ?></span></span>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-success" href="../reports/daily.php?<?= sanitize($dailyQuery) ?>"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Export</a>
        <?php if ($sectionId): ?><a class="btn btn-sm btn-outline-secondary" href="../reports/sf2.php?<?= sanitize($sf2Query) ?>"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Monthly SF2</a><?php endif; ?></div>
    </div>
    <div class="table-responsive"><table data-list-search="Search student name, LRN or attendance status..." data-list-title="Attendance List" class="table table-hover align-middle mb-0 attendance-table">
        <thead><tr><th scope="col">Learner</th><th scope="col">Grade / Section</th><th scope="col">Time-In</th><th scope="col">Time-Out</th><th scope="col">Type</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $status=$row['classification']['status']; $decision=$row['decision']; ?>
        <tr>
            <td><span class="fw-semibold"><?= sanitize($row['last_name'].', '.$row['first_name']) ?></span><small class="d-block text-muted"><?= sanitize($row['lrn']) ?></small></td>
            <td><span class="fw-semibold"><?= sanitize($row['grade_level']) ?></span><small class="d-block text-muted"><?= sanitize($row['section_name']) ?></small></td>
            <?php foreach ([['am_in'=>'AM','pm_in'=>'PM'],['am_out'=>'AM','pm_out'=>'PM']] as $events): ?>
            <td class="text-nowrap"><?php $hasTime=false; foreach ($events as $key=>$label): if (!empty($row['scan'][$key])): $hasTime=true; ?><div><span class="text-muted small"><?= $label ?></span> <?= sanitize(date('h:i A',strtotime($row['scan'][$key]))) ?></div><?php endif; endforeach; ?><?php if (!$hasTime): ?><span class="text-muted">—</span><?php endif; ?></td>
            <?php endforeach; ?>
            <td><span class="attendance-status status-<?= sanitize($status) ?>"><?= sanitize($row['overview_type']==='full_day'?'Full Day':attendanceDecisionLabels()[$status]) ?></span><?php if ($row['overview_late']): ?><small class="d-block text-warning">Late</small><?php endif; ?></td>
            <td><a class="btn btn-sm btn-outline-primary mb-1" href="manual.php?<?= sanitize(http_build_query(array_replace($base,['section'=>$row['section_id']]))) ?>">Manual Entry</a>
            <?php if ($decision): ?><small class="d-block text-muted">Revision <?= (int)$decision['revision'] ?> · Actor #<?= (int)$decision['recorded_by'] ?> · <?= sanitize($decision['confirmed_at']) ?></small><?php if (!empty($decision['reason_ciphertext'])): ?><details><summary>Reason</summary><?= sanitize(decryptPII($decision['reason_ciphertext'],'attendance-decision:'.$row['enrollment_id'].':'.$date)) ?></details><?php endif; ?><?php endif; ?>
            <?php if ($row['absence_notification_status']): ?><small class="d-block">Parent absence SMS: <?= sanitize($row['absence_notification_status']) ?><?= in_array($row['absence_notification_status'],['failed','unknown','sending','pending'],true)?' — follow up if unconfirmed':'' ?></small><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-people d-block fs-3 mb-2" aria-hidden="true"></i><?= $error?'Attendance is unavailable. Please try again.':'No attendance records found for these filters.' ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
</div>

<script id="attendanceSectionData" type="application/json"><?= json_encode(array_map(static fn($s)=>['id'=>(int)$s['id'],'grade'=>$s['grade_level'],'name'=>$s['section_name']],$allSections),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>assets/js/attendance_filters.js" defer></script>
