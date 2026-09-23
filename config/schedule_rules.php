<?php
/**
 * schedule_rules.php
 * Provides DEFAULT schedules per grade level.
 * Admins can override any time when creating/editing a section.
 */

function getScheduleForGrade(string $grade): ?array
{
    $key = strtolower(str_replace(' ', '', trim($grade)));

    // Default AM window (8:00 AM – 12:00 PM)
    $AM = [
        'am_in_start'       => '08:00:00',
        'am_in_end'         => '08:30:00',
        'am_late_threshold' => '08:01:00',
        'am_out_start'      => '11:30:00',
        'am_out_end'        => '12:00:00',
    ];

    // Default PM window (1:00 PM – 4:00 PM)
    $PM = [
        'pm_in_start'       => '13:00:00',
        'pm_in_end'         => '13:30:00',
        'pm_late_threshold' => '13:01:00',
        'pm_out_start'      => '15:30:00',
        'pm_out_end'        => '16:00:00',
    ];

    // Kinder: half-day, admin picks AM or PM
    if (in_array($key, ['kinder', 'kindergarten', 'k'], true)) {
        return [
            'allowed_types' => ['am_only', 'pm_only'],
            'default_type'  => 'am_only',
            'am'            => $AM,
            'pm'            => $PM,
        ];
    }

    if (preg_match('/^grade(\d+)$/', $key, $m)) {
        $n = (int)$m[1];

        // Grade 1–3: AM only
        if ($n >= 1 && $n <= 3) {
            return [
                'allowed_types' => ['am_only'],
                'default_type'  => 'am_only',
                'am'            => $AM,
                'pm'            => null,
            ];
        }

        // Grade 4–6: Full day
        if ($n >= 4 && $n <= 6) {
            return [
                'allowed_types' => ['full_day'],
                'default_type'  => 'full_day',
                'am'            => $AM,
                'pm'            => $PM,
            ];
        }
    }

    return null;
}