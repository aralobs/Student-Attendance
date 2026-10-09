-- ============================================================
-- SPCCS Elementary Attendance System
-- Complete Database Schema v2.0
-- San Pablo City Central School
-- Kinder through Grade 6
-- ============================================================

CREATE DATABASE IF NOT EXISTS attendance_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE attendance_system;

-- ============================================================
-- DROP TABLES (clean reinstall)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sms_logs;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS school_calendar;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- TABLE: users
-- ============================================================
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

-- ============================================================
-- TABLE: sections
-- ============================================================
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
    -- AM schedule window
    am_in_start   TIME         NOT NULL DEFAULT '06:00:00',
    am_in_end     TIME         NOT NULL DEFAULT '08:00:00',
    am_out_start  TIME         NOT NULL DEFAULT '11:00:00',
    am_out_end    TIME         NOT NULL DEFAULT '12:00:00',
    -- PM schedule window
    pm_in_start   TIME         NOT NULL DEFAULT '12:00:00',
    pm_in_end     TIME         NOT NULL DEFAULT '13:30:00',
    pm_out_start  TIME         NOT NULL DEFAULT '17:00:00',
    pm_out_end    TIME         NOT NULL DEFAULT '18:00:00',
    -- Late threshold
    am_late_threshold TIME     NOT NULL DEFAULT '07:31:00',
    pm_late_threshold TIME     NOT NULL DEFAULT '12:31:00',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (adviser_id)
        REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: students
-- ============================================================
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

