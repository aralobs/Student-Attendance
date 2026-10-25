<?php
/** Shared editable text templates. HTML is escaped when used in email. */
function notificationTemplateDefaults(): array {
    return [
        'sms_arrival_template' => 'Hello Parent/Guardian, {student_name} recorded Time-In at {time} on {date} at {school_name}.',
        'sms_departure_template' => 'Hello Parent/Guardian, {student_name} recorded Time-Out at {time} on {date} at {school_name}.',
        'sms_absence_template' => 'Hello Parent/Guardian, {student_name} has no attendance scans on {date} at {school_name}. Please contact the school if needed.',
        'sms_missing_checkout_template' => 'Parent/Guardian: {student_name} did not scan Time-Out on {date}. Please contact the school to confirm their departure.',
        'email_arrival_subject' => 'Time-In recorded - {student_name}',
        'email_departure_subject' => 'Time-Out recorded - {student_name}',
        'email_absence_subject' => 'Absence notice - {student_name}',
        'email_arrival_template' => "Dear Parent/Guardian,\n\n{student_name} recorded Time-In at {time} on {date}.\nSection: {section}\n\nRegards,\n{school_name}",
        'email_departure_template' => "Dear Parent/Guardian,\n\n{student_name} recorded Time-Out at {time} on {date}.\nSection: {section}\n\nRegards,\n{school_name}",
        'email_absence_template' => "Dear Parent/Guardian,\n\n{student_name} was marked absent on {date}.\nSection: {section}\nPlease contact the school to explain the absence.\n\nRegards,\n{school_name}",
    ];
}

function notificationTemplate(string $key): string {
    $canonical = preg_replace('/^sms_(?:am|pm)_(arrival|departure)_template$/', 'sms_$1_template', $key);
    $saved = getSetting($canonical);
    if (is_string($saved)) return trim($saved) !== '' ? $saved : (notificationTemplateDefaults()[$canonical] ?? '');
    // Preserve legacy event-specific values until shared templates are saved.
    $legacy = $key !== $canonical ? [$key] : match ($canonical) {
        'sms_arrival_template' => ['sms_am_arrival_template', 'sms_pm_arrival_template'],
        'sms_departure_template' => ['sms_am_departure_template', 'sms_pm_departure_template'],
        default => [],
    };
    foreach ($legacy as $oldKey) {
        $saved = getSetting($oldKey);
        if (is_string($saved) && trim($saved) !== '') return $saved;
    }
    return notificationTemplateDefaults()[$canonical] ?? '';
}

function renderNotificationText(string $template, array $student, ?string $date = null, ?string $time = null): string {
    $date ??= date('Y-m-d');
    return strtr($template, [
        '{student_name}' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
        '{time}' => $time ?? date('h:i A'),
        '{date}' => date('F j, Y', strtotime($date)),
        '{section}' => $student['section_name'] ?? '',
        '{school_name}' => getSetting('school_name') ?: 'SPCCS',
    ]);
}

