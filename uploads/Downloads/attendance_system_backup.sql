-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 20, 2026 at 11:26 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

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
  `attendance_type` enum('full_day','partial','absent','holiday') NOT NULL DEFAULT 'absent',
  `remarks` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `date`, `am_in`, `am_out`, `am_status`, `pm_in`, `pm_out`, `pm_status`, `attendance_type`, `remarks`, `recorded_by`, `created_at`, `updated_at`) VALUES
(1, 32, '2026-09-02', '13:01:18', NULL, 'late', NULL, NULL, NULL, 'partial', NULL, 1, '2026-09-02 05:01:18', '2026-09-02 05:01:18'),
(2, 8, '2026-09-02', NULL, NULL, 'present', NULL, NULL, NULL, 'absent', '', 1, '2026-09-02 11:39:30', '2026-09-02 11:39:30'),
(3, 7, '2026-09-02', NULL, NULL, 'present', NULL, NULL, NULL, 'absent', '', 1, '2026-09-02 11:39:30', '2026-09-02 11:39:30'),
(4, 9, '2026-09-02', NULL, NULL, 'present', NULL, NULL, NULL, 'absent', '', 1, '2026-09-02 11:39:30', '2026-09-02 11:39:30');

-- --------------------------------------------------------

--
-- Table structure for table `school_calendar`
--

CREATE TABLE `school_calendar` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `title` varchar(100) NOT NULL,
  `type` enum('holiday','no_class','special_event','school_day') NOT NULL DEFAULT 'school_day',
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `school_calendar`
--

