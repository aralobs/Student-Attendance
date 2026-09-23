<?php

/**
 * Reports Hub
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Reports';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Reports
        </h1>
        <p class="page-subtitle">Attendance reports and summaries</p>
    </div>
</div>

<div class="row g-4">
    <?php
    $reports = [
        [
            'title' => 'Daily Attendance',
            'desc'  => 'Full attendance list for a specific date with AM/PM events.',
            'icon'  => 'calendar-day',
            'color' => 'primary',
            'link'  => 'daily.php',
        ],
        [
            'title' => 'By Grade Level',
            'desc'  => 'Attendance summary grouped by grade level.',
            'icon'  => 'bar-chart-steps',
            'color' => 'success',
            'link'  => 'by_grade.php',
        ],
        [
            'title' => 'By Section',
            'desc'  => 'Attendance summary for a specific section over a date range.',
            'icon'  => 'people',
            'color' => 'info',
            'link'  => 'by_section.php',
        ],
        [
            'title' => 'Student Attendance',
            'desc'  => 'Individual student attendance history and rate.',
            'icon'  => 'person-lines-fill',
            'color' => 'secondary',
            'link'  => 'by_student.php',
        ],
        [
            'title' => 'Partial Attendance',
            'desc'  => 'Students who attended only AM or PM on selected dates.',
            'icon'  => 'clock-history',
            'color' => 'warning',
            'link'  => 'partial.php',
        ],
        [
            'title' => 'Absence Summary',
            'desc'  => 'Daily and cumulative absence counts, including peak absence dates.',
            'icon'  => 'x-circle',
            'color' => 'danger',
            'link'  => 'absence_summary.php',
        ],
        [
            'title' => 'Date Range Report',
            'desc'  => 'Attendance summary over a custom date range with holiday awareness.',
            'icon'  => 'calendar-range',
            'color' => 'primary',
            'link'  => 'date_range.php',
        ],
        [
            'title' => 'AM / PM Report',
            'desc'  => 'Compare AM vs PM session attendance across sections.',
            'icon'  => 'sun',
            'color' => 'success',
            'link'  => 'am_pm.php',
        ],
        [
            'title' => 'SF2 Daily Attendance',
            'desc'  => 'DepEd School Form 2 Daily Attendance Record per section.',
            'icon'  => 'file-earmark-ruled',
            'color' => 'danger',
            'link'  => 'sf2.php',
        ],
        [
            'title' => 'SF4 Monthly Summary',
            'desc'  => 'DepEd School Form 4 Monthly Attendance Report for the whole school.',
            'icon'  => 'file-earmark-bar-graph',
            'color' => 'warning',
            'link'  => 'sf4.php',
        ],
    ];
    foreach ($reports as $r):
    ?>
        <div class="col-md-6 col-lg-3">
            <a href="<?= $r['link'] ?>" class="text-decoration-none">
                <div class="card h-100 report-card border-<?= $r['color'] ?>">
                    <div class="card-body text-center py-4">
                        <div class="mb-3">
                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center
                                     bg-<?= $r['color'] ?> bg-opacity-10 text-<?= $r['color'] ?>"
                                style="width:56px;height:56px;font-size:1.5rem">
                                <i class="bi bi-<?= $r['icon'] ?>"></i>
                            </span>
                        </div>
                        <h6 class="fw-700 mb-1"><?= $r['title'] ?></h6>
                        <p class="text-muted small mb-0"><?= $r['desc'] ?></p>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<style>
    .report-card {
        border-top-width: 3px !important;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .report-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
    }
</style>

<?php include '../includes/footer.php'; ?>