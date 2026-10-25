<?php
declare(strict_types=1);

/** Explicit CLI/install migration only. Never call during an HTTP request.
 * MySQL DDL commits implicitly: reject caller transactions, inspect ALTER targets,
 * record successful steps, and retry safely after an interruption. No data backfill.
 */
function ensureEnrollmentSchema(PDO $db): void
{
    if ($db->inTransaction()) throw new LogicException('Enrollment DDL requires a connection outside a transaction');
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') throw new LogicException('Select a database before enrollment migration');
    $lock = 'enrollment_schema_' . substr(hash('sha256', $database), 0, 40);
    $q = $db->prepare('SELECT GET_LOCK(?, 30)');
    $q->execute([$lock]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Enrollment schema migration is already running');
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS enrollment_schema_progress (
            step_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (enrollmentSchemaTables() as $table=>$definition) {
            enrollmentSchemaStep($db, 'table.' . $table, fn()=>$db->exec($definition));
        }
        enrollmentSchemaStep($db, 'control.singleton', fn()=>$db->exec('INSERT IGNORE INTO school_year_control (id,version) VALUES (1,1)'));
        foreach (enrollmentSchemaAlterations() as [$kind,$table,$name,$definition]) {
            $catalog = ['column'=>'COLUMNS','index'=>'STATISTICS','constraint'=>'TABLE_CONSTRAINTS'][$kind];
            $field = ['column'=>'COLUMN_NAME','index'=>'INDEX_NAME','constraint'=>'CONSTRAINT_NAME'][$kind];
            $q = $db->prepare("SELECT COUNT(*) FROM information_schema.$catalog WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND $field=?");
            $q->execute([$table,$name]);
            if (!(int)$q->fetchColumn()) {
                enrollmentSchemaStep($db, $kind . '.' . $table . '.' . $name, fn()=>$db->exec("ALTER TABLE `$table` ADD $definition"));
            }
        }
    } finally {
        $q = $db->prepare('SELECT RELEASE_LOCK(?)');
        $q->execute([$lock]);
    }
}

function enrollmentSchemaStep(PDO $db, string $step, callable $apply): void
{
    $apply();
    $q = $db->prepare('INSERT INTO enrollment_schema_progress (step_key) VALUES (?) ON DUPLICATE KEY UPDATE completed_at=CURRENT_TIMESTAMP');
    $q->execute([$step]);
}

/** Shared definitions also rendered into the two fresh-install SQL imports. */
function enrollmentSchemaTables(): array
{
    $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'school_year_control'=>"CREATE TABLE IF NOT EXISTS school_year_control (
            id TINYINT NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT ck_school_year_control_singleton CHECK (id=1)
        )" . $suffix,
        'school_years'=>"CREATE TABLE IF NOT EXISTS school_years (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(20) NOT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            state ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            active_slot TINYINT GENERATED ALWAYS AS (CASE WHEN state='active' THEN 1 ELSE NULL END) STORED,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_school_year_label (label),
            UNIQUE KEY uq_school_year_active (active_slot),
            KEY idx_school_year_dates (starts_on,ends_on),
            CONSTRAINT ck_school_year_dates CHECK (starts_on<=ends_on)
        )" . $suffix,
        'student_enrollments'=>"CREATE TABLE IF NOT EXISTS student_enrollments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            school_year_id INT NOT NULL,
            state ENUM('prepared','active','completed','cancelled') NOT NULL DEFAULT 'prepared',
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_enrollment_student_year (student_id,school_year_id),
            UNIQUE KEY uq_enrollment_owner (id,student_id),
            KEY idx_enrollment_year_dates (school_year_id,state,starts_on,ends_on),
            CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
            CONSTRAINT fk_enrollment_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE RESTRICT,
            CONSTRAINT ck_enrollment_dates CHECK (starts_on<=ends_on)
        )" . $suffix,
        'enrollment_sections'=>"CREATE TABLE IF NOT EXISTS enrollment_sections (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            section_id INT NOT NULL,
            grade_level ENUM('Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6') NOT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assignment_start (enrollment_id,starts_on),
            UNIQUE KEY uq_assignment_owner (id,enrollment_id),
            KEY idx_assignment_section_dates (section_id,starts_on,ends_on),
            CONSTRAINT fk_assignment_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_assignment_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT,
            CONSTRAINT ck_assignment_dates CHECK (starts_on<=ends_on)
        )" . $suffix,
        'moving_up_batches'=>"CREATE TABLE IF NOT EXISTS moving_up_batches (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_year_id INT NOT NULL,
            target_year_id INT NOT NULL,
            outcome ENUM('promoted','retained','graduated') NOT NULL,
            destination_section_id INT NULL,
            actor_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_moving_up_request (request_key),
            CONSTRAINT fk_batch_source_year FOREIGN KEY (source_year_id) REFERENCES school_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_batch_target_year FOREIGN KEY (target_year_id) REFERENCES school_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_batch_destination_section FOREIGN KEY (destination_section_id) REFERENCES sections(id) ON DELETE RESTRICT,
            CONSTRAINT fk_batch_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
        )" . $suffix,
        'moving_up_decisions'=>"CREATE TABLE IF NOT EXISTS moving_up_decisions (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            source_enrollment_id INT NOT NULL,
            destination_enrollment_id INT NULL,
            target_year_id INT NOT NULL,
            outcome ENUM('promoted','retained','graduated') NOT NULL,
            state ENUM('prepared','applied','cancelled') NOT NULL DEFAULT 'prepared',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            noncancelled_slot TINYINT GENERATED ALWAYS AS (CASE WHEN state<>'cancelled' THEN 1 ELSE NULL END) STORED,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_moving_up_source_target (source_enrollment_id,target_year_id,noncancelled_slot),
            CONSTRAINT fk_decision_batch FOREIGN KEY (batch_id) REFERENCES moving_up_batches(id) ON DELETE RESTRICT,
            CONSTRAINT fk_decision_source FOREIGN KEY (source_enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_decision_destination FOREIGN KEY (destination_enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_decision_target_year FOREIGN KEY (target_year_id) REFERENCES school_years(id) ON DELETE RESTRICT
        )" . $suffix,
        'enrollment_corrections'=>"CREATE TABLE IF NOT EXISTS enrollment_corrections (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            kind VARCHAR(80) NOT NULL,
            command_ciphertext MEDIUMTEXT NOT NULL,
            preview_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            expected_version INT UNSIGNED NOT NULL,
            state ENUM('pending','applied','cancelled') NOT NULL DEFAULT 'pending',
            actor_id INT NOT NULL,
            applied_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_correction_enrollment_state (enrollment_id,state),
            CONSTRAINT fk_correction_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_enrollments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_correction_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
        )" . $suffix,
        'enrollment_migration_issues'=>"CREATE TABLE IF NOT EXISTS enrollment_migration_issues (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            record_id INT NOT NULL,
            reason_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            resolution_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            resolution_id INT NULL,
            resolved_by INT NULL,
            resolved_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_migration_issue (record_type,record_id,reason_code),
            KEY idx_migration_unresolved (resolved_at,record_type),
            CONSTRAINT fk_migration_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE RESTRICT
        )" . $suffix,
    ];
}

