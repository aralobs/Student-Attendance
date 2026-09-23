-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 27, 2026 at 01:05 AM
-- Server version: 9.7.0
-- PHP Version: 8.5.6

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
  `id` int NOT NULL,
  `student_id` int NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` enum('present','absent','late','excused') DEFAULT 'present',
  `remarks` text,
  `recorded_by` int DEFAULT NULL,
  `scan_type` enum('qr','manual') DEFAULT 'qr',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `date`, `time_in`, `time_out`, `status`, `remarks`, `recorded_by`, `scan_type`, `created_at`, `updated_at`) VALUES
(1, 1, '2026-05-16', '07:15:00', NULL, 'present', NULL, 2, 'qr', '2026-05-16 04:58:40', '2026-05-16 04:58:40'),
(2, 2, '2026-05-16', '07:45:00', NULL, 'late', NULL, 2, 'qr', '2026-05-16 04:58:40', '2026-05-16 04:58:40'),
(3, 3, '2026-05-16', NULL, NULL, 'absent', NULL, 3, 'manual', '2026-05-16 04:58:40', '2026-05-16 04:58:40'),
(4, 4, '2026-05-16', '07:10:00', '11:30:00', 'present', NULL, 3, 'qr', '2026-05-16 04:58:40', '2026-05-16 04:58:40'),
(5, 5, '2026-05-16', '07:20:00', NULL, 'present', NULL, 2, 'qr', '2026-05-16 04:58:40', '2026-05-16 04:58:40'),
(6, 1, '2026-05-18', '09:01:28', NULL, 'late', NULL, 1, 'qr', '2026-05-18 09:01:28', '2026-05-18 09:01:28'),
(7, 6, '2026-07-26', '14:36:06', '14:38:31', 'late', NULL, 1, 'qr', '2026-07-26 14:36:06', '2026-07-26 14:38:31'),
(8, 6, '2026-07-27', '07:38:48', NULL, 'late', '', 1, 'manual', '2026-07-26 23:38:48', '2026-07-27 00:08:33'),
(9, 1, '2026-07-27', '07:44:15', NULL, 'late', NULL, 1, 'qr', '2026-07-26 23:44:15', '2026-07-26 23:44:15'),
(10, 3, '2026-07-27', '08:04:30', NULL, 'late', '', 1, 'manual', '2026-07-27 00:04:30', '2026-07-27 00:08:43'),
(11, 4, '2026-07-27', NULL, NULL, 'absent', '', 1, 'manual', '2026-07-27 00:08:43', '2026-07-27 00:08:43'),
(12, 5, '2026-07-27', '08:50:37', NULL, 'late', NULL, 1, 'qr', '2026-07-27 00:50:37', '2026-07-27 00:50:37');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `adviser_id` int DEFAULT NULL,
  `school_year` varchar(20) DEFAULT '2024-2025',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `adviser_id`, `school_year`, `is_active`, `created_at`) VALUES
(1, 'Sampaguita', 2, '2024-2025', 1, '2026-05-16 04:58:39'),
(2, 'Rosal', 3, '2024-2025', 1, '2026-05-16 04:58:39'),
(3, 'Camia', NULL, '2024-2025', 1, '2026-05-16 04:58:39');

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int NOT NULL,
  `student_id` int DEFAULT NULL,
  `recipient_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `type` enum('arrival','departure','absence') NOT NULL,
  `status` enum('sent','failed','pending') DEFAULT 'pending',
  `api_response` text,
  `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`id`, `student_id`, `recipient_number`, `message`, `type`, `status`, `api_response`, `sent_at`) VALUES
(1, 1, '09171234567', 'Good day! Juan Dela Cruz has arrived at school at 09:01 AM. - SPCCS Kindergarten', 'arrival', 'failed', 'No API key configured', '2026-05-18 09:01:28'),
(2, 6, '+639955425054', 'Good day! Miles Marzano has arrived at school at 02:36 PM. - SPCCS Kindergarten', 'arrival', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Miles Marzano has arrived at school at 02:36 PM. - SPCCS Kindergarten\",\"created\":\"2026-07-26T14:36:07Z\",\"sender_id\":\"UnisoftSMS\",\"reference_id\":\"msg_6e59925b-bef9-42bb-90ad-78f0628f9350\",\"fail_reason\":null,\"recipient\":\"+639955425054\"}}', '2026-07-26 14:36:07'),
(3, 6, '+639955425054', 'Good day! Miles Marzano has left school at 02:38 PM. - SPCCS Kindergarten', 'departure', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Miles Marzano has left school at 02:38 PM. - SPCCS Kindergarten\",\"created\":\"2026-07-26T14:38:31Z\",\"sender_id\":\"UnisoftSMS\",\"reference_id\":\"msg_c41dad4d-72a2-464a-bf16-e48f3e35aadb\",\"fail_reason\":null,\"recipient\":\"+639955425054\"}}', '2026-07-26 14:38:32'),
(4, 6, '+639955425054', 'Good day! Miles Marzano has arrived at school at 07:38 AM. - SPCCS Kindergarten', 'arrival', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Miles Marzano has arrived at school at 07:38 AM. - SPCCS Kindergarten\",\"created\":\"2026-07-26T23:38:47Z\",\"sender_id\":\"UnisoftSMS\",\"reference_id\":\"msg_8655d4f4-74e6-4dea-99cd-65fa3a0d181f\",\"fail_reason\":null,\"recipient\":\"+639955425054\"}}', '2026-07-26 23:38:48'),
(5, 1, '+639955425054', 'Good day! Juan Dela Cruz has arrived at school at 07:44 AM. - SPCCS Kindergarten', 'arrival', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Juan Dela Cruz has arrived at school at 07:44 AM. - SPCCS Kindergarten\",\"created\":\"2026-07-26T23:44:14Z\",\"sender_id\":\"UnisoftSMS\",\"reference_id\":\"msg_41cd6b1c-3d34-4776-bf4c-4dd9986eadb5\",\"fail_reason\":null,\"recipient\":\"+639955425054\"}}', '2026-07-26 23:44:15'),
(6, 3, '+639193456789', 'Good day! Miguel Garcia has arrived at school at 08:04 AM. - SPCCS Kindergarten', 'arrival', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Miguel Garcia has arrived at school at 08:04 AM. - SPCCS Kindergarten\",\"created\":\"2026-07-27T00:04:29Z\",\"sender_id\":\"Unisoft\",\"reference_id\":\"msg_13c42630-620d-4a57-8779-f7b20ec45242\",\"fail_reason\":null,\"recipient\":\"+639193456789\"}}', '2026-07-27 00:04:30'),
(7, 4, '+639204567890', 'Good day! Sofia Torres was marked ABSENT today July 27, 2026. Please inform the school. - SPCCS Kindergarten', 'absence', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Sofia Torres was marked ABSENT today July 27, 2026. Please inform the school. - SPCCS Kindergarten\",\"created\":\"2026-07-27T00:08:43Z\",\"sender_id\":\"Unisoft\",\"reference_id\":\"msg_750ca406-4905-49f8-b378-f838cebfdb13\",\"fail_reason\":null,\"recipient\":\"+639204567890\"}}', '2026-07-27 00:08:44'),
(8, 5, '+639955425054', 'Good day! Carlos Villanueva has arrived at school at 08:50 AM. - SPCCS Kindergarten', 'arrival', 'failed', '{\"message\":{\"status\":\"pending\",\"metadata\":{},\"content\":\"Good day! Carlos Villanueva has arrived at school at 08:50 AM. - SPCCS Kindergarten\",\"created\":\"2026-07-27T00:50:37Z\",\"sender_id\":\"Unisoft\",\"reference_id\":\"msg_b245e043-70c5-4c98-8c77-619b1162fa80\",\"fail_reason\":null,\"recipient\":\"+639955425054\"}}', '2026-07-27 00:50:38');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int NOT NULL,
  `lrn` varchar(20) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `birth_date` date DEFAULT NULL,
  `address` text,
  `section_id` int DEFAULT NULL,
  `photo` varchar(255) DEFAULT 'default.png',
  `qr_code` varchar(255) DEFAULT NULL,
  `qr_token` varchar(100) DEFAULT NULL,
  `parent_name` varchar(100) DEFAULT NULL,
  `parent_contact` varchar(20) DEFAULT NULL,
  `parent_email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `lrn`, `first_name`, `middle_name`, `last_name`, `gender`, `birth_date`, `address`, `section_id`, `photo`, `qr_code`, `qr_token`, `parent_name`, `parent_contact`, `parent_email`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '100000000001', 'Juan', 'Cruz', 'Dela Cruz', 'Male', '2019-03-15', '<br />\r\n<b>Deprecated</b>:  htmlspecialchars(): Passing null to parameter #1 ($string) of type string is deprecated in <b>C:\\Apache24\\htdocs\\Attendance_System\\students\\edit.php</b> on line <b>222</b><br />', 1, 'default.png', NULL, 'STU-100000000001-A1B2C3', 'Maria Dela Cruz', '09955425054', 'marzanomiles@gmail.com', 1, '2026-05-16 04:58:39', '2026-07-26 23:43:39'),
