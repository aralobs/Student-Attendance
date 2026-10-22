<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/enrollment_access.php';

/** Student management is available to admins and assigned teachers only. */
function requireStudentManagement(): void {
    requireLogin();
    if (!isAdmin() && !isTeacher()) {
        http_response_code(403);
        exit('Student management is restricted to administrators and assigned teachers.');
    }
}

/** Return a SQL predicate and bindings, using only developer-supplied aliases. */
function studentSectionScope(string $alias = 'sec', ?int $yearId = null, bool $historical = false): array {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid section query alias.');
    }
    if (!isAdmin() && !isTeacher()) return ['1 = 0', []];
    if (enrollmentModelEnabled(getDB())) {
        [$scope,$params] = enrollmentSectionScope($alias,currentUser(),$historical);
        if ($yearId !== null) return ["($scope) AND {$alias}.school_year_id = ?", array_merge($params,[$yearId])];
        return ["($scope) AND {$alias}.school_year_id = (SELECT id FROM school_years WHERE state='active')",$params];
    }
    if (isAdmin()) return ['1 = 1', []];
    if (!isTeacher()) return ['1 = 0', []];
    return ["{$alias}.is_active = 1 AND {$alias}.adviser_id = ?", [(int)currentUser()['id']]];
}

function studentRecordScope(string $alias = 's', ?int $yearId = null, bool $historical = false): array {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid student query alias.');
    }
    if (!isAdmin() && !isTeacher()) return ['1 = 0', []];
    if (enrollmentModelEnabled(getDB())) {
        [$sectionScope,$params] = studentSectionScope('student_scope_section',$yearId,$historical);
        $states = $historical ? "'active','completed'" : "'active'";
        $dateScope = $historical ? '' : ' AND CURDATE() BETWEEN student_scope_enrollment.starts_on AND student_scope_enrollment.ends_on AND CURDATE() BETWEEN student_scope_assignment.starts_on AND student_scope_assignment.ends_on';
        return ["EXISTS (SELECT 1 FROM student_enrollments student_scope_enrollment
          JOIN enrollment_sections student_scope_assignment ON student_scope_assignment.enrollment_id=student_scope_enrollment.id
          JOIN sections student_scope_section ON student_scope_section.id=student_scope_assignment.section_id
          JOIN school_years student_scope_year ON student_scope_year.id=student_scope_enrollment.school_year_id
          WHERE student_scope_enrollment.student_id={$alias}.id AND student_scope_enrollment.state IN ($states)
          AND student_scope_section.school_year_id=student_scope_year.id AND ($sectionScope)$dateScope)",$params];
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
