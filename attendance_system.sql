-- Schema only. No student, parent, account or integration records.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;




CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `am_in` time DEFAULT NULL COMMENT 'AM arrival time',
  `am_out` time DEFAULT NULL COMMENT 'AM departure time',
  `am_status` enum('present','late','absent') DEFAULT NULL COMMENT 'AM session status',
  `pm_in` time DEFAULT NULL COMMENT 'PM arrival time',
  `pm_out` time DEFAULT NULL COMMENT 'PM departure time',
  `pm_status` enum('present','late','absent') DEFAULT NULL COMMENT 'PM session status',
  `attendance_type` enum('full_day','partial','absent','holiday','remarked') NOT NULL DEFAULT 'absent',
  `remarks` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `school_calendar` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `title` varchar(100) NOT NULL,
  `type` enum('holiday','no_class','special_event','school_day') NOT NULL DEFAULT 'school_day',
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `grade_level` enum('Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6') NOT NULL,
  `schedule_type` enum('full_day','am_only','pm_only') NOT NULL DEFAULT 'full_day',
  `adviser_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) NOT NULL DEFAULT '2026-2027',
  `am_in_start` time NOT NULL DEFAULT '06:00:00',
  `am_in_end` time NOT NULL DEFAULT '08:00:00',
  `am_out_start` time NOT NULL DEFAULT '11:00:00',
  `am_out_end` time NOT NULL DEFAULT '12:00:00',
  `pm_in_start` time NOT NULL DEFAULT '12:00:00',
  `pm_in_end` time NOT NULL DEFAULT '13:30:00',
  `pm_out_start` time NOT NULL DEFAULT '17:00:00',
  `pm_out_end` time NOT NULL DEFAULT '18:00:00',
  `am_late_threshold` time NOT NULL DEFAULT '07:31:00',
  `pm_late_threshold` time NOT NULL DEFAULT '12:31:00',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `recipient_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `type` enum('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout') NOT NULL,
  `status` enum('sent','failed','pending') NOT NULL DEFAULT 'pending',
  `api_response` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `lrn` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `birth_date` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `grade_level` enum('Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6') DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `enrollment_status` enum('active','promoted','retained','graduated','transferred_out','dropped') NOT NULL DEFAULT 'active',
  `previous_section_id` int(11) DEFAULT NULL,
  `previous_grade_level` enum('Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6') DEFAULT NULL,
  `previous_school_year` varchar(20) DEFAULT NULL,
  `photo` varchar(255) NOT NULL DEFAULT 'default.png',
  `qr_code` varchar(255) DEFAULT NULL,
  `qr_token` varchar(100) NOT NULL,
  `parent_name` varchar(100) DEFAULT NULL,
  `parent_contact` varchar(20) DEFAULT NULL,
  `parent_email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','teacher','scanner_operator') NOT NULL DEFAULT 'teacher',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `session_token` varchar(255) DEFAULT NULL,
  `session_user_agent` varchar(255) DEFAULT NULL,
  `session_ip` varchar(45) DEFAULT NULL,
  `session_last_activity` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_attendance_date` (`date`),
  ADD KEY `idx_attendance_student` (`student_id`),
  ADD KEY `idx_attendance_type` (`attendance_type`),
  ADD KEY `idx_attendance_am_status` (`am_status`),
  ADD KEY `idx_attendance_pm_status` (`pm_status`);

ALTER TABLE `school_calendar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_calendar_date` (`date`),
  ADD KEY `idx_calendar_type` (`type`);

ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `adviser_id` (`adviser_id`),
  ADD KEY `idx_sections_grade` (`grade_level`),
  ADD KEY `idx_sections_schedule` (`schedule_type`);

ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sms_logs_student` (`student_id`),
  ADD KEY `idx_sms_logs_type` (`type`);

ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lrn` (`lrn`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `idx_students_section` (`section_id`),
  ADD KEY `idx_students_lrn` (`lrn`),
  ADD KEY `idx_students_qr_token` (`qr_token`),
  ADD KEY `idx_students_active` (`is_active`);

ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `uniq_session_token` (`session_token`);


ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `school_calendar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `school_calendar`
  ADD CONSTRAINT `school_calendar_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL;

ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS end_of_day_parent_alerts (
  student_id INT NOT NULL,
  date DATE NOT NULL,
  requested_by INT NOT NULL,
  status ENUM('pending','sent','failed','unknown') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

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
