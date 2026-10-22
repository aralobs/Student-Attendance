<?php
declare(strict_types=1);
// This command adds schema only; it never infers dates or maps legacy records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || $argv[1] !== '--schema') {
    fwrite(STDERR, "Usage: php migrations/school_year_enrollment.php --schema\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/enrollment_schema.php';
try {
    ensureEnrollmentSchema(getDB(true));
    fwrite(STDOUT, "Annual enrollment schema ready. Legacy records remain unmapped.\n");
} catch (Throwable $error) {
    // No connection details or database record contents in command output.
    fwrite(STDERR, "Annual enrollment schema failed; completed DDL steps are retained for retry.\n");
    exit(1);
}
