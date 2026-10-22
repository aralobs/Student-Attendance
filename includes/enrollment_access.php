<?php
declare(strict_types=1);
require_once __DIR__ . '/enrollment_repository.php';

/** Existing-style [SQL predicate, positional bindings] tuple for a sections alias.
 * Historical access follows the section's CURRENT adviser, including archived sections.
 */
function enrollmentSectionScope(string $alias, array $actor, bool $historical = false): array
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $alias)) throw new InvalidArgumentException('Invalid section query alias.');
    if (!filter_var($actor['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) return ['1 = 0', []];
    if (($actor['role'] ?? '') === 'admin') return ['1 = 1', []];
    if (($actor['role'] ?? '') !== 'teacher') return ['1 = 0', []];
    return [($historical ? '' : "$alias.is_active = 1 AND ") . "$alias.adviser_id = ?", [(int)$actor['id']]];
}

/** Guard only: caller owns any transaction. Within one it locks and rechecks context.
 * Scanner eligibility is resolved separately via purpose=scan; it grants no staff rights.
 */
function requireEnrollmentWrite(PDO $db, array $actor, int $studentId, string $date): array
{
    [$scope,$params] = enrollmentSectionScope('sec',$actor);
    if ($scope === '1 = 0') throw new DomainException('Enrollment write access denied.');
    $ctx = getEnrollmentContext($db,$studentId,$date,'write',$db->inTransaction());
    if (!$ctx) throw new DomainException('No eligible active enrollment for this date.');
    $q = $db->prepare("SELECT sec.id FROM sections sec WHERE sec.id=? AND ($scope)");
    $q->execute(array_merge([$ctx['section_id']],$params));
    if (!$q->fetchColumn()) throw new DomainException('Enrollment write access denied.');
    return $ctx;
}
