-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 17, 2026 at 03:48 PM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table `cache`
--

CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `clinic_visits`
--

CREATE TABLE IF NOT EXISTS `clinic_visits` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) UNSIGNED DEFAULT NULL,
  `faculty_id` bigint(20) UNSIGNED DEFAULT NULL,
  `staff_id` bigint(20) UNSIGNED DEFAULT NULL,
  `nurse_id` bigint(20) UNSIGNED DEFAULT NULL,
  `visit_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `symptoms` text DEFAULT NULL,
  `temperature` varchar(255) DEFAULT NULL,
  `blood_pressure` varchar(255) DEFAULT NULL,
  `assessment` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `medicine_id` bigint(20) UNSIGNED DEFAULT NULL,
  `medicine_quantity` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `clinic_visits` (`id`, `student_id`, `faculty_id`, `staff_id`, `nurse_id`, `visit_date`, `reason`, `symptoms`, `temperature`, `blood_pressure`, `assessment`, `treatment`, `remarks`, `created_at`, `updated_at`, `medicine_id`, `medicine_quantity`) VALUES
(18, 15, NULL, NULL, 1, '2026-11-17', 'Dizziness', 'hubog kaayo ang pesti', '38.0 °C', '130/80', 'hubog sya', 'Other', 'Hydrated and vital signs stabilized', '2026-11-17 04:44:58', '2026-11-17 04:45:36', 7, 30),
(19, 15, NULL, NULL, 2, '2026-11-17', 'Other', 'nag breakdown', '36.5 °C', '120/85', 'Vasovagal Syncope / Fainting Episode', 'Other', 'Excused and sent home', '2026-11-17 04:47:14', '2026-11-17 04:47:14', NULL, NULL)
ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`);

--
-- Table structure for table `faculties`
--

CREATE TABLE IF NOT EXISTS `faculties` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `department` varchar(255) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `faculties` (`id`, `employee_id`, `first_name`, `middle_name`, `last_name`, `position`, `department`, `sex`, `birth_date`, `contact_number`, `address`, `created_at`, `updated_at`) VALUES
(5, '324521', 'wefgfuygd', 'fdfsdf', 'fhsufe', 'Instructor II', 'Guidance & Counseling Center', 'Female', '2014-07-11', '09668575678', 'vhgffc', '2026-11-17 04:31:47', '2026-11-17 04:31:47')
ON DUPLICATE KEY UPDATE `first_name` = VALUES(`first_name`);

--
-- Table structure for table `medicines`
--

