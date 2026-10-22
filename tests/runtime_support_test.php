<?php
// Deliberate expiry forces a supported-runtime review instead of silently retaining
// an obsolete CI/runtime policy. Dates: https://www.php.net/supported-versions.php
$supportedUntil=['8.2'=>'2026-12-31','8.3'=>'2027-12-31','8.4'=>'2028-12-31','8.5'=>'2029-12-31'];
$branch=PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (!isset($supportedUntil[$branch]) || gmdate('Y-m-d') > $supportedUntil[$branch]) {
    fwrite(STDERR,"PHP branch must be reviewed/upgraded before deployment.\n"); exit(1);
}
echo "PHP $branch is within the reviewed security-support window. Install current patch releases.\n";
