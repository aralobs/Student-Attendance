-- Schema only. No student, parent, account or integration records.

CREATE DATABASE IF NOT EXISTS attendance_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE attendance_system;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sms_logs;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS school_calendar;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    full_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NULL,
    role       ENUM('admin','teacher','scanner_operator') NOT NULL DEFAULT 'teacher',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    section_name  VARCHAR(50)  NOT NULL,
    grade_level   ENUM(
                    'Kinder',
                    'Grade 1',
                    'Grade 2',
                    'Grade 3',
                    'Grade 4',
                    'Grade 5',
                    'Grade 6'
                  ) NOT NULL,
    schedule_type ENUM('full_day','am_only','pm_only') NOT NULL DEFAULT 'full_day',
    adviser_id    INT          NULL,
    school_year   VARCHAR(20)  NOT NULL DEFAULT '2026-2027',
    am_in_start   TIME         NOT NULL DEFAULT '06:00:00',
    am_in_end     TIME         NOT NULL DEFAULT '08:00:00',
    am_out_start  TIME         NOT NULL DEFAULT '11:00:00',
    am_out_end    TIME         NOT NULL DEFAULT '12:00:00',
    pm_in_start   TIME         NOT NULL DEFAULT '12:00:00',
    pm_in_end     TIME         NOT NULL DEFAULT '13:30:00',
    pm_out_start  TIME         NOT NULL DEFAULT '17:00:00',
    pm_out_end    TIME         NOT NULL DEFAULT '18:00:00',
    am_late_threshold TIME     NOT NULL DEFAULT '07:31:00',
    pm_late_threshold TIME     NOT NULL DEFAULT '12:31:00',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (adviser_id)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    lrn            VARCHAR(20)  NOT NULL UNIQUE,
    first_name     VARCHAR(50)  NOT NULL,
    middle_name    VARCHAR(50)  NULL,
    last_name      VARCHAR(50)  NOT NULL,
    gender         ENUM('Male','Female') NOT NULL,
    birth_date     DATE         NULL,
    address        TEXT         NULL,
    section_id     INT          NULL,
    photo          VARCHAR(255) NOT NULL DEFAULT 'default.png',
    qr_code        VARCHAR(255) NULL,
    qr_token       VARCHAR(100) NOT NULL UNIQUE,
    parent_name    VARCHAR(100) NULL,
    parent_contact VARCHAR(20)  NULL,
    parent_email   VARCHAR(100) NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id)
        REFERENCES sections(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT          NOT NULL,
    date             DATE         NOT NULL,

    am_in            TIME         NULL COMMENT 'AM arrival time',
    am_out           TIME         NULL COMMENT 'AM departure time',
    am_status        ENUM('present','late','absent') NULL COMMENT 'AM session status',

    pm_in            TIME         NULL COMMENT 'PM arrival time',
    pm_out           TIME         NULL COMMENT 'PM departure time',
    pm_status        ENUM('present','late','absent') NULL COMMENT 'PM session status',

    attendance_type  ENUM('full_day','partial','absent','holiday','remarked') NOT NULL DEFAULT 'absent',
    remarks          TEXT         NULL,
    recorded_by      INT          NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_attendance (student_id, date),
    FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON DELETE CASCADE,
    FOREIGN KEY (recorded_by)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE school_calendar (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    date        DATE         NOT NULL UNIQUE,
    title       VARCHAR(100) NOT NULL,
    type        ENUM(
                    'holiday',
                    'no_class',
                    'special_event',
                    'school_day'
                ) NOT NULL DEFAULT 'school_day',
    description TEXT         NULL,
    created_by  INT          NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT          NULL,
    recipient_number VARCHAR(20)  NOT NULL,
    message          TEXT         NOT NULL,
    type             ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout') NOT NULL,
    status           ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
    api_response     TEXT         NULL,
    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT         NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;






CREATE INDEX idx_attendance_date        ON attendance (date);
CREATE INDEX idx_attendance_student     ON attendance (student_id);
CREATE INDEX idx_attendance_type        ON attendance (attendance_type);
CREATE INDEX idx_attendance_am_status   ON attendance (am_status);
CREATE INDEX idx_attendance_pm_status   ON attendance (pm_status);
CREATE INDEX idx_students_section       ON students (section_id);
CREATE INDEX idx_students_lrn           ON students (lrn);
CREATE INDEX idx_students_qr_token      ON students (qr_token);
CREATE INDEX idx_students_active        ON students (is_active);
CREATE INDEX idx_sections_grade         ON sections (grade_level);
CREATE INDEX idx_sections_schedule      ON sections (schedule_type);
CREATE INDEX idx_calendar_date          ON school_calendar (date);
CREATE INDEX idx_calendar_type          ON school_calendar (type);
CREATE INDEX idx_sms_logs_student       ON sms_logs (student_id);
CREATE INDEX idx_sms_logs_type          ON sms_logs (type);



CREATE TABLE IF NOT EXISTS end_of_day_parent_alerts (
    student_id INT NOT NULL,
    date DATE NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','sent','failed','unknown') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_start BIGINT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL,
    INDEX rate_limit_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sf2_remarks (
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    report_month TINYINT UNSIGNED NOT NULL,
    report_year SMALLINT UNSIGNED NOT NULL,
    remark_ciphertext TEXT NOT NULL,
    version INT UNSIGNED NOT NULL,
    updated_by INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, section_id, report_month, report_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sf2_remark_history (
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    report_month TINYINT UNSIGNED NOT NULL,
    report_year SMALLINT UNSIGNED NOT NULL,
    actor_id INT NOT NULL,
    version INT UNSIGNED NOT NULL,
    change_ciphertext TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX sf2_history_target (student_id, section_id, report_year, report_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_events (
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  request_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT NULL,
  device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  action VARCHAR(80) NOT NULL,
  outcome VARCHAR(12) NOT NULL,
  target_type VARCHAR(16) NOT NULL,
  target_id INT NULL,
  alert TINYINT(1) NOT NULL DEFAULT 0,
  route_name VARCHAR(120) NOT NULL,
  occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY audit_time (occurred_at), KEY audit_actor (actor_id,occurred_at), KEY audit_alert (alert,occurred_at),
  KEY audit_target (target_type,target_id,occurred_at), KEY audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS security_change_history (
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  change_ciphertext MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER security_events_no_update BEFORE UPDATE ON security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_events_no_delete BEFORE DELETE ON security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_history_no_update BEFORE UPDATE ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_history_no_delete BEFORE DELETE ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';


-- Annual enrollment ownership. Fresh installations only; use the PHP migration for existing records.
CREATE TABLE IF NOT EXISTS school_year_control (
            id TINYINT NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT ck_school_year_control_singleton CHECK (id=1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_years (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_enrollments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollment_sections (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moving_up_batches (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moving_up_decisions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollment_corrections (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollment_migration_issues (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO school_year_control (id,version) VALUES (1,1);

ALTER TABLE `sections` ADD `school_year_id` INT NULL;
ALTER TABLE `attendance` ADD `enrollment_id` INT NULL;
ALTER TABLE `attendance` ADD `assignment_id` INT NULL;
ALTER TABLE `sections` ADD KEY idx_sections_year_grade (school_year_id,grade_level);
ALTER TABLE `attendance` ADD KEY idx_attendance_enrollment_date (enrollment_id,student_id,date);
ALTER TABLE `attendance` ADD KEY idx_attendance_assignment_date (assignment_id,enrollment_id,date);
ALTER TABLE `sections` ADD CONSTRAINT fk_sections_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE RESTRICT;
ALTER TABLE `attendance` ADD CONSTRAINT fk_attendance_enrollment FOREIGN KEY (enrollment_id,student_id) REFERENCES student_enrollments(id,student_id) ON DELETE RESTRICT;
ALTER TABLE `attendance` ADD CONSTRAINT fk_attendance_assignment FOREIGN KEY (assignment_id,enrollment_id) REFERENCES enrollment_sections(id,enrollment_id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS enrollment_schema_progress (
    step_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