CREATE TABLE IF NOT EXISTS `medicines` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_name` varchar(255) NOT NULL,
  `treatment_type` varchar(255) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `unit` varchar(255) NOT NULL DEFAULT 'Tablet',
  `stock` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `minimum_stock` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `medicines` (`id`, `medicine_name`, `treatment_type`, `expiration_date`, `unit`, `stock`, `minimum_stock`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Paracetamol', 'Medication', NULL, 'Tablet', 147, 20, NULL, '2026-09-16 20:58:16', '2026-10-18 20:07:19'),
(2, 'Neozep', 'Medication', NULL, 'Piece', 100, 20, NULL, '2026-09-18 13:45:00', '2026-09-22 11:34:14'),
(3, 'Ibuprofen', 'Medication', NULL, 'Tablet', 100, 20, NULL, '2026-09-22 10:58:29', '2026-09-22 11:24:15'),
(4, 'Cetirizine', 'Medication', NULL, 'Tablet', 100, 20, NULL, '2026-09-22 10:58:42', '2026-09-22 11:24:18'),
(5, 'Loratadine', 'Medication', NULL, 'Tablet', 99, 20, NULL, '2026-09-22 10:58:50', '2026-09-24 15:36:07'),
(7, 'Antacid', 'Medication', NULL, 'Tablet', 70, 20, NULL, '2026-09-22 10:59:07', '2026-11-17 04:45:36'),
(8, 'Povidone-Iodine', 'Wound Care', NULL, 'Bottle', 100, 20, NULL, '2026-09-22 10:59:17', '2026-09-22 11:24:47'),
(9, 'Antiseptic solution', 'Wound Care', NULL, 'Bottle', 100, 20, NULL, '2026-09-22 10:59:25', '2026-09-22 11:24:52'),
(10, 'Normal Saline (0.9% NaCl)', 'Wound Care', NULL, 'Bottle', 100, 20, NULL, '2026-09-22 10:59:34', '2026-09-22 11:25:02'),
(11, 'Hydrogen Peroxide', 'Wound Care', NULL, 'Bottle', 100, 20, NULL, '2026-09-22 10:59:43', '2026-09-22 11:25:07'),
(12, 'Calamine Lotion', 'Wound Care', NULL, 'Bottle', 100, 20, NULL, '2026-09-22 10:59:51', '2026-09-22 11:25:11'),
(13, 'Topical antibiotic ointment', 'Wound Care', NULL, 'Tube', 100, 20, NULL, '2026-09-22 10:59:58', '2026-09-22 11:25:16'),
(14, 'Hydrocortisone 1% cream', 'Wound Care', NULL, 'Tube', 100, 20, NULL, '2026-09-22 11:00:07', '2026-09-22 11:25:20'),
(16, 'Oral glucose/glucose gel', 'First Aid', NULL, 'Pack', 99, 20, NULL, '2026-09-22 11:00:22', '2026-09-29 11:28:29'),
(17, 'Adhesive Bandage', 'First Aid', NULL, 'pieces', 100, 20, 'For covering small cuts and abrasions', NULL, NULL),
(18, 'Gauze Pads', 'First Aid', NULL, 'pieces', 100, 20, 'For covering and dressing wounds', NULL, NULL),
(19, 'Medical Tape', 'First Aid', NULL, 'rolls', 100, 20, 'For securing wound dressings', NULL, NULL),
(20, 'Cotton Balls', 'First Aid', NULL, 'packs', 100, 20, 'For cleaning and applying antiseptic', NULL, NULL),
(21, 'Alcohol Pads', 'First Aid', NULL, 'pieces', 100, 20, 'For skin preparation', NULL, NULL),
(22, 'Disposable Gloves', 'First Aid', NULL, 'pairs', 100, 20, 'For basic first aid procedures', NULL, NULL),
(23, 'Elastic Bandage', 'First Aid', NULL, 'rolls', 100, 20, 'For supporting minor injuries', NULL, NULL),
(24, 'Instant Cold Pack', 'Cold Compress', NULL, 'pieces', 100, 20, 'For immediate first aid of minor injuries', NULL, NULL),
(30, 'Oral Rehydration Solution', 'Medication', NULL, 'sachets', 100, 20, 'For fluid replacement during dehydration', NULL, NULL),
(32, 'Mefenamic Acid', 'Medication', NULL, 'tablets', 100, 20, 'For pain when appropriate', NULL, NULL),
(33, 'Loperamide', 'Medication', NULL, 'tablets', 100, 20, 'For diarrhea when appropriate', NULL, NULL),
(34, 'Simethicone', 'Medication', NULL, 'tablets', 100, 20, 'For gas and bloating', NULL, NULL),
(37, 'Normal Saline 0.9%', 'Wound Care', NULL, 'bottles', 100, 20, 'For wound irrigation', NULL, NULL),
(42, 'Burn Gel', 'Wound Care', NULL, 'Tube', 95, 20, 'For minor burns', NULL, '2026-10-04 14:34:11'),
(44, 'Reusable Cold Pack', 'Cold Compress', NULL, 'pieces', 100, 20, 'For cold compress application', NULL, NULL),
(45, 'Ice Pack', 'Cold Compress', NULL, 'pieces', 100, 20, 'For cold compress application', NULL, NULL),
(46, 'Reusable Hot Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For hot compress application', NULL, NULL),
(47, 'Warm Compress Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For warm compress application', NULL, NULL),
(48, 'Hot Water Bag', 'Hot Compress', NULL, 'pieces', 100, 20, 'For warm compress application', NULL, NULL),
(49, 'Reusable Hot Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For hot compress application', NULL, NULL),
(50, 'Warm Compress Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For warm compress application', NULL, NULL),
(51, 'Hot Water Bag', 'Hot Compress', NULL, 'pieces', 100, 20, 'For hot compress application', NULL, NULL),
(52, 'Reusable Hot Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For hot compress application', NULL, NULL),
(53, 'Warm Compress Pack', 'Hot Compress', NULL, 'pieces', 100, 20, 'For warm compress application', NULL, NULL),
(54, 'Hot Water Bag', 'Hot Compress', NULL, 'pieces', 100, 20, 'For hot compress application', NULL, NULL),
(55, 'Cetirizine', 'Medication', NULL, 'Tablet', 200, 155, NULL, '2026-09-29 11:28:47', '2026-10-19 01:15:57')
ON DUPLICATE KEY UPDATE `stock` = VALUES(`stock`);

--
-- Table structure for table `organization_categories`
--

CREATE TABLE IF NOT EXISTS `organization_categories` (
  `category` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `tagline` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `organization_categories` (`category`, `title`, `tagline`, `created_at`, `updated_at`) VALUES
('admin', 'College Administration & Health Services', 'Executive leadership guiding health governance, institutional accreditation, and campus student welfare.', '2026-11-15 13:57:01', '2026-11-15 13:57:01'),
('clinic', 'Clinic Healthcare Team', 'Medical officers, clinical nurses, midwives, and healthcare specialists serving the TCC campus daily.', '2026-11-15 13:57:01', '2026-11-15 13:57:01')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

--
-- Table structure for table `organization_members`
--

CREATE TABLE IF NOT EXISTS `organization_members` (
  `id` varchar(50) NOT NULL,
  `category` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `department` varchar(255) NOT NULL,
  `credentials` varchar(255) DEFAULT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `duty_hours` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Active Duty',
  `avatar_bg` varchar(50) NOT NULL DEFAULT 'bg-[#70091f]',
  `badge_color` varchar(255) DEFAULT NULL,
  `responsibilities` longtext DEFAULT NULL,
  `reports_to` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `organization_members` (`id`, `category`, `name`, `role`, `department`, `credentials`, `level`, `email`, `phone`, `location`, `duty_hours`, `status`, `avatar_bg`, `badge_color`, `responsibilities`, `reports_to`, `sort_order`, `created_at`, `updated_at`) VALUES
('a-1', 'admin', 'Dr. Kharen Jane S Ungab', 'College President', 'Office of the President', 'Doctor of Education (EdD) · Institutional Head', 1, 'president@tcc.edu.ph', '+63 (088) 555-0001', 'TCC Administration Building · Executive Suite', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Administrative', 'bg-[#70091f]', 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800', '[\"Institutional direction and educational governance\", \"Sanctioning campus health & safety operational budgets\", \"Liaison with municipal government and healthcare partners\"]', NULL, 0, '2026-11-15 13:59:01', '2026-11-16 13:07:44'),
('a-2', 'admin', 'Dr. Merilyn Tangon', 'Vice President for Academic Affairs', 'Academic Affairs Directorate', 'PhD in Educational Leadership · ALCUCOA Lead Evaluator', 2, 'vpaa@tcc.edu.ph', '+63 (088) 555-0002', 'TCC Administration Building · 2nd Floor', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Administrative', 'bg-[#8b1505]', 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800', '[\"Academic integration of campus healthcare programs\", \"Institutional medical requirements for enrolled students\", \"Faculty wellness and health development programs\"]', 'a-1', 0, '2026-11-15 13:59:01', '2026-11-16 13:08:00'),
('a-3', 'admin', 'Prof. Rowena G. Alcantara, RN, MN', 'Dean, College of Nursing & Midwifery', 'College of Nursing & Health Sciences', 'BS Nursing · Master of Nursing (MN)', 3, 'dean.nursing@tcc.edu.ph', '+63 (088) 555-0003', 'Health Sciences Building · Dean\'s Office', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Active Duty', 'bg-[#5c0819]', 'bg-emerald-50 text-emerald-700', '[\"Supervision of nursing & midwifery clinical affiliations\", \"Clinical curriculum and competency standards\", \"Collaboration between academic department & campus clinic\"]', 'a-2', 3, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('a-4', 'admin', 'Prof. Leoneil R Sulapas', 'Dean of Student Affairs & Services', 'Student Affairs and Services (SAS)', 'MA in Educational Management', 3, 'osas@tcc.edu.ph', '+63 (088) 555-0004', 'DSAS OFFICE HALLWAY', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Administrative', 'bg-[#480613]', 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800', '[\"Student welfare and emergency crisis assistance\", \"Campus-wide annual medical and dental checkup drives\", \"Coordination of medical clearance for collegiate athletes\"]', 'a-1', 0, '2026-11-15 13:59:01', '2026-11-16 13:08:49'),
('c-1', 'clinic', 'Dr. Maria Elena Ramos, MD', 'College Medical Officer / Head Physician', 'Health Services & Diagnostics', 'Doctor of Medicine (MD) · Board Certified', 1, 'dr.ramos@tcc.edu.ph', '+63 (088) 555-0101', 'Baluarte Campus Clinic · Room 101', 'Mon - Thu · 8:00 AM - 5:00 PM', 'In Clinic', 'bg-[#70091f]', 'bg-[#70091f]/10 text-[#70091f]', '[\"Directs campus health policy and patient diagnosis\", \"Conducts routine student & faculty clinical evaluations\", \"Supervises prescription dispensations and emergency protocols\"]', NULL, 1, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-2', 'clinic', 'Angel Santos, RN, MAN', 'Head Nurse & Clinic Coordinator', 'Nursing & Clinic Operations', 'BS Nursing · Master of Arts in Nursing (MAN)', 2, 'angel.santos@tcc.edu.ph', '+63 (088) 555-0102', 'Baluarte Campus Clinic · Triage Station', 'Mon - Fri · 7:30 AM - 4:30 PM', 'Active Duty', 'bg-[#8b1505]', 'bg-emerald-50 text-emerald-700', '[\"Coordinates day-to-day clinic visits and patient intake\", \"Manages student vital signs and immunization tracking\", \"Supervises junior staff nurses and clinical interns\"]', 'c-1', 2, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-3', 'clinic', 'Carmela Bautista, RN', 'Staff Nurse (Primary Care & Triage)', 'Primary Healthcare Unit', 'BS Nursing · Registered Nurse (RN)', 3, 'carmela.bautista@tcc.edu.ph', '+63 (088) 555-0103', 'Baluarte Campus Clinic · Bed Ward 1', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Active Duty', 'bg-[#5c0819]', 'bg-blue-50 text-blue-700', '[\"First aid treatment and wound dressing\", \"Patient vital signs recording and consultation logs\", \"Assisting physician in student physical examinations\"]', 'c-2', 3, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-4', 'clinic', 'Jocelyn Dela Cruz, RM, RN', 'Staff Midwife & Maternal Health Specialist', 'Maternal & Reproductive Healthcare', 'Registered Midwife (RM) · Registered Nurse (RN)', 3, 'jocelyn.delacruz@tcc.edu.ph', '+63 (088) 555-0104', 'Baluarte Campus Clinic · Examination Room', 'Mon - Fri · 8:00 AM - 5:00 PM', 'Active Duty', 'bg-[#a31a08]', 'bg-purple-50 text-purple-700', '[\"Maternal and reproductive health counseling\", \"Fetal Doppler monitoring and routine vitals assessment\", \"Women\'s health wellness education seminars\"]', 'c-2', 4, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-5', 'clinic', 'Mark Anthony Dizon, RN', 'Emergency & First Aid Nurse', 'Emergency Response & Triage', 'BS Nursing · BLS / ACLS Certified', 3, 'mark.dizon@tcc.edu.ph', '+63 (088) 555-0105', 'Baluarte Campus Clinic · Emergency Bay', 'Mon - Fri · 8:00 AM - 5:00 PM', 'On Call', 'bg-[#70091f]', 'bg-amber-50 text-amber-700', '[\"Emergency trauma response on campus grounds\", \"Maintenance of emergency kit, oxygen, and stretchers\", \"CPR and emergency medical transport coordination\"]', 'c-2', 5, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-6', 'clinic', 'Kristine Mae Perez, RPh', 'Clinic Pharmacist & Dispensary Custodian', 'Pharmacy & Medicine Inventory', 'BS Pharmacy · Registered Pharmacist (RPh)', 4, 'kristine.perez@tcc.edu.ph', '+63 (088) 555-0106', 'Baluarte Campus Clinic · Pharmacy Room', 'Mon - Fri · 8:30 AM - 4:30 PM', 'Active Duty', 'bg-[#480613]', 'bg-emerald-50 text-emerald-700', '[\"Medicine stock tracking, expiration audits & re-ordering\", \"Safe prescription dispensing and dosage instructions\", \"Inventory logging on the digital clinic management system\"]', 'c-2', 6, '2026-11-15 13:59:01', '2026-11-15 13:59:01'),
('c-7', 'clinic', 'Ryan Paul Villanueva', 'Medical Records Officer & Health Data Custodian', 'Health Records & Statistics', 'BS Information Technology · Health Records Specialist', 4, 'ryan.villanueva@tcc.edu.ph', '+63 (088) 555-0107', 'Baluarte Campus Clinic · Archive Section', 'Mon - Fri · 8:00 AM - 5:00 PM', 'In Clinic', 'bg-[#2e030c]', 'bg-stone-50 text-stone-700', '[\"Maintenance of student digital and physical health folders\", \"Processing medical certificates and clearance documents\", \"Generation of monthly health statistics and clinic analytics\"]', 'c-2', 7, '2026-11-15 13:59:01', '2026-11-15 13:59:01')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

--
-- Table structure for table `students`
--

CREATE TABLE IF NOT EXISTS `students` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) NOT NULL,
  `course` varchar(255) NOT NULL,
  `year_level` varchar(255) NOT NULL,
  `section` varchar(255) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `students` (`id`, `student_id`, `first_name`, `middle_name`, `last_name`, `course`, `year_level`, `section`, `sex`, `birth_date`, `contact_number`, `address`, `created_at`, `updated_at`) VALUES
(14, '20241407', 'Tabligan', NULL, 'Angel Stacee', 'BSBA', '3rd Year', '3B', 'Female', '2006-03-03', '09959048004', 'Napocor Natumolan 20B', '2026-11-16 15:27:58', '2026-11-17 15:23:30'),
(15, '20242311', 'Grace', NULL, 'Tocmohan', 'BSIT', '3rd Year', '3B', 'Female', '2006-06-20', '09090909090', 'sdfvbgnhmj,k.', '2026-11-17 04:36:58', '2026-11-17 04:36:58')
ON DUPLICATE KEY UPDATE `first_name` = VALUES(`first_name`);

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(255) DEFAULT NULL,
  `profile_picture` longtext DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `role`, `profile_picture`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'Angel Stacee', 'admin.clinic.edu@gmail.com', 'Nurse', NULL, NULL, '$2y$12$L9Bi1WGhfCLGphbAEhaNue28lj5LRHur.pVX6Zy4LJyV4TAgxWbCK', NULL, '2026-09-15 15:46:54', '2026-11-17 04:26:53'),
(2, 'Ralph', 'tcc_clinic@tcc.edu.ph', 'Nurse', NULL, NULL, '$2y$12$L9Bi1WGhfCLGphbAEhaNue28lj5LRHur.pVX6Zy4LJyV4TAgxWbCK', NULL, '2026-09-16 15:42:35', '2026-10-20 05:06:13')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
