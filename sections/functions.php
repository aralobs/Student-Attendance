/**
 * Return the canonical schedule for a given grade level.
 * Returns null if the grade isn't recognized.
 */
function getScheduleForGrade(string $grade): ?array
{
    // Normalize: "Grade 1", "grade 1", "Grade1" all → "grade1"
    $key = strtolower(str_replace(' ', '', trim($grade)));

    // Kinder (accept "kinder", "kindergarten", "k")
    if (in_array($key, ['kinder', 'kindergarten', 'k'], true)) {
        return [
            'schedule_type'      => 'full_day',
            'am_in_start'        => '07:00:00',
            'am_in_end'          => '08:00:00',
            'am_late_threshold'  => '07:31:00',
            'am_out_start'       => '11:00:00',
            'am_out_end'         => '12:00:00',
            'pm_in_start'        => '13:00:00',
            'pm_in_end'          => '13:30:00',
            'pm_late_threshold'  => '13:01:00',
            'pm_out_start'       => '15:30:00',
            'pm_out_end'         => '16:00:00',
        ];
    }

    // Grade N — extract number
    if (preg_match('/^grade(\d+)$/', $key, $m)) {
        $n = (int)$m[1];

        // Grade 1 & 2 — AM only, 7am–12pm
        if ($n >= 1 && $n <= 2) {
            return [
                'schedule_type'      => 'am_only',
                'am_in_start'        => '07:00:00',
                'am_in_end'          => '08:00:00',
                'am_late_threshold'  => '07:31:00',
                'am_out_start'       => '11:00:00',
                'am_out_end'         => '12:00:00',
                // PM values kept as sane defaults (ignored when am_only)
                'pm_in_start'        => '13:00:00',
                'pm_in_end'          => '13:30:00',
                'pm_late_threshold'  => '13:01:00',
                'pm_out_start'       => '15:30:00',
                'pm_out_end'         => '16:00:00',
            ];
        }

        // Grade 3–6 — full day, 7am–4pm
        if ($n >= 3 && $n <= 6) {
            return [
                'schedule_type'      => 'full_day',
                'am_in_start'        => '07:00:00',
                'am_in_end'          => '08:00:00',
                'am_late_threshold'  => '07:31:00',
                'am_out_start'       => '11:00:00',
                'am_out_end'         => '12:00:00',
                'pm_in_start'        => '13:00:00',
                'pm_in_end'          => '13:30:00',
                'pm_late_threshold'  => '13:01:00',
                'pm_out_start'       => '15:30:00',
                'pm_out_end'         => '16:00:00',
            ];
        }
    }

    return null; // unknown grade
}