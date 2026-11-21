<?php
declare(strict_types=1);
// Short CLI entry point for hosting panels with a 255-character cron limit.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/school_year_enrollment.php';
