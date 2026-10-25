<?php
require_once __DIR__ . '/pii_security.php';

/** Never use caller-supplied request IDs, device IDs or event text. */
function securityRequestId(): string {
    static $id; return $id ??= bin2hex(random_bytes(12));
}
function securityAuditActor(): ?int {
    return isset($GLOBALS['security_audit_actor']) ? (int)$GLOBALS['security_audit_actor'] : null;
}
function securityAuditContext(int $actor): void {
    $GLOBALS['security_audit_actor'] = $actor;
    $token = $_SESSION['session_token'] ?? '';
    $GLOBALS['security_audit_device'] = $token !== '' ? hash('sha256', $token) : null;
}
function securityAuditRoute(): string {
    $root = realpath(__DIR__ . '/..'); $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $root = str_replace('\\','/',(string)$root); $script = str_replace('\\','/',(string)$script);
    return $script && str_starts_with($script,$root . '/') ? substr($script,strlen($root)+1,120) : 'cli';
}
function securityAuditAlertCount(): ?int {
    try { return (int)getDB(true)->query('SELECT COUNT(*) FROM security_events WHERE alert=1 AND occurred_at >= NOW() - INTERVAL 1 DAY')->fetchColumn(); }
    catch (Throwable $error) { error_log('SECURITY_ALERT audit_storage_unavailable request_id=' . securityRequestId()); return null; }
}
/** IDs/outcomes only. Sensitive before/after values belong in encrypted history. */
function securityAuditEvent(PDO $db, string $action, string $outcome = 'success', string $targetType = 'route', ?int $targetId = null, bool $alert = false): string {
    if (!preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $action)
        || !in_array($outcome, ['success','failure','denied','limited','error'], true)
        || !in_array($targetType, ['route','user','student','attendance','report','school_year','enrollment','enroll_correct'], true)) throw new InvalidArgumentException('Invalid audit event.');
    $event = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO security_events (event_id,request_id,actor_id,device_hash,action,outcome,target_type,target_id,alert,route_name) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$event,securityRequestId(),securityAuditActor(),$GLOBALS['security_audit_device'] ?? null,$action,$outcome,$targetType,$targetId,(int)$alert,securityAuditRoute()]);
    return $event;
}
function securityAuditChange(PDO $db, string $action, string $targetType, int $targetId, ?array $before, array $after, bool $alert = false): void {
    if (!$db->inTransaction()) throw new LogicException('Change history requires the mutation transaction.');
    $event = securityAuditEvent($db,$action,'success',$targetType,$targetId,$alert);
    $ciphertext = encryptPII(json_encode(['before'=>$before,'after'=>$after], JSON_THROW_ON_ERROR), 'security-change:' . $event);
    $db->prepare('INSERT INTO security_change_history (event_id,change_ciphertext) VALUES (?,?)')->execute([$event,$ciphertext]);
}
function attendanceAuditSnapshot(?array $row): ?array {
    if ($row === null) return null;
    return array_intersect_key($row, array_flip(['id','student_id','enrollment_id','assignment_id','date','am_in','am_out','am_status','pm_in','pm_out','pm_status','attendance_type','remarks','recorded_by']));
}
function auditAttendanceAfterWrite(PDO $db, int $id, ?array $before, string $action): void {
    $read = $db->prepare('SELECT * FROM attendance WHERE id = ?'); $read->execute([$id]);
    $after = $read->fetch(PDO::FETCH_ASSOC);
    if (!$after) throw new RuntimeException('Attendance history target is missing.');
    securityAuditChange($db,$action,'attendance',$id,attendanceAuditSnapshot($before),attendanceAuditSnapshot($after));
}
function auditAccountAfterWrite(PDO $db, int $id, ?array $before, string $action): void {
    $read = $db->prepare('SELECT role,is_active FROM users WHERE id = ?'); $read->execute([$id]);
    $after = $read->fetch(PDO::FETCH_ASSOC) ?: ['deleted'=>true];
    $safeBefore = $before === null ? null : array_intersect_key($before,array_flip(['role','is_active']));
    securityAuditChange($db,$action,'user',$id,$safeBefore,$after,true);
}
/** Route-level access/denial coverage supplements the transactional change events. */
function registerSecurityRequestAudit(): void {
    if (PHP_SAPI === 'cli') return;
    register_shutdown_function(function (): void {
        $root = realpath(__DIR__ . '/..');
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (!$script || !str_starts_with(str_replace('\\','/',$script),str_replace('\\','/',$root) . '/')) return;
        $route = str_replace('\\','/',substr($script,strlen($root)+1));
        $status = http_response_code() ?: 200;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($route === 'heartbeat.php' && $status < 400) return;
        $outcome = $status >= 500 ? 'error' : ($status === 429 ? 'limited' : ($status >= 400 ? 'denied' : ($GLOBALS['security_audit_outcome'] ?? 'success')));
        $action = match (true) {
            $route === 'index.php' && $method === 'POST' => 'auth.login',
            $route === 'logout.php' => 'auth.logout',
            $route === 'attendance/scan_process.php' => 'scanner.request',
            str_starts_with($route,'reports/') => $method === 'POST' ? 'report.write_request' : 'report.read',
            str_starts_with($route,'students/') || $route === 'student_photo.php' => $method === 'POST' ? 'student.write_request' : 'student.read',
            str_starts_with($route,'users/') || $route === 'profile.php' => $method === 'POST' ? 'account.write_request' : 'account.read',
            default => 'application.request',
        };
        // Successful anonymous login-page loads do not need a log entry.
        if (securityAuditActor() === null && $action === 'application.request' && $outcome === 'success') return;
        $target = null;
        $raw = $_GET['id'] ?? $_POST['id'] ?? $_POST['student_id'] ?? null;
        if (is_scalar($raw)) { $parsed = filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if ($parsed !== false) $target = $parsed; }
        try {
            $db = getDB(true);
            // Never commit a mutation that terminated without its own commit.
            if ($db->inTransaction()) $db->rollBack();
            securityAuditEvent($db,$action,$outcome,$action === 'student.read' ? 'student' : 'route',$target,
                $outcome !== 'success' || ($action === 'report.read' && preg_match('/(?:export|sf2|index_reports)/',$route))
                    || ($action === 'student.read' && preg_match('/(?:bulk|qr_download)/',$route)));
        } catch (Throwable $error) {
            // Logging outages must be visible, but no SQL/PII/credentials go into diagnostics.
            error_log('SECURITY_ALERT audit_storage_unavailable request_id=' . securityRequestId());
        }
    });
}
