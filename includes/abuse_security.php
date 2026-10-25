<?php
/** Shared request quotas. Must use an InnoDB table on the application DB. */
function abuseClientIP(): string {
    // Do not trust caller-controlled Forwarded/X-Forwarded-For headers.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function abuseBucket(string $scope, string $identity, int $limit, int $seconds, int $cost = 1): array {
    return ['key' => hash('sha256', $scope . "\0" . $identity), 'limit' => $limit, 'seconds' => $seconds, 'cost' => $cost];
}

/** Atomically charge/check every bucket; returns seconds until a denied quota resets. */
function consumeAbuseLimits(PDO $db, array $buckets, ?int $now = null): int {
    $now ??= time();
    // Stable lock order prevents deadlocks when requests share only some buckets.
    usort($buckets, fn($a, $b) => strcmp($a['key'], $b['key']));
    $retry = 0;
    $db->beginTransaction();
    try {
        foreach ($buckets as $bucket) {
            // Upsert takes an exclusive row lock immediately. INSERT IGNORE
            // would take shared locks that deadlock when upgraded concurrently.
            $db->prepare('INSERT INTO security_rate_limits (bucket_key, window_start, hits, expires_at) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE bucket_key = VALUES(bucket_key)')
                ->execute([$bucket['key'], $now, $now + $bucket['seconds']]);
            $stmt = $db->prepare('SELECT window_start, hits FROM security_rate_limits WHERE bucket_key = ? FOR UPDATE');
            $stmt->execute([$bucket['key']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Rate-limit bucket unavailable.');
            $start = (int)$row['window_start'];
            $hits = (int)$row['hits'];
            if ($start + $bucket['seconds'] <= $now) { $start = $now; $hits = 0; }
            // Saturate counters. Continued rejected traffic never extends the window.
            $hits = min($bucket['limit'] + 1, $hits + $bucket['cost']);
            $db->prepare('UPDATE security_rate_limits SET window_start = ?, hits = ?, expires_at = ? WHERE bucket_key = ?')
                ->execute([$start, $hits, $start + $bucket['seconds'], $bucket['key']]);
            if ($hits > $bucket['limit']) $retry = max($retry, $start + $bucket['seconds'] - $now);
        }
        $db->commit();
        return $retry;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function enforceAbuseLimits(PDO $db, array $buckets, bool $json = false): void {
    try {
        $retry = consumeAbuseLimits($db, $buckets);
    } catch (Throwable $error) {
        // A missing migration/outage must not silently disable abuse protection.
        error_log('Security rate-limit storage unavailable.');
        http_response_code(503);
        $message = 'Service temporarily unavailable. Please try again later.';
        $retry = 0;
    }
    if ($retry > 0) {
        http_response_code(429);
        header('Retry-After: ' . $retry);
        $message = 'Too many attempts. Please wait ' . $retry . ' seconds and try again.';
    }
    if (!isset($message)) return;
    header('Cache-Control: no-store');
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message, 'retry_after' => $retry]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

function loginAbuseBuckets(string $username): array {
    return [abuseBucket('login.ip', abuseClientIP(), 100, 900),
        abuseBucket('login.username', mb_strtolower(trim($username), 'UTF-8'), 10, 900)];
}

function scannerProbeBuckets(int $userId, int $cost): array {
    return [abuseBucket('scan.invalid.user', (string)$userId, 20, 60, $cost),
        abuseBucket('scan.invalid.ip', abuseClientIP(), 100, 60, $cost)];
}

function scannerAbuseBuckets(array $user): array {
    return array_merge([
        abuseBucket('scan.user', (string)$user['id'], 120, 60),
        abuseBucket('scan.device', (string)($_SESSION['session_token'] ?? ''), 120, 60),
        abuseBucket('scan.ip', abuseClientIP(), 600, 60),
    ], scannerProbeBuckets((int)$user['id'], 0));
}

function requireScannerPermission(bool $json = false): void {
    if (in_array(currentUser()['role'] ?? '', ['admin', 'teacher', 'scanner_operator'], true)) return;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Scanner access denied.']);
    } else echo 'Scanner access denied.';
    exit;
}

/** LRN-based reusable identity with a cryptographically random suffix. */
function newStudentQRToken(string $lrn): string {
    return 'STU-' . $lrn . '-' . strtoupper(bin2hex(random_bytes(32)));
}

function passwordPolicyError($password): ?string {
    if (!is_string($password) || str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')) return 'Invalid password.';
    $length = mb_strlen($password, 'UTF-8');
    if ($length < 8 || $length > 16) return 'Password must be between 8 and 16 characters.';
    return null;
}