function enrollmentSchemaAlterations(): array
{
    return [
        ['column','sections','school_year_id','`school_year_id` INT NULL'],
        ['column','attendance','enrollment_id','`enrollment_id` INT NULL'],
        ['column','attendance','assignment_id','`assignment_id` INT NULL'],
        ['index','sections','idx_sections_year_grade','KEY idx_sections_year_grade (school_year_id,grade_level)'],
        ['index','attendance','idx_attendance_enrollment_date','KEY idx_attendance_enrollment_date (enrollment_id,student_id,date)'],
        ['index','attendance','idx_attendance_assignment_date','KEY idx_attendance_assignment_date (assignment_id,enrollment_id,date)'],
        ['constraint','sections','fk_sections_school_year','CONSTRAINT fk_sections_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE RESTRICT'],
        ['constraint','attendance','fk_attendance_enrollment','CONSTRAINT fk_attendance_enrollment FOREIGN KEY (enrollment_id,student_id) REFERENCES student_enrollments(id,student_id) ON DELETE RESTRICT'],
        ['constraint','attendance','fk_attendance_assignment','CONSTRAINT fk_attendance_assignment FOREIGN KEY (assignment_id,enrollment_id) REFERENCES enrollment_sections(id,enrollment_id) ON DELETE RESTRICT'],
    ];
}