INSERT INTO `school_calendar` (`id`, `date`, `title`, `type`, `description`, `created_by`, `created_at`) VALUES
(1, '2026-08-21', 'Ninoy Aquino Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(2, '2026-08-31', 'National Heroes Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(3, '2026-11-01', 'All Saints Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(4, '2026-11-02', 'All Souls Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(5, '2026-11-30', 'Bonifacio Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(6, '2026-12-08', 'Feast of Immaculate Conception', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(7, '2026-12-25', 'Christmas Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(8, '2026-12-30', 'Rizal Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(9, '2026-12-21', 'Christmas Break Start', 'no_class', 'Christmas vacation begins', 1, '2026-08-27 14:25:17'),
(10, '2026-12-22', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(11, '2026-12-23', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(12, '2026-12-24', 'Christmas Eve', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(13, '2026-12-26', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(14, '2026-12-27', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(15, '2026-12-28', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(16, '2026-12-29', 'Christmas Break', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(17, '2026-12-31', 'New Year\'s Eve', 'no_class', 'Christmas vacation', 1, '2026-08-27 14:25:17'),
(18, '2027-01-01', 'New Year\'s Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(19, '2027-02-05', 'Chinese New Year', 'holiday', 'Special Non-Working Holiday', 1, '2026-08-27 14:25:17'),
(20, '2027-02-25', 'EDSA People Power', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(21, '2027-04-01', 'Holy Thursday', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(22, '2027-04-02', 'Good Friday', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(23, '2027-04-03', 'Black Saturday', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(24, '2027-04-09', 'Araw ng Kagitingan', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(25, '2027-05-01', 'Labor Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(26, '2027-06-12', 'Independence Day', 'holiday', 'National Holiday', 1, '2026-08-27 14:25:17'),
(27, '2026-09-03', 'dd', 'no_class', 'dwdw', 1, '2026-09-03 03:07:55');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

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

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `grade_level`, `schedule_type`, `adviser_id`, `school_year`, `am_in_start`, `am_in_end`, `am_out_start`, `am_out_end`, `pm_in_start`, `pm_in_end`, `pm_out_start`, `pm_out_end`, `am_late_threshold`, `pm_late_threshold`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Kinder - Sampaguita', 'Kinder', 'am_only', 2, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(2, 'Kinder - Rosak', 'Kinder', 'full_day', 7, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-09-03 03:14:50'),
(3, 'Kinder - Camia', 'Kinder', 'am_only', 4, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(4, 'Grade 1 - Mabini', 'Grade 1', 'full_day', 2, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(5, 'Grade 1 - Rizal', 'Grade 1', 'full_day', 3, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(6, 'Grade 1 - Bonifacio', 'Grade 1', 'full_day', 4, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(7, 'Grade 2 - Magayon', 'Grade 2', 'full_day', 5, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(8, 'Grade 2 - Mayon', 'Grade 2', 'full_day', 6, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(9, 'Grade 2 - Pulag', 'Grade 2', 'full_day', 7, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(10, 'Grade 3 - Aguinaldo', 'Grade 3', 'full_day', 2, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(11, 'Grade 3 - Luna', 'Grade 3', 'full_day', 3, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(12, 'Grade 3 - Silang', 'Grade 3', 'full_day', 4, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(13, 'Grade 4 - Lakandula', 'Grade 4', 'full_day', 5, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(14, 'Grade 4 - Lapu-Lapu', 'Grade 4', 'full_day', 6, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(15, 'Grade 4 - Legaspi', 'Grade 4', 'full_day', 7, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(16, 'Grade 5 - Bathala', 'Grade 5', 'full_day', 2, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(17, 'Grade 5 - Diwata', 'Grade 5', 'full_day', 3, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(18, 'Grade 5 - Anito', 'Grade 5', 'full_day', 4, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(19, 'Grade 6 - Kalikasan', 'Grade 6', 'full_day', 5, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(20, 'Grade 6 - Kalikayan', 'Grade 6', 'full_day', 6, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(21, 'Grade 6 - Kalayaan', 'Grade 6', 'full_day', 7, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 1, '2026-08-27 14:21:59', '2026-08-27 14:21:59'),
(22, 'wqe', 'Grade 4', 'full_day', NULL, '2026-2027', '06:00:00', '08:00:00', '11:00:00', '12:00:00', '12:00:00', '13:30:00', '17:00:00', '18:00:00', '07:31:00', '12:31:00', 0, '2026-09-18 10:05:19', '2026-09-18 10:05:44');

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `recipient_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `type` enum('am_arrival','am_departure','pm_arrival','pm_departure','absence') NOT NULL,
  `status` enum('sent','failed','pending') NOT NULL DEFAULT 'pending',
  `api_response` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`id`, `student_id`, `recipient_number`, `message`, `type`, `status`, `api_response`, `sent_at`) VALUES
(1, 32, '09171234592', 'Hello Ma\'am/Sir, your child Abigail Aguilar arrived at SPCCS this morning at 01:01 PM. Thank you.', 'am_arrival', 'failed', 'No API key configured', '2026-09-02 05:01:18'),
(2, 8, '09171234568', 'Hello Ma\'am/Sir, your child Gabrielle Castro was absent from SPCCS on September 2, 2026. Please contact the school if needed.', 'absence', 'failed', 'No API key configured', '2026-09-02 11:39:30'),
(3, 7, '09171234567', 'Hello Ma\'am/Sir, your child Rafael Flores was absent from SPCCS on September 2, 2026. Please contact the school if needed.', 'absence', 'failed', 'No API key configured', '2026-09-02 11:39:30'),
(4, 9, '09171234569', 'Hello Ma\'am/Sir, your child Marco Ramos was absent from SPCCS on September 2, 2026. Please contact the school if needed.', 'absence', 'failed', 'No API key configured', '2026-09-02 11:39:30');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

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

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `lrn`, `first_name`, `middle_name`, `last_name`, `gender`, `birth_date`, `address`, `section_id`, `photo`, `qr_code`, `qr_token`, `parent_name`, `parent_contact`, `parent_email`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '100000000001', 'Juan', 'Cruz', 'Dela Cruz', 'Male', '2019-03-15', NULL, 1, 'default.png', NULL, 'STU-100000000001-AA1111', 'Maria Dela Cruz', '09171234561', 'parent01@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(2, '100000000002', 'Ana', 'Reyes', 'Santos', 'Female', '2019-06-20', NULL, 1, 'default.png', NULL, 'STU-100000000002-AA2222', 'Pedro Santos', '09171234562', 'parent02@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(3, '100000000003', 'Luis', 'Gomez', 'Garcia', 'Male', '2019-01-10', NULL, 1, 'default.png', NULL, 'STU-100000000003-AA3333', 'Rosa Garcia', '09171234563', 'parent03@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(4, '100000000004', 'Sofia', 'Lim', 'Torres', 'Female', '2019-09-05', NULL, 2, 'default.png', NULL, 'STU-100000000004-AA4444', 'Luis Torres', '09171234564', 'parent04@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(5, '100000000005', 'Carlos', 'Bautista', 'Villanueva', 'Male', '2019-04-22', NULL, 2, 'default.png', NULL, 'STU-100000000005-AA5555', 'Carmen Villanueva', '09171234565', 'parent05@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(6, '100000000006', 'Isabella', 'Cruz', 'Mendoza', 'Female', '2019-07-14', NULL, 2, 'default.png', NULL, 'STU-100000000006-AA6666', 'Roberto Mendoza', '09171234566', 'parent06@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(7, '100000000007', 'Rafael', 'Santos', 'Flores', 'Male', '2019-02-28', NULL, 3, 'default.png', NULL, 'STU-100000000007-AA7777', 'Elena Flores', '09171234567', 'parent07@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(8, '100000000008', 'Gabrielle', 'Reyes', 'Castro', 'Female', '2019-11-03', NULL, 3, 'default.png', NULL, 'STU-100000000008-AA8888', 'Antonio Castro', '09171234568', 'parent08@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(9, '100000000009', 'Marco', 'Dela Cruz', 'Ramos', 'Male', '2019-08-19', NULL, 3, 'default.png', NULL, 'STU-100000000009-AA9999', 'Patricia Ramos', '09171234569', 'parent09@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(10, '100000000010', 'Camille', 'Garcia', 'Navarro', 'Female', '2018-05-07', NULL, 4, 'default.png', NULL, 'STU-100000000010-BB1111', 'Fernando Navarro', '09171234570', 'parent10@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(11, '100000000011', 'Diego', 'Santos', 'Aquino', 'Male', '2018-03-12', NULL, 4, 'default.png', NULL, 'STU-100000000011-BB2222', 'Luz Aquino', '09171234571', 'parent11@email.com', 1, '2026-08-27 14:22:46', '2026-09-18 10:16:49'),
(12, '100000000012', 'Bianca', 'Cruz', 'Pascual', 'Female', '2018-07-25', NULL, 4, 'default.png', NULL, 'STU-100000000012-BB3333', 'Mario Pascual', '09171234572', 'parent12@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(13, '100000000013', 'Miguel', 'Reyes', 'Fernandez', 'Male', '2018-01-18', NULL, 5, 'default.png', NULL, 'STU-100000000013-BB4444', 'Clara Fernandez', '09171234573', 'parent13@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(14, '100000000014', 'Sophia', 'Lim', 'Castillo', 'Female', '2018-09-30', NULL, 5, 'default.png', NULL, 'STU-100000000014-BB5555', 'Jose Castillo', '09171234574', 'parent14@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(15, '100000000015', 'Gabriel', 'Torres', 'Miranda', 'Male', '2018-06-14', NULL, 5, 'default.png', NULL, 'STU-100000000015-BB6666', 'Ana Miranda', '09171234575', 'parent15@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(16, '100000000016', 'Mia', 'Villanueva', 'Salazar', 'Female', '2018-11-22', NULL, 6, 'default.png', NULL, 'STU-100000000016-BB7777', 'Ramon Salazar', '09171234576', 'parent16@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(17, '100000000017', 'Nathan', 'Mendoza', 'Reyes', 'Male', '2018-04-08', NULL, 6, 'default.png', NULL, 'STU-100000000017-BB8888', 'Gloria Reyes', '09171234577', 'parent17@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(18, '100000000018', 'Chloe', 'Flores', 'Dizon', 'Female', '2018-12-01', NULL, 6, 'default.png', NULL, 'STU-100000000018-BB9999', 'Victor Dizon', '09171234578', 'parent18@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(19, '100000000019', 'Ethan', 'Castro', 'Ocampo', 'Male', '2017-02-14', NULL, 7, 'default.png', NULL, 'STU-100000000019-CC1111', 'Nora Ocampo', '09171234579', 'parent19@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(20, '100000000020', 'Emma', 'Ramos', 'Santiago', 'Female', '2017-08-27', NULL, 7, 'default.png', NULL, 'STU-100000000020-CC2222', 'Ben Santiago', '09171234580', 'parent20@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(21, '100000000021', 'Liam', 'Navarro', 'Dela Rosa', 'Male', '2017-05-19', NULL, 7, 'default.png', NULL, 'STU-100000000021-CC3333', 'Celia Dela Rosa', '09171234581', 'parent21@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(22, '100000000022', 'Olivia', 'Aquino', 'Reyes', 'Female', '2017-10-03', NULL, 8, 'default.png', NULL, 'STU-100000000022-CC4444', 'Dante Reyes', '09171234582', 'parent22@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(23, '100000000023', 'Noah', 'Pascual', 'Cruz', 'Male', '2017-03-31', NULL, 8, 'default.png', NULL, 'STU-100000000023-CC5555', 'Mercy Cruz', '09171234583', 'parent23@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(24, '100000000024', 'Ava', 'Fernandez', 'Lopez', 'Female', '2017-07-16', NULL, 8, 'default.png', NULL, 'STU-100000000024-CC6666', 'Raul Lopez', '09171234584', 'parent24@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(25, '100000000025', 'James', 'Castillo', 'Villafuerte', 'Male', '2017-01-09', NULL, 9, 'default.png', NULL, 'STU-100000000025-CC7777', 'Lilia Villafuerte', '09171234585', 'parent25@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(26, '100000000026', 'Charlotte', 'Miranda', 'Santos', 'Female', '2017-11-24', NULL, 9, 'default.png', NULL, 'STU-100000000026-CC8888', 'Ernesto Santos', '09171234586', 'parent26@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(27, '100000000027', 'Benjamin', 'Salazar', 'Hernandez', 'Male', '2017-06-07', NULL, 9, 'default.png', NULL, 'STU-100000000027-CC9999', 'Alma Hernandez', '09171234587', 'parent27@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(28, '100000000028', 'Amelia', 'Reyes', 'Bautista', 'Female', '2016-04-13', NULL, 10, 'default.png', NULL, 'STU-100000000028-DD1111', 'Felix Bautista', '09171234588', 'parent28@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(29, '100000000029', 'Lucas', 'Dizon', 'Dela Cruz', 'Male', '2016-09-28', NULL, 10, 'default.png', NULL, 'STU-100000000029-DD2222', 'Delia Dela Cruz', '09171234589', 'parent29@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(30, '100000000030', 'Harper', 'Ocampo', 'Reyes', 'Female', '2016-02-17', NULL, 10, 'default.png', NULL, 'STU-100000000030-DD3333', 'Carlos Reyes', '09171234590', 'parent30@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(31, '100000000031', 'Elijah', 'Santiago', 'Manalo', 'Male', '2016-07-04', NULL, 11, 'default.png', NULL, 'STU-100000000031-DD4444', 'Susan Manalo', '09171234591', 'parent31@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(32, '100000000032', 'Abigaill', 'Dela Rosa', 'Aguilar', 'Female', '2016-12-19', '', 11, 'default.png', NULL, 'STU-100000000032-DD5555', 'Tomas Aguilar', '09171234592', 'parent32@email.com', 1, '2026-08-27 14:22:46', '2026-09-18 10:20:21'),
(33, '100000000033', 'Alexander', 'Reyes', 'Buenaventura', 'Male', '2016-05-26', NULL, 11, 'default.png', NULL, 'STU-100000000033-DD6666', 'Lily Buenaventura', '09171234593', 'parent33@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(34, '100000000034', 'Emily', 'Cruz', 'Domingo', 'Female', '2016-08-11', NULL, 12, 'default.png', NULL, 'STU-100000000034-DD7777', 'Ricky Domingo', '09171234594', 'parent34@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(35, '100000000035', 'Daniel', 'Lopez', 'Pascua', 'Male', '2016-03-23', NULL, 12, 'default.png', NULL, 'STU-100000000035-DD8888', 'Nena Pascua', '09171234595', 'parent35@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(36, '100000000036', 'Sofia', 'Villafuerte', 'Reyes', 'Female', '2016-10-06', NULL, 12, 'default.png', NULL, 'STU-100000000036-DD9999', 'Arnold Reyes', '09171234596', 'parent36@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(37, '100000000037', 'Matthew', 'Santos', 'Magsaysay', 'Male', '2015-01-14', NULL, 13, 'default.png', NULL, 'STU-100000000037-EE1111', 'Linda Magsaysay', '09171234597', 'parent37@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(38, '100000000038', 'Avery', 'Hernandez', 'Quezon', 'Female', '2015-06-29', NULL, 13, 'default.png', NULL, 'STU-100000000038-EE2222', 'Frank Quezon', '09171234598', 'parent38@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(39, '100000000039', 'Joseph', 'Bautista', 'Roxas', 'Male', '2015-11-08', NULL, 13, 'default.png', NULL, 'STU-100000000039-EE3333', 'Nita Roxas', '09171234599', 'parent39@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(40, '100000000040', 'Elizabeth', 'Dela Cruz', 'Osmeña', 'Female', '2015-04-17', NULL, 14, 'default.png', NULL, 'STU-100000000040-EE4444', 'Albert Osmeña', '09171234600', 'parent40@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(41, '100000000041', 'David', 'Reyes', 'Laurel', 'Male', '2015-09-02', NULL, 14, 'default.png', NULL, 'STU-100000000041-EE5555', 'Virginia Laurel', '09171234601', 'parent41@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(42, '100000000042', 'Penelope', 'Manalo', 'Quirino', 'Female', '2015-02-21', NULL, 14, 'default.png', NULL, 'STU-100000000042-EE6666', 'Rodolfo Quirino', '09171234602', 'parent42@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(43, '100000000043', 'Samuel', 'Aguilar', 'Macaraeg', 'Male', '2015-07-30', NULL, 15, 'default.png', NULL, 'STU-100000000043-EE7777', 'Perla Macaraeg', '09171234603', 'parent43@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(44, '100000000044', 'Victoria', 'Buenaventura', 'Santos', 'Female', '2015-12-15', NULL, 15, 'default.png', NULL, 'STU-100000000044-EE8888', 'Benny Santos', '09171234604', 'parent44@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(45, '100000000045', 'Jack', 'Domingo', 'Cruz', 'Male', '2015-05-10', NULL, 15, 'default.png', NULL, 'STU-100000000045-EE9999', 'Tessie Cruz', '09171234605', 'parent45@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(46, '100000000046', 'Scarlett', 'Pascua', 'Reyes', 'Female', '2014-03-07', NULL, 16, 'default.png', NULL, 'STU-100000000046-FF1111', 'Edwin Reyes', '09171234606', 'parent46@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(47, '100000000047', 'Henry', 'Magsaysay', 'Dela Cruz', 'Male', '2014-08-22', NULL, 16, 'default.png', NULL, 'STU-100000000047-FF2222', 'Lorna Dela Cruz', '09171234607', 'parent47@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(48, '100000000048', 'Grace', 'Quezon', 'Santos', 'Female', '2014-01-31', NULL, 16, 'default.png', NULL, 'STU-100000000048-FF3333', 'Wilfredo Santos', '09171234608', 'parent48@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(49, '100000000049', 'Leo', 'Roxas', 'Garcia', 'Male', '2014-06-16', NULL, 17, 'default.png', NULL, 'STU-100000000049-FF4444', 'Ester Garcia', '09171234609', 'parent49@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(50, '100000000050', 'Zoey', 'Osmeña', 'Reyes', 'Female', '2014-11-01', NULL, 17, 'default.png', NULL, 'STU-100000000050-FF5555', 'Alfredo Reyes', '09171234610', 'parent50@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(51, '100000000051', 'Owen', 'Laurel', 'Bautista', 'Male', '2014-04-20', NULL, 17, 'default.png', NULL, 'STU-100000000051-FF6666', 'Connie Bautista', '09171234611', 'parent51@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(52, '100000000052', 'Lily', 'Quirino', 'Mendoza', 'Female', '2014-09-05', NULL, 18, 'default.png', NULL, 'STU-100000000052-FF7777', 'Nestor Mendoza', '09171234612', 'parent52@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(53, '100000000053', 'Ryan', 'Macaraeg', 'Flores', 'Male', '2014-02-24', NULL, 18, 'default.png', NULL, 'STU-100000000053-FF8888', 'Irma Flores', '09171234613', 'parent53@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(54, '100000000054', 'Nora', 'Santos', 'Castro', 'Female', '2014-07-13', NULL, 18, 'default.png', NULL, 'STU-100000000054-FF9999', 'Domingo Castro', '09171234614', 'parent54@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(55, '100000000055', 'Isaac', 'Cruz', 'Ramos', 'Male', '2013-05-28', NULL, 19, 'default.png', NULL, 'STU-100000000055-GG1111', 'Milagros Ramos', '09171234615', 'parent55@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(56, '100000000056', 'Hannah', 'Reyes', 'Navarro', 'Female', '2013-10-13', NULL, 19, 'default.png', NULL, 'STU-100000000056-GG2222', 'Ernesto Navarro', '09171234616', 'parent56@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(57, '100000000057', 'Elias', 'Santos', 'Aquino', 'Male', '2013-03-02', NULL, 19, 'default.png', NULL, 'STU-100000000057-GG3333', 'Norma Aquino', '09171234617', 'parent57@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(58, '100000000058', 'Stella', 'Garcia', 'Pascual', 'Female', '2013-08-17', NULL, 20, 'default.png', NULL, 'STU-100000000058-GG4444', 'Romeo Pascual', '09171234618', 'parent58@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(59, '100000000059', 'Adrian', 'Reyes', 'Fernandez', 'Male', '2013-01-06', NULL, 20, 'default.png', NULL, 'STU-100000000059-GG5555', 'Leticia Fernandez', '09171234619', 'parent59@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(60, '100000000060', 'Clara', 'Bautista', 'Castillo', 'Female', '2013-06-25', NULL, 20, 'default.png', NULL, 'STU-100000000060-GG6666', 'Porfirio Castillo', '09171234620', 'parent60@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(61, '100000000061', 'Victor', 'Mendoza', 'Miranda', 'Male', '2013-11-10', NULL, 21, 'default.png', NULL, 'STU-100000000061-GG7777', 'Salome Miranda', '09171234621', 'parent61@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(62, '100000000062', 'Aurora', 'Flores', 'Salazar', 'Female', '2013-04-29', NULL, 21, 'default.png', NULL, 'STU-100000000062-GG8888', 'Gregorio Salazar', '09171234622', 'parent62@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46'),
(63, '100000000063', 'Felix', 'Castro', 'Dizon', 'Male', '2013-09-18', NULL, 21, 'default.png', NULL, 'STU-100000000063-GG9999', 'Rowena Dizon', '09171234623', 'parent63@email.com', 1, '2026-08-27 14:22:46', '2026-08-27 14:22:46');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'school_name', 'San Pablo City Central School', '2026-08-27 14:25:33'),
(2, 'school_address', 'San Pablo City, Laguna', '2026-08-27 14:25:33'),
(3, 'school_year', '2026-2027', '2026-08-27 14:25:33'),
(4, 'grade_levels', 'Kinder,Grade 1,Grade 2,Grade 3,Grade 4,Grade 5,Grade 6', '2026-08-27 14:25:33'),
(5, 'unisms_api_key', '', '2026-08-27 14:25:33'),
(6, 'unisms_sender_id', 'UnisoftSMS', '2026-08-27 14:25:33'),
(7, 'sms_am_arrival_template', 'Hello Ma\'am/Sir, your child {student_name} arrived at SPCCS this morning at {time}. Thank you.', '2026-08-27 14:25:33'),
(8, 'sms_am_departure_template', 'Hello Ma\'am/Sir, your child {student_name} left SPCCS this morning at {time}. Thank you.', '2026-08-27 14:25:33'),
(9, 'sms_pm_arrival_template', 'Hello Ma\'am/Sir, your child {student_name} arrived at SPCCS this afternoon at {time}. Thank you.', '2026-08-27 14:25:33'),
(10, 'sms_pm_departure_template', 'Hello Ma\'am/Sir, your child {student_name} left SPCCS this afternoon at {time}. Safe travels.', '2026-08-27 14:25:33'),
(11, 'sms_absence_template', 'Hello Ma\'am/Sir, your child {student_name} was absent from SPCCS on {date}. Please contact the school if needed.', '2026-08-27 14:25:33'),
(12, 'mail_host', 'smtp.gmail.com', '2026-08-27 14:25:33'),
(13, 'mail_port', '587', '2026-08-27 14:25:33'),
(14, 'mail_username', '', '2026-08-27 14:25:33'),
(15, 'mail_password', '', '2026-08-27 14:25:33'),
(16, 'mail_from_name', 'SPCCS Attendance System', '2026-08-27 14:25:33'),
(17, 'mail_from_email', '', '2026-08-27 14:25:33'),
(18, 'email_notifications', '1', '2026-08-27 14:25:33'),
(22, 'grade_level', 'Kindergarten', '2026-09-18 10:33:14'),
(23, 'time_in_start', '07:00', '2026-09-18 10:33:14'),
(24, 'time_in_end', '08:00', '2026-09-18 10:33:14'),
(25, 'late_threshold', '07:31', '2026-09-18 10:33:14'),
(26, 'time_out_start', '11:00', '2026-09-18 10:33:14'),
(27, 'time_out_end', '12:00', '2026-09-18 10:33:14');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','teacher') NOT NULL DEFAULT 'teacher',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$12$FweEHtJtKYVaUW2nF1s0VuGkpsPi91k6.zcq5N4vJQAkNrCBFlLM.', 'System Administrator', 'admin@spccs.edu.ph', 'admin', 1, '2026-08-27 14:21:13', '2026-08-27 14:37:29'),
(2, 'teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos', 'msantos@spccs.edu.ph', 'teacher', 1, '2026-08-27 14:21:13', '2026-08-27 14:21:13'),
(3, 'teacher2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jose Reyes', 'jreyes@spccs.edu.ph', 'teacher', 1, '2026-08-27 14:21:13', '2026-08-27 14:21:13'),
(4, 'teacher3', '$2y$10$YQOMt5MqutN2w3wxqmFTs.S35z3L56Z9UoYnc6.BuCvN7AF9Br/pG', 'Ana Cruz', 'acruz@spccs.edu.ph', 'teacher', 2, '2026-08-27 14:21:13', '2026-09-18 10:50:56'),
(5, 'teacher4', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pedro Dela Cruz', 'pdelacruz@spccs.edu.ph', 'teacher', 1, '2026-08-27 14:21:13', '2026-08-27 14:21:13'),
(6, 'teacher5', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rosa Bautista', 'rbautista@spccs.edu.ph', 'teacher', 1, '2026-08-27 14:21:13', '2026-08-27 14:21:13'),
(7, 'teacher6', '$2y$10$XoFVxeGpFQ8esmsUSlsh4.e0EauN0YLq53uuvMIhrHg6SJDegurn2', 'Carlos Mendoza', 'cmendoza@spccs.edu.ph', 'teacher', 1, '2026-08-27 14:21:13', '2026-09-18 10:52:48'),
(8, 'teacher7', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Elena Torres', 'etorres@spccs.edu.ph', 'teacher', 2, '2026-08-27 14:21:13', '2026-09-18 10:47:31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_attendance_date` (`date`),
  ADD KEY `idx_attendance_student` (`student_id`),
  ADD KEY `idx_attendance_type` (`attendance_type`),
  ADD KEY `idx_attendance_am_status` (`am_status`),
  ADD KEY `idx_attendance_pm_status` (`pm_status`);

--
-- Indexes for table `school_calendar`
--
ALTER TABLE `school_calendar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_calendar_date` (`date`),
  ADD KEY `idx_calendar_type` (`type`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `adviser_id` (`adviser_id`),
  ADD KEY `idx_sections_grade` (`grade_level`),
  ADD KEY `idx_sections_schedule` (`schedule_type`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sms_logs_student` (`student_id`),
  ADD KEY `idx_sms_logs_type` (`type`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lrn` (`lrn`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `idx_students_section` (`section_id`),
  ADD KEY `idx_students_lrn` (`lrn`),
  ADD KEY `idx_students_qr_token` (`qr_token`),
  ADD KEY `idx_students_active` (`is_active`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `school_calendar`
--
ALTER TABLE `school_calendar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `school_calendar`
--
ALTER TABLE `school_calendar`
  ADD CONSTRAINT `school_calendar_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
