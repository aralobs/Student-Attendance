<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
withEnrollmentFixture(function (array $f): void {
    $db = $f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $db->exec("UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $copy = __DIR__.'/enrollment_app_'.bin2hex(random_bytes(8));
    createEnrollmentAppFixture($copy);
    try {
        $pages = ['students/students.php', 'reports/daily.php', 'reports/date_range.php',
            'reports/absence_summary.php', 'reports/by_student.php', 'reports/partial.php'];
        foreach ($pages as $page) {
            $filters = ['school_year_id'=>1, 'date'=>'2026-10-21', 'date_from'=>'2026-10-21',
                'date_to'=>'2026-10-21', 'month'=>10, 'year'=>2026];
            foreach (['Grade 3', ''] as $grade) {
                $response = runEnrollmentAppEndpoint($copy, $f, $page, $f['admin'], [], $filters+['grade'=>$grade], 'GET');
                checkEnrollment($response['status'] === 200, $page.' renders');
                $doc = new DOMDocument(); @$doc->loadHTML($response['body']); $x = new DOMXPath($doc);
                $options = $x->query('//select[@name="section"]/option[@value="11"]');
                checkEnrollment($options->length === 1 && str_contains($options->item(0)->textContent, 'Grade 3'), $page.' labels include grade');
                checkEnrollment($x->query('//select[@name="section"]/option[@value="16" and not(@disabled) and not(@hidden)]')->length === ($grade === '' ? 1 : 0), $page.' only offers matching sections');
                checkEnrollment($x->query('//select[@name="section" and @data-section-filter]')->length === 1, $page.' supports immediate grade changes');
            }
        }
    } finally {
        removeEnrollmentAppFixture($copy);
    }
});
echo 'Section filter pages passed ('.$GLOBALS['enrollment_checks']." checks).\n";