(2, '100000000002', 'Ana', 'Reyes', 'Santos', 'Female', '2019-06-20', NULL, 1, 'default.png', NULL, 'STU-100000000002-D4E5F6', 'Pedro Santos', '09182345678', NULL, 1, '2026-05-16 04:58:39', '2026-05-16 04:58:39'),
(3, '100000000003', 'Miguel', 'Gomez', 'Garcia', 'Male', '2019-01-10', NULL, 2, 'default.png', NULL, 'STU-100000000003-G7H8I9', 'Rosa Garcia', '09193456789', NULL, 1, '2026-05-16 04:58:39', '2026-05-16 04:58:39'),
(4, '100000000004', 'Sofia', 'Lim', 'Torres', 'Female', '2019-09-05', NULL, 2, 'default.png', NULL, 'STU-100000000004-J1K2L3', 'Luis Torres', '09204567890', NULL, 1, '2026-05-16 04:58:39', '2026-05-16 04:58:39'),
(5, '100000000005', 'Carlos', 'Bautista', 'Villanueva', 'Male', '2019-04-22', 'Purok 3 brgy. San Nicolas San Pablo City, Laguna', 1, 'default.png', NULL, 'STU-100000000005-M4N5O6', 'Carmen Villanueva', '09955425054', 'marzanomiles@gmail.com', 1, '2026-05-16 04:58:39', '2026-07-27 00:48:29'),
(6, '109773080078', 'Miles', 'Sevilla', 'Marzano', 'Male', '2002-10-19', 'Purok 3 brgy. San Nicolas San Pablo City, Laguna', 3, 'student_1785076472_5845.png', NULL, 'STU-109773080078-0DC019', 'Melanie S. Marzano', '09955425054', 'marzanomiles@gmail.com', 1, '2026-07-26 14:34:32', '2026-07-26 14:34:32');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'school_name', 'San Pablo City Central School', '2026-05-16 04:58:39'),
(2, 'school_address', 'San Pablo City, Laguna', '2026-05-16 04:58:39'),
(3, 'school_year', '2026-2027', '2026-07-26 14:23:28'),
(4, 'grade_level', 'Kindergarten', '2026-05-16 04:58:39'),
(5, 'time_in_start', '07:00:00', '2026-05-16 04:58:39'),
(6, 'time_in_end', '08:00:00', '2026-05-16 04:58:39'),
(7, 'late_threshold', '07:31:00', '2026-05-16 04:58:39'),
(8, 'time_out_start', '11:00:00', '2026-05-16 04:58:39'),
(9, 'time_out_end', '12:00:00', '2026-05-16 04:58:39'),
(10, 'unisms_api_key', 'sk_37luO_tKRILQq_NRuZkcF7ePNtKNU5vfWuGbqTmbJyRKI1MdYvIK-DKTImgJ2rX0xKvqRTYu0fnIAa82rUY0ww-1655', '2026-07-27 00:03:07'),
(11, 'unisms_sender_id', 'Unisoft', '2026-07-27 00:03:07'),
(12, 'sms_arrival_template', 'Good day! {student_name} has arrived at school at {time}. - SPCCS Kindergarten', '2026-05-16 04:58:39'),
(13, 'sms_departure_template', 'Good day! {student_name} has left school at {time}. - SPCCS Kindergarten', '2026-05-16 04:58:39'),
(14, 'sms_absence_template', 'Good day! {student_name} was marked ABSENT today {date}. Please inform the school. - SPCCS Kindergarten', '2026-05-16 04:58:39'),
(99, 'mail_host', 'smtp.gmail.com', '2026-07-27 00:28:13'),
(100, 'mail_port', '587', '2026-07-27 00:28:13'),
(101, 'mail_username', 'spccs.attendance@gmail.com', '2026-07-27 00:40:58'),
(102, 'mail_password', 'mvpd xxxx ecoo lihg', '2026-07-27 00:40:58'),
(103, 'mail_from_name', 'SPCCS Kinder Attendance', '2026-07-27 00:28:13'),
(104, 'mail_from_email', 'marzanomiles@gmail.com', '2026-07-27 00:40:58'),
(105, 'email_notifications', '1', '2026-07-27 00:28:13');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','teacher') NOT NULL DEFAULT 'teacher',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$12$qGnaviuQ7Lbu0uN8HPULDO5CCYRE7bbcNBtZeJmJ3WPDsnZH8sukS', 'System Administrator', 'admin@spccs.edu.ph', 'admin', 1, '2026-05-16 04:58:39', '2026-05-16 05:22:53'),
(2, 'teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos', 'msantos@spccs.edu.ph', 'teacher', 1, '2026-05-16 04:58:39', '2026-05-16 04:58:39'),
(3, 'teacher2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jose Reyes', 'jreyes@spccs.edu.ph', 'teacher', 1, '2026-05-16 04:58:39', '2026-05-16 04:58:39'),
(4, 'msm', '$2y$12$OFTi/AK8j16cqLFtG0sa1.3SGx.a7E7haFNdAuWKvWMOXejD9iOfW', 'Marzano', 'marzanomiles@gmail.com', 'teacher', 1, '2026-07-27 00:47:03', '2026-07-27 00:54:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `adviser_id` (`adviser_id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lrn` (`lrn`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `section_id` (`section_id`);

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=127;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