-- ============================================================
-- TABLE: attendance
-- 4-event system: am_in, am_out, pm_in, pm_out
-- attendance_type: full_day, partial, absent, holiday
-- ============================================================
CREATE TABLE attendance (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT          NOT NULL,
    date             DATE         NOT NULL,

    -- AM events
    am_in            TIME         NULL COMMENT 'AM arrival time',
    am_out           TIME         NULL COMMENT 'AM departure time',
    am_status        ENUM('present','late','absent') NULL COMMENT 'AM session status',

    -- PM events
    pm_in            TIME         NULL COMMENT 'PM arrival time',
    pm_out           TIME         NULL COMMENT 'PM departure time',
    pm_status        ENUM('present','late','absent') NULL COMMENT 'PM session status',

    -- Overall
    attendance_type  ENUM('full_day','partial','absent','holiday') NOT NULL DEFAULT 'absent',
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

-- ============================================================
-- TABLE: school_calendar
-- Marks holidays, no-class days, special events
-- ============================================================
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

-- ============================================================
-- TABLE: sms_logs
-- ============================================================
CREATE TABLE sms_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT          NULL,
    recipient_number VARCHAR(20)  NOT NULL,
    message          TEXT         NOT NULL,
    type             ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence') NOT NULL,
    status           ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
    api_response     TEXT         NULL,
    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)
        REFERENCES students(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: system_settings
-- ============================================================
CREATE TABLE system_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT         NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: Users (password = 'password' for all)
-- ============================================================
INSERT INTO users (username, password, full_name, email, role) VALUES
('admin',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator',  'admin@spccs.edu.ph',    'admin'),
('teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos',          'msantos@spccs.edu.ph',  'teacher'),
('teacher2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jose Reyes',            'jreyes@spccs.edu.ph',   'teacher'),
('teacher3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana Cruz',              'acruz@spccs.edu.ph',    'teacher'),
('teacher4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pedro Dela Cruz',       'pdelacruz@spccs.edu.ph','teacher'),
('teacher5', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rosa Bautista',         'rbautista@spccs.edu.ph','teacher'),
('teacher6', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Carlos Mendoza',        'cmendoza@spccs.edu.ph', 'teacher'),
('teacher7', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Elena Torres',          'etorres@spccs.edu.ph',  'teacher');

-- ============================================================
-- SEED: Sections (3 per grade level = 21 sections)
-- schedule_type adjustable per section
-- ============================================================
INSERT INTO sections (
    section_name, grade_level, schedule_type, adviser_id, school_year,
    am_in_start, am_in_end, am_late_threshold,
    am_out_start, am_out_end,
    pm_in_start, pm_in_end, pm_late_threshold,
    pm_out_start, pm_out_end
) VALUES
-- Kinder (am_only — half day)
('Kinder - Sampaguita', 'Kinder', 'am_only', 2, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Kinder - Rosal',      'Kinder', 'am_only', 3, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Kinder - Camia',      'Kinder', 'am_only', 4, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 1 (full_day)
('Grade 1 - Mabini',    'Grade 1', 'full_day', 2, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 1 - Rizal',     'Grade 1', 'full_day', 3, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 1 - Bonifacio', 'Grade 1', 'full_day', 4, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 2 (full_day)
('Grade 2 - Magayon',   'Grade 2', 'full_day', 5, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 2 - Mayon',     'Grade 2', 'full_day', 6, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 2 - Pulag',     'Grade 2', 'full_day', 7, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 3 (full_day)
('Grade 3 - Aguinaldo', 'Grade 3', 'full_day', 2, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 3 - Luna',      'Grade 3', 'full_day', 3, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 3 - Silang',    'Grade 3', 'full_day', 4, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 4 (full_day)
('Grade 4 - Lakandula', 'Grade 4', 'full_day', 5, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 4 - Lapu-Lapu', 'Grade 4', 'full_day', 6, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 4 - Legaspi',   'Grade 4', 'full_day', 7, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 5 (full_day)
('Grade 5 - Bathala',   'Grade 5', 'full_day', 2, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 5 - Diwata',    'Grade 5', 'full_day', 3, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 5 - Anito',     'Grade 5', 'full_day', 4, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),

-- Grade 6 (full_day)
('Grade 6 - Kalikasan',  'Grade 6', 'full_day', 5, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 6 - Kalikayan',  'Grade 6', 'full_day', 6, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00'),
('Grade 6 - Kalayaan',   'Grade 6', 'full_day', 7, '2026-2027', '06:00:00','08:00:00','07:31:00','11:00:00','12:00:00','12:00:00','13:30:00','12:31:00','17:00:00','18:00:00');

-- ============================================================
-- SEED: Sample students (3 per section, 63 total)
-- ============================================================
INSERT INTO students (lrn, first_name, middle_name, last_name, gender, birth_date, section_id, parent_name, parent_contact, parent_email, qr_token) VALUES
-- Kinder Sampaguita (section 1)
('100000000001','Juan','Cruz','Dela Cruz','Male','2019-03-15',1,'Maria Dela Cruz','09171234561','parent01@email.com','STU-100000000001-AA1111'),
('100000000002','Ana','Reyes','Santos','Female','2019-06-20',1,'Pedro Santos','09171234562','parent02@email.com','STU-100000000002-AA2222'),
('100000000003','Luis','Gomez','Garcia','Male','2019-01-10',1,'Rosa Garcia','09171234563','parent03@email.com','STU-100000000003-AA3333'),
-- Kinder Rosal (section 2)
('100000000004','Sofia','Lim','Torres','Female','2019-09-05',2,'Luis Torres','09171234564','parent04@email.com','STU-100000000004-AA4444'),
('100000000005','Carlos','Bautista','Villanueva','Male','2019-04-22',2,'Carmen Villanueva','09171234565','parent05@email.com','STU-100000000005-AA5555'),
('100000000006','Isabella','Cruz','Mendoza','Female','2019-07-14',2,'Roberto Mendoza','09171234566','parent06@email.com','STU-100000000006-AA6666'),
-- Kinder Camia (section 3)
('100000000007','Rafael','Santos','Flores','Male','2019-02-28',3,'Elena Flores','09171234567','parent07@email.com','STU-100000000007-AA7777'),
('100000000008','Gabrielle','Reyes','Castro','Female','2019-11-03',3,'Antonio Castro','09171234568','parent08@email.com','STU-100000000008-AA8888'),
('100000000009','Marco','Dela Cruz','Ramos','Male','2019-08-19',3,'Patricia Ramos','09171234569','parent09@email.com','STU-100000000009-AA9999'),
-- Grade 1 Mabini (section 4)
('100000000010','Camille','Garcia','Navarro','Female','2018-05-07',4,'Fernando Navarro','09171234570','parent10@email.com','STU-100000000010-BB1111'),
('100000000011','Diego','Santos','Aquino','Male','2018-03-12',4,'Luz Aquino','09171234571','parent11@email.com','STU-100000000011-BB2222'),
('100000000012','Bianca','Cruz','Pascual','Female','2018-07-25',4,'Mario Pascual','09171234572','parent12@email.com','STU-100000000012-BB3333'),
-- Grade 1 Rizal (section 5)
('100000000013','Miguel','Reyes','Fernandez','Male','2018-01-18',5,'Clara Fernandez','09171234573','parent13@email.com','STU-100000000013-BB4444'),
('100000000014','Sophia','Lim','Castillo','Female','2018-09-30',5,'Jose Castillo','09171234574','parent14@email.com','STU-100000000014-BB5555'),
('100000000015','Gabriel','Torres','Miranda','Male','2018-06-14',5,'Ana Miranda','09171234575','parent15@email.com','STU-100000000015-BB6666'),
-- Grade 1 Bonifacio (section 6)
('100000000016','Mia','Villanueva','Salazar','Female','2018-11-22',6,'Ramon Salazar','09171234576','parent16@email.com','STU-100000000016-BB7777'),
('100000000017','Nathan','Mendoza','Reyes','Male','2018-04-08',6,'Gloria Reyes','09171234577','parent17@email.com','STU-100000000017-BB8888'),
('100000000018','Chloe','Flores','Dizon','Female','2018-12-01',6,'Victor Dizon','09171234578','parent18@email.com','STU-100000000018-BB9999'),
-- Grade 2 Magayon (section 7)
('100000000019','Ethan','Castro','Ocampo','Male','2017-02-14',7,'Nora Ocampo','09171234579','parent19@email.com','STU-100000000019-CC1111'),
('100000000020','Emma','Ramos','Santiago','Female','2017-08-27',7,'Ben Santiago','09171234580','parent20@email.com','STU-100000000020-CC2222'),
('100000000021','Liam','Navarro','Dela Rosa','Male','2017-05-19',7,'Celia Dela Rosa','09171234581','parent21@email.com','STU-100000000021-CC3333'),
-- Grade 2 Mayon (section 8)
('100000000022','Olivia','Aquino','Reyes','Female','2017-10-03',8,'Dante Reyes','09171234582','parent22@email.com','STU-100000000022-CC4444'),
('100000000023','Noah','Pascual','Cruz','Male','2017-03-31',8,'Mercy Cruz','09171234583','parent23@email.com','STU-100000000023-CC5555'),
('100000000024','Ava','Fernandez','Lopez','Female','2017-07-16',8,'Raul Lopez','09171234584','parent24@email.com','STU-100000000024-CC6666'),
-- Grade 2 Pulag (section 9)
('100000000025','James','Castillo','Villafuerte','Male','2017-01-09',9,'Lilia Villafuerte','09171234585','parent25@email.com','STU-100000000025-CC7777'),
('100000000026','Charlotte','Miranda','Santos','Female','2017-11-24',9,'Ernesto Santos','09171234586','parent26@email.com','STU-100000000026-CC8888'),
('100000000027','Benjamin','Salazar','Hernandez','Male','2017-06-07',9,'Alma Hernandez','09171234587','parent27@email.com','STU-100000000027-CC9999'),
-- Grade 3 Aguinaldo (section 10)
('100000000028','Amelia','Reyes','Bautista','Female','2016-04-13',10,'Felix Bautista','09171234588','parent28@email.com','STU-100000000028-DD1111'),
('100000000029','Lucas','Dizon','Dela Cruz','Male','2016-09-28',10,'Delia Dela Cruz','09171234589','parent29@email.com','STU-100000000029-DD2222'),
('100000000030','Harper','Ocampo','Reyes','Female','2016-02-17',10,'Carlos Reyes','09171234590','parent30@email.com','STU-100000000030-DD3333'),
-- Grade 3 Luna (section 11)
('100000000031','Elijah','Santiago','Manalo','Male','2016-07-04',11,'Susan Manalo','09171234591','parent31@email.com','STU-100000000031-DD4444'),
('100000000032','Abigail','Dela Rosa','Aguilar','Female','2016-12-19',11,'Tomas Aguilar','09171234592','parent32@email.com','STU-100000000032-DD5555'),
('100000000033','Alexander','Reyes','Buenaventura','Male','2016-05-26',11,'Lily Buenaventura','09171234593','parent33@email.com','STU-100000000033-DD6666'),
-- Grade 3 Silang (section 12)
('100000000034','Emily','Cruz','Domingo','Female','2016-08-11',12,'Ricky Domingo','09171234594','parent34@email.com','STU-100000000034-DD7777'),
('100000000035','Daniel','Lopez','Pascua','Male','2016-03-23',12,'Nena Pascua','09171234595','parent35@email.com','STU-100000000035-DD8888'),
('100000000036','Sofia','Villafuerte','Reyes','Female','2016-10-06',12,'Arnold Reyes','09171234596','parent36@email.com','STU-100000000036-DD9999'),
-- Grade 4 Lakandula (section 13)
('100000000037','Matthew','Santos','Magsaysay','Male','2015-01-14',13,'Linda Magsaysay','09171234597','parent37@email.com','STU-100000000037-EE1111'),
('100000000038','Avery','Hernandez','Quezon','Female','2015-06-29',13,'Frank Quezon','09171234598','parent38@email.com','STU-100000000038-EE2222'),
('100000000039','Joseph','Bautista','Roxas','Male','2015-11-08',13,'Nita Roxas','09171234599','parent39@email.com','STU-100000000039-EE3333'),
-- Grade 4 Lapu-Lapu (section 14)
('100000000040','Elizabeth','Dela Cruz','Osmeña','Female','2015-04-17',14,'Albert Osmeña','09171234600','parent40@email.com','STU-100000000040-EE4444'),
('100000000041','David','Reyes','Laurel','Male','2015-09-02',14,'Virginia Laurel','09171234601','parent41@email.com','STU-100000000041-EE5555'),
('100000000042','Penelope','Manalo','Quirino','Female','2015-02-21',14,'Rodolfo Quirino','09171234602','parent42@email.com','STU-100000000042-EE6666'),
-- Grade 4 Legaspi (section 15)
('100000000043','Samuel','Aguilar','Macaraeg','Male','2015-07-30',15,'Perla Macaraeg','09171234603','parent43@email.com','STU-100000000043-EE7777'),
('100000000044','Victoria','Buenaventura','Santos','Female','2015-12-15',15,'Benny Santos','09171234604','parent44@email.com','STU-100000000044-EE8888'),
('100000000045','Jack','Domingo','Cruz','Male','2015-05-10',15,'Tessie Cruz','09171234605','parent45@email.com','STU-100000000045-EE9999'),
-- Grade 5 Bathala (section 16)
('100000000046','Scarlett','Pascua','Reyes','Female','2014-03-07',16,'Edwin Reyes','09171234606','parent46@email.com','STU-100000000046-FF1111'),
('100000000047','Henry','Magsaysay','Dela Cruz','Male','2014-08-22',16,'Lorna Dela Cruz','09171234607','parent47@email.com','STU-100000000047-FF2222'),
('100000000048','Grace','Quezon','Santos','Female','2014-01-31',16,'Wilfredo Santos','09171234608','parent48@email.com','STU-100000000048-FF3333'),
-- Grade 5 Diwata (section 17)
('100000000049','Leo','Roxas','Garcia','Male','2014-06-16',17,'Ester Garcia','09171234609','parent49@email.com','STU-100000000049-FF4444'),
('100000000050','Zoey','Osmeña','Reyes','Female','2014-11-01',17,'Alfredo Reyes','09171234610','parent50@email.com','STU-100000000050-FF5555'),
('100000000051','Owen','Laurel','Bautista','Male','2014-04-20',17,'Connie Bautista','09171234611','parent51@email.com','STU-100000000051-FF6666'),
-- Grade 5 Anito (section 18)
('100000000052','Lily','Quirino','Mendoza','Female','2014-09-05',18,'Nestor Mendoza','09171234612','parent52@email.com','STU-100000000052-FF7777'),
('100000000053','Ryan','Macaraeg','Flores','Male','2014-02-24',18,'Irma Flores','09171234613','parent53@email.com','STU-100000000053-FF8888'),
('100000000054','Nora','Santos','Castro','Female','2014-07-13',18,'Domingo Castro','09171234614','parent54@email.com','STU-100000000054-FF9999'),
-- Grade 6 Kalikasan (section 19)
('100000000055','Isaac','Cruz','Ramos','Male','2013-05-28',19,'Milagros Ramos','09171234615','parent55@email.com','STU-100000000055-GG1111'),
('100000000056','Hannah','Reyes','Navarro','Female','2013-10-13',19,'Ernesto Navarro','09171234616','parent56@email.com','STU-100000000056-GG2222'),
('100000000057','Elias','Santos','Aquino','Male','2013-03-02',19,'Norma Aquino','09171234617','parent57@email.com','STU-100000000057-GG3333'),
-- Grade 6 Kalikayan (section 20)
('100000000058','Stella','Garcia','Pascual','Female','2013-08-17',20,'Romeo Pascual','09171234618','parent58@email.com','STU-100000000058-GG4444'),
('100000000059','Adrian','Reyes','Fernandez','Male','2013-01-06',20,'Leticia Fernandez','09171234619','parent59@email.com','STU-100000000059-GG5555'),
('100000000060','Clara','Bautista','Castillo','Female','2013-06-25',20,'Porfirio Castillo','09171234620','parent60@email.com','STU-100000000060-GG6666'),
-- Grade 6 Kalayaan (section 21)
('100000000061','Victor','Mendoza','Miranda','Male','2013-11-10',21,'Salome Miranda','09171234621','parent61@email.com','STU-100000000061-GG7777'),
('100000000062','Aurora','Flores','Salazar','Female','2013-04-29',21,'Gregorio Salazar','09171234622','parent62@email.com','STU-100000000062-GG8888'),
('100000000063','Felix','Castro','Dizon','Male','2013-09-18',21,'Rowena Dizon','09171234623','parent63@email.com','STU-100000000063-GG9999');

-- ============================================================
-- SEED: School calendar (sample holidays 2026-2027)
-- ============================================================
INSERT INTO school_calendar (date, title, type, description, created_by) VALUES
('2026-08-21', 'Ninoy Aquino Day',          'holiday',      'National Holiday',                    1),
('2026-08-31', 'National Heroes Day',        'holiday',      'National Holiday',                    1),
('2026-11-01', 'All Saints Day',             'holiday',      'National Holiday',                    1),
('2026-11-02', 'All Souls Day',              'holiday',      'National Holiday',                    1),
('2026-11-30', 'Bonifacio Day',              'holiday',      'National Holiday',                    1),
('2026-12-08', 'Feast of Immaculate Conception','holiday',   'National Holiday',                    1),
('2026-12-25', 'Christmas Day',              'holiday',      'National Holiday',                    1),
('2026-12-30', 'Rizal Day',                  'holiday',      'National Holiday',                    1),
('2026-12-21', 'Christmas Break Start',      'no_class',     'Christmas vacation begins',           1),
('2026-12-22', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-23', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-24', 'Christmas Eve',              'no_class',     'Christmas vacation',                  1),
('2026-12-26', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-27', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-28', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-29', 'Christmas Break',            'no_class',     'Christmas vacation',                  1),
('2026-12-31', 'New Year''s Eve',            'no_class',     'Christmas vacation',                  1),
('2027-01-01', 'New Year''s Day',            'holiday',      'National Holiday',                    1),
('2027-02-05', 'Chinese New Year',           'holiday',      'Special Non-Working Holiday',         1),
('2027-02-25', 'EDSA People Power',          'holiday',      'National Holiday',                    1),
('2027-04-01', 'Holy Thursday',              'holiday',      'National Holiday',                    1),
('2027-04-02', 'Good Friday',                'holiday',      'National Holiday',                    1),
('2027-04-03', 'Black Saturday',             'holiday',      'National Holiday',                    1),
('2027-04-09', 'Araw ng Kagitingan',         'holiday',      'National Holiday',                    1),
('2027-05-01', 'Labor Day',                  'holiday',      'National Holiday',                    1),
('2027-06-12', 'Independence Day',           'holiday',      'National Holiday',                    1);

-- ============================================================
-- SEED: System settings
-- ============================================================
INSERT INTO system_settings (setting_key, setting_value) VALUES
('school_name',             'San Pablo City Central School'),
('school_address',          'San Pablo City, Laguna'),
('school_year',             '2026-2027'),
('grade_levels',            'Kinder,Grade 1,Grade 2,Grade 3,Grade 4,Grade 5,Grade 6'),

-- UniSMS
('unisms_api_key',          ''),
('unisms_sender_id',        'UnisoftSMS'),

-- SMS templates (4 events + absence)
('sms_am_arrival_template',
 'Hello Ma''am/Sir, your child {student_name} arrived at SPCCS this morning at {time}. Thank you.'),
('sms_am_departure_template',
 'Hello Ma''am/Sir, your child {student_name} left SPCCS this morning at {time}. Thank you.'),
('sms_pm_arrival_template',
 'Hello Ma''am/Sir, your child {student_name} arrived at SPCCS this afternoon at {time}. Thank you.'),
('sms_pm_departure_template',
 'Hello Ma''am/Sir, your child {student_name} left SPCCS this afternoon at {time}. Safe travels.'),
('sms_absence_template',
 'Hello Ma''am/Sir, your child {student_name} was absent from SPCCS on {date}. Please contact the school if needed.'),

-- Email (Gmail SMTP)
('mail_host',               'smtp.gmail.com'),
('mail_port',               '587'),
('mail_username',           ''),
('mail_password',           ''),
('mail_from_name',          'SPCCS Attendance System'),
('mail_from_email',         ''),
('email_notifications',     '1');

-- ============================================================
-- INDEXES
-- ============================================================
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

-- ============================================================
-- VERIFY
-- Run this after import to check row counts
-- ============================================================
-- SELECT 'users'           AS tbl, COUNT(*) AS rows FROM users
-- UNION SELECT 'sections',          COUNT(*) FROM sections
-- UNION SELECT 'students',          COUNT(*) FROM students
-- UNION SELECT 'attendance',        COUNT(*) FROM attendance
-- UNION SELECT 'school_calendar',   COUNT(*) FROM school_calendar
-- UNION SELECT 'system_settings',   COUNT(*) FROM system_settings;

-- Expected:
-- users          = 8
-- sections       = 21
-- students       = 63
-- attendance     = 0
-- school_calendar= 26
-- system_settings= 17