<?php
/** Provider acceptance is not confirmation of delivery to a phone. */
function smsResponseStatus(string $response): string {
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) return 'failed';
    $status = $decoded['message']['status'] ?? $decoded['status'] ?? null;
    if ($status === 'sent' || ($decoded['event'] ?? null) === 'message.sent') return 'sent';
    if ($status === 'pending') return 'pending';
    return 'failed';
}
