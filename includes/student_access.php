<?php
require_once __DIR__ . '/functions.php';

/** Student management is available to admins and assigned teachers only. */
function requireStudentManagement(): void {
    requireLogin();
    if (!isAdmin() && !isTeacher()) {
        http_response_code(403);
        exit('Student management is restricted to administrators and assigned teachers.');
    }
}

/** Return a SQL predicate and bindings, using only developer-supplied aliases. */
function studentSectionScope(string $alias = 'sec'): array {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid section query alias.');
    }
    if (isAdmin()) return ['1 = 1', []];
    if (!isTeacher()) return ['1 = 0', []];
    return ["{$alias}.is_active = 1 AND {$alias}.adviser_id = ?", [(int)currentUser()['id']]];
}

function studentRecordScope(string $alias = 's'): array {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid student query alias.');
    }
    if (isAdmin()) return ['1 = 1', []];
    [$sectionScope, $params] = studentSectionScope('student_scope_section');
    return ["EXISTS (SELECT 1 FROM sections student_scope_section
        WHERE student_scope_section.id = {$alias}.section_id AND ({$sectionScope}))", $params];
}

function requireStudentCreationSection(int $sectionId): void {
    if (isAdmin()) return;
    if (!isTeacher() || $sectionId <= 0) {
        http_response_code(403);
        exit('Choose one of your assigned active sections.');
    }
    [$scope, $params] = studentSectionScope('sec');
    $check = getDB()->prepare("SELECT sec.id FROM sections sec WHERE sec.id = ? AND ({$scope})");
    $check->execute(array_merge([$sectionId], $params));
    if (!$check->fetch()) {
        http_response_code(403);
        exit('Access denied to this section.');
    }
}
