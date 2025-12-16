-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 16, 2025 at 01:42 AM
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
-- Database: `dentitrack_main`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `posted_by` int(11) NOT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `posted_by`, `posted_at`, `image`) VALUES
(1, 'Holiday', 'christmas szn', 5, '2025-12-25 16:00:00', 'ann_69286c7a515fe.jfif'),
(2, 'Maintenance', 'wait for the further announcement', 5, '2025-11-28 16:00:00', 'ann_69286c660649c.jpg'),
(3, 'Maintenance', 'wait for the further announcement', 5, '2025-11-28 16:00:00', 'ann_69286bc247c39.jfif');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` varchar(50) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('approved','booked','completed','cancelled','declined','rest','pending_installment') NOT NULL DEFAULT 'booked',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `user_id`, `patient_id`, `service_id`, `appointment_date`, `appointment_time`, `start_time`, `end_time`, `comments`, `status`, `created_at`, `updated_at`) VALUES
(93, 40, 39, 48, '2025-12-25', '9:00 AM - 11:00 AM', '09:00:00', '11:00:00', '', 'pending_installment', '2025-12-14 07:07:08', '2025-12-14 07:33:45'),
(96, 42, 41, 45, '2025-12-22', '8:00 AM - 9:00 AM', '08:00:00', '09:00:00', '', 'completed', '2025-12-14 11:09:52', '2025-12-14 11:59:48'),
(99, 42, 41, 30, '2025-12-30', '8:00 AM - 9:00 AM', '08:00:00', '09:00:00', '', 'completed', '2025-12-14 14:00:32', '2025-12-14 14:01:41'),
(100, 40, 39, 47, '2025-12-27', '5:06 AM - 5:08 AM', '05:06:00', '05:08:00', '', 'completed', '2025-12-14 14:04:09', '2025-12-14 18:55:58'),
(104, 46, NULL, 46, '2025-12-28', '5:00 AM - 6:00 AM', '05:00:00', '06:00:00', '', 'pending_installment', '2025-12-15 15:14:28', '2025-12-15 16:10:04'),
(105, 46, NULL, 47, '2026-01-05', '5:08 AM - 5:10 AM', '05:08:00', '05:10:00', '', 'approved', '2025-12-15 16:12:41', '2025-12-15 16:13:36'),
(107, 42, 41, 57, '2026-01-07', '5:45 AM - 6:30 AM', '05:45:00', '06:30:00', '', 'pending_installment', '2025-12-15 16:29:39', '2025-12-15 16:30:26');

-- --------------------------------------------------------

--
-- Table structure for table `dental_records`
--

CREATE TABLE `dental_records` (
  `record_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `record_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dental_supplies`
--

CREATE TABLE `dental_supplies` (
  `supply_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `batch_id` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) DEFAULT 'pcs',
  `expiration_date` date DEFAULT NULL,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 5,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lot_number` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dental_supplies`
--

INSERT INTO `dental_supplies` (`supply_id`, `name`, `batch_id`, `category`, `description`, `quantity`, `unit`, `expiration_date`, `low_stock_threshold`, `last_updated`, `lot_number`) VALUES
(38, 'Rubber Dam', '2026-07-10-38', NULL, 'A thin latex or non-latex sheet used to isolate one or more teeth during dental procedures (restorations, root canal, etc.) to keep the area dry and clean.', 118, 'boxes', '2026-07-10', 10, '2025-12-14 06:49:17', 'LOT-20251128-ab563c'),
(39, 'Rubber Dam', '2028-11-01-39', NULL, 'A thin latex or non-latex sheet used to isolate one or more teeth during dental procedures (restorations, root canal, etc.) to keep the area dry and clean.', 13, 'boxes', '2028-11-01', 10, '2025-12-14 04:49:34', 'LOT-20251128-84e858'),
(40, 'Rubber Mixing Bowl', '2028-02-11-40', NULL, 'A flexible rubber bowl used for mixing dental materials such as alginate and impression', 109, 'boxes', '2028-02-11', 60, '2025-12-14 05:59:13', 'LOT-20251128-22c570'),
(41, 'Composite Resin Kit (A2)', '2026-03-20-CRA2', 'Restorative Materials', 'Light-cured composite material for anterior and posterior restorations (Shade A2).', 45, 'kits', '2026-03-20', 5, '2025-12-15 18:48:42', 'LOT-20251128-CRK001'),
(42, 'Gingival Retraction Cord', '2027-01-15-GRC', 'Auxiliary Materials', 'Knitted cord for temporary retraction of gingival tissue before impression taking.', 80, 'bottles', '2027-01-15', 15, '2025-12-15 18:48:42', 'LOT-20251128-GRC002'),
(43, 'Disposable Syringe Tips', '2028-05-01-DST', 'Disposables', 'Air/water syringe tips, disposable plastic.', 500, 'packs', '2028-05-01', 100, '2025-12-15 18:48:42', 'LOT-20251128-DST003'),
(44, 'Anesthetic Cartridges (Lidocaine)', '2026-10-01-LAC', 'Anesthetics', '2% Lidocaine with Epinephrine 1:100,000.', 250, 'boxes', '2026-10-01', 25, '2025-12-15 18:48:42', 'LOT-20251128-LAC004'),
(45, 'Etching Gel', '2027-04-10-EG', 'Adhesives', '37% Phosphoric Acid Etching Gel for enamel and dentin conditioning.', 95, 'syringes', '2027-04-10', 10, '2025-12-15 18:48:42', 'LOT-20251128-ETG005'),
(46, 'Prophy Paste (Medium Grit)', '2028-08-01-PPM', 'Preventive', 'Mint-flavored prophylaxis paste, medium grit.', 160, 'cups', '2028-08-01', 30, '2025-12-15 18:48:42', 'LOT-20251128-PPM006'),
(47, 'Isolation Rolls (Cotton)', '2027-06-01-IR', 'Disposables', 'Sterile cotton rolls for isolation and moisture control.', 1000, 'bags', '2027-06-01', 200, '2025-12-15 18:48:42', 'LOT-20251128-IRC007'),
(48, 'Dental Bibs', '2029-12-31-DB', 'Disposables', 'Two-ply paper with polyethylene backing patient bibs.', 300, 'boxes', NULL, 50, '2025-12-15 18:48:42', 'LOT-20251128-DB008'),
(49, 'Curing Light Sleeves', '2028-03-01-CLS', 'Sterilization/Infection Control', 'Disposable barrier sleeves for curing lights.', 400, 'boxes', '2028-03-01', 40, '2025-12-15 18:48:42', 'LOT-20251128-CLS009'),
(50, 'Alginate Impression Material', '2026-11-25-AIM', 'Impression Materials', 'Chromatic fast-set alginate for dental impressions.', 75, 'cans', '2026-11-25', 10, '2025-12-15 18:48:42', 'LOT-20251128-AIM010');

-- --------------------------------------------------------

--
-- Table structure for table `doctor_availability`
--

CREATE TABLE `doctor_availability` (
  `id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `available_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctor_availability`
--

INSERT INTO `doctor_availability` (`id`, `doctor_id`, `available_date`, `start_time`, `end_time`, `is_available`, `reason`, `created_at`, `updated_at`) VALUES
(1, 4, '2025-11-30', '00:00:00', '23:59:59', 1, 'asd', '2025-11-26 19:52:01', '2025-11-26 20:13:04'),
(2, 4, '2025-12-02', '00:00:00', '23:59:59', 0, 'sick leave', '2025-11-26 20:13:12', '2025-11-26 20:13:12'),
(3, 4, '2025-12-12', '00:00:00', '23:59:59', 0, 'testing', '2025-12-03 06:21:59', '2025-12-03 06:21:59'),
(4, 4, '2025-12-13', '00:00:00', '23:59:59', 0, 'bagod na', '2025-12-03 08:50:45', '2025-12-03 08:50:45');

-- --------------------------------------------------------

--
-- Table structure for table `patient`
--

CREATE TABLE `patient` (
  `patient_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `fullname` varchar(255) GENERATED ALWAYS AS (trim(concat_ws(' ',`first_name`,`middle_name`,`last_name`))) STORED,
  `email` varchar(150) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT 'Other',
  `address` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_number` varchar(20) DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `outstanding_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_date` datetime DEFAULT NULL,
  `downpayment_default` decimal(10,2) DEFAULT 500.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient`
--

INSERT INTO `patient` (`patient_id`, `user_id`, `first_name`, `middle_name`, `last_name`, `email`, `contact_number`, `dob`, `gender`, `address`, `profile_image`, `emergency_contact_name`, `emergency_contact_number`, `medical_history`, `allergies`, `notes`, `created_at`, `updated_at`, `outstanding_balance`, `is_archived`, `archived_date`, `downpayment_default`) VALUES
(39, 40, 'mayi ganda', 'm', 'landicho', 'ayizaki2004@gmail.com', '09354544921', '2004-07-18', 'Female', 'Canada', NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-14 07:06:00', '2025-12-15 19:15:58', 36935.29, 0, NULL, 500.00),
(41, 42, 'Cristan Irish', 'P.', 'Legaspi', 'tantanlegaspi01@gmail.com', '09354508020', '2003-06-01', 'Male', 'abangan', 'patient_42_1765831513.jpg', NULL, NULL, NULL, NULL, NULL, '2025-12-14 11:08:55', '2025-12-15 20:45:13', 3500.00, 0, NULL, 500.00);

-- --------------------------------------------------------

--
-- Table structure for table `patient_files`
--

CREATE TABLE `patient_files` (
  `file_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient_files`
--

INSERT INTO `patient_files` (`file_id`, `patient_id`, `file_name`, `file_path`, `uploaded_at`) VALUES
(4, 39, 'Dental-Bridge.jpg', '../uploads/patient_files/1765729175_Dental-Bridge.jpg', '2025-12-15 00:19:35'),
(5, 41, 'online_bookings.txt', '../uploads/patient_files/1765832194_online_bookings.txt', '2025-12-16 04:56:34');

-- --------------------------------------------------------

--
-- Table structure for table `patient_recommendations`
--

CREATE TABLE `patient_recommendations` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_username` varchar(255) DEFAULT NULL,
  `recommendation` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient_recommendations`
--

INSERT INTO `patient_recommendations` (`id`, `patient_id`, `doctor_username`, `recommendation`, `created_at`) VALUES
(45, 26, 'Trishia Mayi', 'hello', '2025-11-27 02:18:00'),
(46, 27, 'Trishia Mayi', 'brush teeth', '2025-11-28 13:28:16'),
(47, 41, 'Trishia Mayi', 'you need to brush your teeth after a meal', '2025-12-16 04:56:49');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `patient_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `discount_type` varchar(20) DEFAULT 'none',
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `supplies_used` text DEFAULT NULL,
  `payment_method` varchar(20) DEFAULT 'cash',
  `booking_option` varchar(50) DEFAULT NULL,
  `status` enum('pending','paid','completed','pending_installment') NOT NULL DEFAULT 'pending',
  `payment_option` enum('full','installment') DEFAULT 'full',
  `downpayment` decimal(10,2) DEFAULT 0.00,
  `installment_term` int(11) DEFAULT 1,
  `monthly_payment` decimal(10,2) DEFAULT 0.00,
  `appointment_id` int(11) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `user_id`, `amount`, `payment_date`, `created_at`, `patient_id`, `service_id`, `discount_type`, `discount_amount`, `total_amount`, `supplies_used`, `payment_method`, `booking_option`, `status`, `payment_option`, `downpayment`, `installment_term`, `monthly_payment`, `appointment_id`, `proof_image`) VALUES
(169, 40, 50000.00, '2025-12-14', '2025-12-14 07:07:08', 39, 48, 'none', 0.00, 50000.00, '[]', 'Cash', 'Online', 'pending', 'installment', 0.00, 34, 1464.71, 93, 'uploads/payment_proofs/proof_693e621c23b0f.png'),
(170, 40, 6400.00, '2025-12-14', '2025-12-14 08:31:05', 39, 46, 'None', 0.00, 7000.00, NULL, NULL, 'Walk-in', 'pending', NULL, 600.00, 0, 0.00, NULL, 'uploads/payment_proofs/proof_693e75c9e06f9.jpg'),
(172, 42, 5000.00, '2025-12-14', '2025-12-14 11:09:52', 41, 45, 'none', 0.00, 5000.00, '[]', 'Cash', 'Online', 'paid', 'full', 0.00, 0, 0.00, 96, 'uploads/payment_proofs/proof_693e9b00aa310.png'),
(175, 42, 20000.00, '2025-12-14', '2025-12-14 14:00:32', 41, 30, 'none', 0.00, 20000.00, '[]', 'Cash', 'Walk-in', 'paid', 'full', 0.00, 0, 0.00, 99, 'uploads/payment_proofs/proof_693ec3007a661.jfif'),
(176, 40, 5000.00, '2025-12-14', '2025-12-14 14:04:09', 39, 47, 'none', 0.00, 5000.00, '[]', 'Cash', 'Walk-in', 'paid', 'full', 0.00, 0, 0.00, 100, 'uploads/payment_proofs/proof_693ec3d93931b.png'),
(183, 42, 8000.00, '2025-12-16', '2025-12-15 16:29:39', 41, 57, 'none', 0.00, 8000.00, '[]', 'Cash', 'Walk-in', 'pending', 'installment', 0.00, 2, 3500.00, 107, 'uploads/payment_proofs/proof_694037736f077.png');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` int(11) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `category`, `description`, `price`, `duration`, `image_path`, `created_at`, `status`) VALUES
(1, 'Fluoride Application for Kids', 'Pediatric Dentistry', 'Helps strengthen tooth enamel and prevent cavities in children.', 800.00, '30 minutes', 'uploads/service_images/svc_691ec18f4774b.jfif', '2025-10-21 23:17:09', 'active'),
(2, 'Sealant Application for Kids', 'Pediatric Dentistry', 'Protective coating applied to children’s molars to prevent decay.', 900.00, '30 minutes', 'uploads/service_images/svc_69289072713a0.jpg', '2025-10-21 23:17:09', 'active'),
(3, 'Space Maintainer', 'Pediatric Dentistry', 'Used to keep space open for permanent teeth after premature tooth loss.', 1500.00, '45 minutes', 'uploads/service_images/svc_693d95a9f2965.jpg', '2025-10-21 23:17:09', 'active'),
(30, 'Metal Braces', 'Orthodontics', 'A comprehensive treatment using metal brackets and wires to correct severe misalignment and bite issues.', 20000.00, '1 hour', 'uploads/service_images/svc_693da1e40850d.jpg', '2025-11-15 03:19:53', 'active'),
(39, 'Deep Cleaning (Per Quadrant)', 'Periodontics', 'Scaling and Root Planing (SRP): A non-surgical procedure to treat gum disease by cleaning plaque and calculus below the gum line in one section of the mouth.', 3500.00, '50 minutes', 'uploads/service_images/svc_693d013a73e83.jpg', '2025-12-13 13:39:44', 'active'),
(42, 'Tooth extraction', 'General Dentistry', 'Tooth extraction is a dental procedure that involves the removal of a tooth from its socket in the jawbone, often performed when a tooth is damaged beyond repair or poses a risk to oral health.', 800.00, '30 minutes', 'uploads/service_images/svc_693d96da2db5b.jfif', '2025-12-14 00:39:54', 'active'),
(43, 'Tooth Restoration', 'General Dentistry', 'Tooth restoration is a dental procedure aimed at repairing or replacing damaged or missing parts of teeth to improve oral health, functionality, and aesthetics.', 10000.00, '1 hr', 'uploads/service_images/svc_693d99ce98e20.webp', '2025-12-14 00:52:30', 'active'),
(44, 'Oral Prophylaxis', 'General Dentistry', 'Preventive dental procedure aimed at maintaining oral health by removing plaque and tartar, and preventing dental issues such as cavities and gum disease.', 2000.00, '30 minutes', 'uploads/service_images/svc_693d9b1362683.jpg', '2025-12-14 00:57:55', 'active'),
(45, 'Odontectonomy', 'Oral Surgery', 'Odontectomy is a surgical procedure primarily used for the extraction of impacted teeth.\r\nThis procedure involves cutting into the gums to expose the impacted tooth and the surrounding bone, and in some cases, drilling into the bone to access the tooth more easily', 5000.00, '40 min', 'uploads/service_images/svc_693d9d4ae7add.jpeg', '2025-12-14 01:07:22', 'active'),
(46, 'Biopsy', 'Oral Surgery', 'A procedure where a small sample of tissue is removed from an abnormal area in the mouth or jaw for laboratory examination to determine the presence of a disease, such as cancer.', 7000.00, '1 hour', 'uploads/service_images/svc_693d9f8cc0f72.jfif', '2025-12-14 01:17:00', 'active'),
(47, 'Evaluation & Management of Oral Tumors and Cysts', 'Oral Surgery', 'The diagnosis and surgical excision or treatment of growths and fluid-filled sacs in the mouth and jawbone.', 5000.00, '2', 'uploads/service_images/svc_693da07e4a87f.jpg', '2025-12-14 01:21:02', 'active'),
(48, 'Ceramic Braces', 'Orthodontics', 'These work identically to traditional metal braces but use clear or tooth-colored ceramic brackets instead of metal. They are popular among adults and image-conscious teens because they are less noticeable.', 50000.00, '2 hours', 'uploads/service_images/svc_693da2e9bd739.jfif', '2025-12-14 01:31:21', 'active'),
(49, 'Self-Ligating Braces', 'Orthodontics', 'These utilize a built-in clip or sliding mechanism (a \"door\") to hold the archwire, eliminating the need for elastic ties (ligatures). This mechanism reduces friction and allows the teeth to move more freely.', 70000.00, '2 hours', 'uploads/service_images/svc_693da43e7c32c.jfif', '2025-12-14 01:37:02', 'active'),
(50, 'Functional Appliance', 'Orthodontics', 'These are removable or fixed devices used primarily in growing children or early teens to correct underlying skeletal discrepancies. They work by guiding jaw growth, modifying muscle function, and correcting habits like tongue thrust or mouth breathing', 40000.00, '1 hour', 'uploads/service_images/svc_693da4d031e36.jpg', '2025-12-14 01:39:28', 'active'),
(51, 'Fixed Bridge', 'Prosthodontics', 'A non-removable appliance to replace missing teeth, supported by crowns placed on the natural teeth (abutments) on either side of the gap.', 10000.00, '30 minutes', 'uploads/service_images/svc_693da55ad92a2.jpg', '2025-12-14 01:41:46', 'active'),
(52, 'Crowns', 'Prosthodontics', 'A tooth-shaped \"cap\" placed over a single tooth to restore its function, size, and appearance after significant damage or decay.', 7000.00, '2 hours', 'uploads/service_images/svc_693da5b4470a4.jfif', '2025-12-14 01:43:16', 'active'),
(53, 'Dentures', 'Prosthodontics', 'Removable appliances (full or partial) that replace missing teeth and soft tissue.', 6000.00, '1 hour', 'uploads/service_images/svc_693da60282035.jpg', '2025-12-14 01:44:34', 'active'),
(54, 'Veneers', 'Prosthodontics', 'Thin, custom-made porcelain or composite shells bonded to the front surface of teeth to change their color, shape, and size.', 15000.00, '2 hours', 'uploads/service_images/svc_693da66210f67.jfif', '2025-12-14 01:46:10', 'active'),
(55, 'Root Canal Treatment', 'Endodontics', 'The main endodontic procedure. It involves completely removing the infected or inflamed pulp (nerve tissue) from the tooth\'s root canals, meticulously cleaning and disinfecting the space, and then filling and sealing the canals with an inert material to save the natural tooth.', 10000.00, '1 hour', 'uploads/service_images/svc_693da718baf49.jfif', '2025-12-14 01:49:12', 'active'),
(56, 'Pulpotomy', 'Endodontics', 'A procedure mostly performed on primary (baby) teeth where only the infected or inflamed pulp in the crown portion of the tooth is removed. The healthy pulp tissue inside the root canals is left intact.', 10000.00, '45 minutes', 'uploads/service_images/svc_693da91159241.jfif', '2025-12-14 01:57:37', 'active'),
(57, 'Apicoectomy', 'Endodontics', 'This is a minor surgical procedure performed after a previous Root Canal Treatment has failed. The dentist accesses the root tip through the gum, removes the very end of the root, and scrapes away any infected tissue/cyst, sealing the end of the canal from the outside.', 8000.00, '45 minutes', 'uploads/service_images/svc_693da966efdac.jfif', '2025-12-14 01:59:02', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `supply_usage`
--

CREATE TABLE `supply_usage` (
  `id` int(11) NOT NULL,
  `supply_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `quantity_used` int(11) NOT NULL,
  `used_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supply_usage`
--

INSERT INTO `supply_usage` (`id`, `supply_id`, `patient_id`, `user_id`, `quantity_used`, `used_at`) VALUES
(1, 10, 3, 0, 12, '2025-11-11 02:51:07'),
(2, 1, 1, 0, 12, '2025-11-11 02:51:38'),
(3, 5, 4, 0, 5, '2025-11-11 02:52:54'),
(4, 5, 4, 0, 5, '2025-11-11 02:53:29'),
(5, 5, 4, 0, 5, '2025-11-11 02:53:48'),
(6, 5, 3, 0, 1, '2025-11-11 03:06:54'),
(7, 5, 2, 0, 1, '2025-11-11 03:10:03'),
(8, 5, 1, 0, 1, '2025-11-11 03:12:14'),
(9, 5, 4, 0, 1, '2025-11-11 03:14:40'),
(10, 5, 1, 0, 1, '2025-11-11 03:17:53'),
(11, 5, 4, 0, 1, '2025-11-11 03:30:59'),
(12, 5, 4, 0, 1, '2025-11-11 03:34:41'),
(13, 5, 4, 0, 1, '2025-11-11 03:36:50'),
(14, 5, 4, 0, 1, '2025-11-11 03:37:02'),
(15, 8, 4, 0, 1, '2025-11-11 03:43:46'),
(16, 5, 4, 0, 1, '2025-11-11 03:47:53'),
(17, 5, 4, 0, 1, '2025-11-11 03:52:17'),
(18, 5, 3, 0, 1, '2025-11-11 14:28:10'),
(19, 1, 4, 0, 12, '2025-11-12 15:46:23'),
(20, 9, 4, 0, 3, '2025-11-12 15:46:23'),
(21, 1, 4, 0, 1, '2025-11-12 17:03:32'),
(22, 1, 4, 0, 2, '2025-11-12 17:18:41'),
(23, 1, 4, 0, 52, '2025-11-12 17:22:30'),
(24, 1, 4, 0, 12, '2025-11-12 17:32:43'),
(25, 1, 4, 0, 2, '2025-11-12 17:38:15'),
(26, 1, 4, 0, 2, '2025-11-12 20:29:18'),
(27, 1, 3, 0, 1, '2025-11-12 20:41:42'),
(28, 1, 2, 0, 2, '2025-11-12 21:10:24'),
(29, 1, 1, 0, 12, '2025-11-12 21:12:35'),
(30, 1, 2, 0, 2, '2025-11-12 21:45:29'),
(31, 1, 2, 0, 2, '2025-11-12 21:45:37'),
(32, 1, 2, 0, 2, '2025-11-12 21:45:50'),
(33, 1, 4, 0, 3, '2025-11-12 21:46:14'),
(34, 1, 2, 0, 1, '2025-11-12 22:04:32'),
(35, 1, 3, 0, 1, '2025-11-12 22:05:31'),
(36, 11, 3, 0, 1, '2025-11-12 22:07:13'),
(37, 1, 3, 0, 1, '2025-11-12 22:07:13'),
(38, 7, 3, 0, 5, '2025-11-13 01:59:36'),
(39, 7, 26, 0, 5, '2025-11-27 03:40:55'),
(41, 38, 36, 0, 1, '2025-12-13 16:53:16'),
(42, 38, 36, 0, 1, '2025-12-13 16:54:28'),
(43, 38, 36, 0, 1, '2025-12-13 16:55:52'),
(44, 40, 26, 0, 1, '2025-12-13 19:11:44'),
(45, 39, 26, 0, 1, '2025-12-13 19:21:20'),
(46, 39, 37, 5, 6, '2025-12-14 12:49:34'),
(47, 40, 26, 5, 5, '2025-12-14 13:24:34'),
(48, 38, 26, 5, 6, '2025-12-14 13:24:34'),
(49, 38, 38, 5, 2, '2025-12-14 13:59:13'),
(50, 40, 38, 5, 5, '2025-12-14 13:59:13'),
(51, 38, 38, 5, 6, '2025-12-14 14:49:17');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('about_us_image', 'images/about_us_image_692a6c7072a3b.jpg'),
('about_us_image_path', 'images/clinic_team.jpg'),
('about_us_text', 'At DentiTrack, we are passionate about providing exceptional dental care tailored to your needs. Our experienced team is committed to enhancing your dental health with personalized, state-of-the-art treatments in a welcoming environment. We utilize the latest technology to ensure your comfort and the highest standard of care. From routine check-ups to complex cosmetic procedures, trust us to brighten your smile and improve your oral health.'),
('about_us_title', 'Our Mission to your Smile'),
('accent_color', '#d7e515'),
('clinic_address', '0067 Wakas, Bocaue, Philippines'),
('clinic_contact_number', '0922 878 7341'),
('clinic_email', 'danaroxasdental@gmail.com'),
('clinic_hours_friday', 'CLOSED'),
('clinic_hours_html', '<ul style=\"list-style: none; padding: 0;\"><li><strong>Monday:</strong> 09:30 AM - 04:30 PM</li><li><strong>Tuesday:</strong> 10:00 AM - 08:30 PM</li><li><strong>Wednesday:</strong> 12:00 PM - 09:00 PM</li><li><strong>Thursday:</strong> CLOSED</li><li><strong>Friday:</strong> CLOSED</li><li><strong>Saturday:</strong> CLOSED</li><li><strong>Sunday:</strong> CLOSED</li></ul>'),
('clinic_hours_monday', '09:30 AM - 04:30 PM'),
('clinic_hours_saturday', 'CLOSED'),
('clinic_hours_sunday', 'CLOSED'),
('clinic_hours_thursday', 'CLOSED'),
('clinic_hours_tuesday', '10:00 AM - 08:30 PM'),
('clinic_hours_wednesday', '12:00 PM - 09:00 PM'),
('clinic_phone', ''),
('default_timezone', 'Asia/Manila'),
('doctor_can_manage_appointments', 'no'),
('enable_appointments', 'no'),
('enable_audit_logging', 'no'),
('enable_discounts', 'no'),
('enable_notifications', 'no'),
('enable_payments', 'no'),
('font_family', 'Segoe UI'),
('footer_text', ''),
('header_bg_image', 'images/header_bg_692a6cb3c7b06.jpg'),
('header_text_color', '#ffffff'),
('require_strong_passwords', 'no'),
('secretary_can_manage_patients', 'no'),
('sidebar_bg', '#349edf'),
('sidebar_text', '#ffffff'),
('system_name', 'DentiTrack');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `otp_code` varchar(10) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `role` enum('patient','secretary','doctor','admin') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `remember_token` varchar(255) DEFAULT NULL,
  `remember_selector` varchar(32) DEFAULT NULL,
  `verification_expiry` datetime DEFAULT NULL,
  `verification_token` varchar(6) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `otp_code`, `otp_expiry`, `role`, `created_at`, `updated_at`, `remember_token`, `remember_selector`, `verification_expiry`, `verification_token`, `is_verified`) VALUES
(3, 'Trishia Mayi', NULL, '$2y$10$J0glyT39TQbiVeMIYC9/Fu9eyzARm1c5GV9VAcRqBxaE0Olwm6/Lu', NULL, NULL, 'doctor', '2025-10-01 01:08:16', '2025-11-20 17:55:13', NULL, NULL, NULL, NULL, 1),
(4, 'Oswald', 'johnoswald@gmail.com', '$2y$10$tuuNj5fEM8dBlBFXdta/P.q/MQwWuZHJezklXHXV2JQENbM4NCkRG', NULL, NULL, 'doctor', '2025-10-01 01:17:25', '2025-11-20 17:55:16', NULL, NULL, NULL, NULL, 1),
(5, 'Andrei', 'andrei09555938734@gmail.com', '$2y$10$iUuDJM7ayaTEMcRHuADd3uHDbzPUr9SpH3hylI59mNv.OmDp3/58O', NULL, NULL, 'secretary', '2025-10-01 01:20:36', '2025-12-16 03:36:47', NULL, NULL, NULL, NULL, 1),
(10, 'Admin Mayi', NULL, '$2y$10$N8ocdRzOi4AJsSsdmrhvQ.OQf6/T3DtoYL9FEdxchmcI9v6podN6G', NULL, NULL, 'admin', '2025-10-02 00:19:08', '2025-11-30 00:19:10', NULL, NULL, NULL, NULL, 1),
(12, 'Admin Maureen', 'maureennipas2@gmail.com', '$2y$10$9AWdpW7Dz/LayApdzewqfeTXMbo1OGchgdNkyV7F9e6HeKJUxV7yu', NULL, NULL, 'admin', '2025-10-02 16:13:15', '2025-11-20 17:55:34', NULL, NULL, NULL, NULL, 1),
(14, 'yap', 'yap@gmail.com', '$2y$10$3oc7mV9M.RH3cjzmbi/2n.T89D4.BXOQ26349s/AI0hPgQfRoxETW', NULL, NULL, 'admin', '2025-10-22 13:43:15', '2025-11-20 17:55:39', NULL, NULL, NULL, NULL, 1),
(15, 'yapyap', 'yapyap@gmail.com', '$2y$10$DJvTs2vMcyqdgvqQma8aSuZIULqPQvrOA5fGkorNQQQ4.Hre30Y3.', NULL, NULL, 'secretary', '2025-10-22 13:44:54', '2025-11-20 17:55:42', NULL, NULL, NULL, NULL, 1),
(16, 'yap123', 'yapjohnoswald@gmail.com', '$2y$10$dK98Hhs3/CtSDm8yzboMjeDm5Akg1R1N.5w42b4EWH4vQE1.Fj.Pe', NULL, NULL, 'patient', '2025-10-22 13:46:38', '2025-11-20 17:55:43', NULL, NULL, NULL, NULL, 1),
(40, 'mayi', 'ayizaki2004@gmail.com', '$2y$10$jd3Nesd0GoCB2UGnxoJ8G.hKSm8PKi9ngZRE1D5B9SmqjUcD2Drca', NULL, NULL, 'patient', '2025-12-14 15:06:00', '2025-12-14 15:06:20', NULL, NULL, NULL, NULL, 1),
(42, 'etan01', 'tantanlegaspi01@gmail.com', '$2y$10$6Z5UrznfWMGU5fhvENTC8O86RYbyYNweK8zRT.mLgPjj/OqTIgPJC', '404214', '2025-12-16 03:59:43', 'patient', '2025-12-14 19:08:55', '2025-12-16 03:49:43', NULL, NULL, NULL, NULL, 1),
(46, 'Jeorgepatient', 'jeorgeanthony1738@gmail.com', '$2y$10$Cw892H4302lkavA5Go5Use7bfXTTUR0BybM8K9syYcU5ucLT16vdy', NULL, NULL, 'patient', '2025-12-15 23:13:07', '2025-12-15 23:13:49', NULL, NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `action_type` varchar(50) NOT NULL COMMENT 'e.g., Login, Logout, Record Added',
  `timestamp` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_logs`
--

INSERT INTO `user_logs` (`log_id`, `user_id`, `username`, `action_type`, `timestamp`, `ip_address`) VALUES
(1, 5, 'Andrei', 'Successful Login', '2025-11-15 02:00:13', '::1'),
(2, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 02:00:21', '::1'),
(3, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 02:35:03', '::1'),
(4, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 02:38:37', '::1'),
(5, 5, 'Andrei', 'Successful Login', '2025-11-15 02:38:40', '::1'),
(6, NULL, 'jeorge', 'Failed Login Attempt', '2025-11-15 02:40:29', '::1'),
(7, 5, 'Andrei', 'Successful Login', '2025-11-15 02:40:51', '::1'),
(8, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 02:40:56', '::1'),
(9, 5, 'Andrei', 'Successful Login', '2025-11-15 03:17:48', '::1'),
(10, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 03:18:22', '::1'),
(11, 5, 'Andrei', 'Successful Login', '2025-11-15 03:20:05', '::1'),
(12, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 03:20:57', '::1'),
(13, 5, 'Andrei', 'Successful Login', '2025-11-15 03:24:33', '::1'),
(14, 3, 'Trishia Mayi', 'Successful Login', '2025-11-15 03:29:21', '::1'),
(15, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 03:29:34', '::1'),
(16, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 03:46:27', '::1'),
(17, NULL, 'yap123', 'Failed Login Attempt', '2025-11-15 03:46:51', '::1'),
(18, 16, 'yap123', 'Successful Login', '2025-11-15 03:48:14', '::1'),
(19, 15, 'yapyap', 'Successful Login', '2025-11-15 04:25:05', '::1'),
(20, NULL, 'Trishia Mayi', 'Failed Login Attempt', '2025-11-15 04:52:16', '::1'),
(21, 3, 'Trishia Mayi', 'OTP Sent', '2025-11-15 04:52:46', '::1'),
(22, 3, 'Trishia Mayi', 'OTP Verified', '2025-11-15 04:52:56', '::1'),
(23, 3, 'Trishia Mayi', 'Password Reset Successful', '2025-11-15 04:53:07', '::1'),
(24, 3, 'Trishia Mayi', 'Successful Login', '2025-11-15 04:53:17', '::1'),
(25, 5, 'Andrei', 'Successful Login', '2025-11-15 05:55:53', '::1'),
(26, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 06:49:39', '::1'),
(27, 5, 'Andrei', 'Successful Login', '2025-11-15 07:28:17', '::1'),
(28, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 07:34:42', '::1'),
(29, 5, 'Andrei', 'Successful Login', '2025-11-15 07:37:15', '::1'),
(30, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 07:39:15', '::1'),
(31, 15, 'yapyap', 'Successful Login', '2025-11-15 12:21:28', '::1'),
(32, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 13:45:31', '::1'),
(33, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 13:46:24', '::1'),
(34, 5, 'Andrei', 'Successful Login', '2025-11-15 13:46:38', '::1'),
(35, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 13:50:33', '::1'),
(36, 5, 'Andrei', 'Successful Login', '2025-11-15 13:50:57', '::1'),
(37, NULL, 'yap123', 'Failed Login Attempt', '2025-11-15 13:53:19', '::1'),
(38, 5, 'Andrei', 'Successful Login', '2025-11-15 13:53:24', '::1'),
(39, 5, 'Andrei', 'Successful Login', '2025-11-15 14:14:33', '::1'),
(40, 5, 'Andrei', 'Successful Login', '2025-11-15 14:50:41', '::1'),
(41, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 14:53:07', '::1'),
(42, NULL, 'Trishia Mayi', 'Failed Login Attempt', '2025-11-15 14:53:58', '::1'),
(43, 3, 'Trishia Mayi', 'Successful Login', '2025-11-15 14:54:11', '::1'),
(44, 5, 'Andrei', 'Successful Login', '2025-11-15 15:01:19', '::1'),
(45, NULL, 'yap123', 'Failed Login Attempt', '2025-11-15 15:10:37', '::1'),
(46, 5, 'Andrei', 'Successful Login', '2025-11-15 15:10:44', '::1'),
(47, 10, 'Admin Mayi', 'Successful Login', '2025-11-15 15:16:10', '::1'),
(48, 5, 'Andrei', 'Successful Login', '2025-11-15 15:22:08', '::1'),
(49, 3, 'Trishia Mayi', 'Successful Login', '2025-11-15 15:58:34', '::1'),
(50, 5, 'Andrei', 'Successful Login', '2025-11-20 01:23:24', '::1'),
(51, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 15:20:46', '::1'),
(52, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 15:43:33', '::1'),
(53, 3, 'Trishia Mayi', 'Successful Login', '2025-11-20 15:43:38', '::1'),
(54, 5, 'Andrei', 'Successful Login', '2025-11-20 15:43:41', '::1'),
(55, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 15:43:49', '::1'),
(56, 5, 'Andrei', 'Successful Login', '2025-11-20 15:43:58', '::1'),
(57, 25, 'ayazaki', 'Successful Login', '2025-11-20 17:42:39', '::1'),
(58, 5, 'Andrei', 'Failed Login (Unverified Account)', '2025-11-20 17:47:53', '::1'),
(59, 26, 'ayizaki', 'Successful Login', '2025-11-20 17:49:26', '::1'),
(60, 10, 'Admin Mayi', 'Failed Login (Unverified Account)', '2025-11-20 17:49:52', '::1'),
(61, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 17:54:44', '::1'),
(62, 26, 'ayizaki', 'Successful Login', '2025-11-20 17:54:48', '::1'),
(63, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 17:54:54', '::1'),
(64, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 18:12:56', '::1'),
(65, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 21:02:06', '::1'),
(66, 5, 'Andrei', 'Successful Login', '2025-11-20 21:12:15', '::1'),
(67, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 21:19:41', '::1'),
(68, 5, 'Andrei', 'Successful Login', '2025-11-20 21:22:56', '::1'),
(69, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 21:32:34', '::1'),
(70, 10, 'Admin Mayi', 'Successful Login', '2025-11-20 23:27:34', '::1'),
(71, 5, 'Andrei', 'Successful Login', '2025-11-20 23:46:42', '::1'),
(72, 5, 'Andrei', 'Successful Login', '2025-11-23 19:57:26', '::1'),
(73, 10, 'Admin Mayi', 'Successful Login', '2025-11-23 20:08:12', '::1'),
(74, 26, 'ayizaki', 'Successful Login', '2025-11-23 20:09:28', '::1'),
(75, 5, 'Andrei', 'Successful Login', '2025-11-23 20:14:45', '::1'),
(76, 26, 'ayizaki', 'Successful Login', '2025-11-23 20:16:02', '::1'),
(77, 10, 'Admin Mayi', 'Successful Login', '2025-11-23 20:19:25', '::1'),
(78, 26, 'ayizaki', 'Successful Login', '2025-11-23 20:21:12', '::1'),
(79, 5, 'Andrei', 'Successful Login', '2025-11-23 20:28:25', '::1'),
(80, 26, 'ayizaki', 'Successful Login', '2025-11-23 20:55:17', '::1'),
(81, 3, 'Trishia Mayi', 'Successful Login', '2025-11-23 20:57:20', '::1'),
(82, 5, 'Andrei', 'Successful Login', '2025-11-23 21:07:19', '::1'),
(83, 26, 'ayizaki', 'Successful Login', '2025-11-23 21:07:25', '::1'),
(84, 3, 'Trishia Mayi', 'Successful Login', '2025-11-23 21:09:24', '::1'),
(85, 26, 'ayizaki', 'Successful Login', '2025-11-23 21:12:35', '::1'),
(86, 5, 'Andrei', 'Successful Login', '2025-11-23 21:20:00', '::1'),
(87, 26, 'ayizaki', 'Successful Login', '2025-11-23 21:22:00', '::1'),
(88, 3, 'Trishia Mayi', 'Successful Login', '2025-11-23 23:15:38', '::1'),
(89, 5, 'Andrei', 'Successful Login', '2025-11-26 12:37:11', '::1'),
(90, 10, 'Admin Mayi', 'Successful Login', '2025-11-26 12:39:52', '::1'),
(91, 5, 'Andrei', 'Successful Login', '2025-11-26 12:43:34', '::1'),
(92, 26, 'ayizaki', 'Successful Login', '2025-11-26 12:46:01', '::1'),
(93, 5, 'Andrei', 'Successful Login', '2025-11-26 12:54:44', '::1'),
(94, 26, 'ayizaki', 'Successful Login', '2025-11-26 13:03:27', '::1'),
(95, 10, 'Admin Mayi', 'Successful Login', '2025-11-26 13:03:49', '::1'),
(96, 5, 'Andrei', 'Successful Login', '2025-11-26 13:05:02', '::1'),
(97, 27, 'analizel', 'Successful Login', '2025-11-26 13:12:00', '::1'),
(98, 27, 'analizel', 'Successful Login', '2025-11-26 13:12:07', '::1'),
(99, 26, 'ayizaki', 'Successful Login', '2025-11-26 13:12:29', '::1'),
(100, 27, 'analizel', 'Successful Login', '2025-11-26 13:13:43', '::1'),
(101, 27, 'analizel', 'Successful Login', '2025-11-26 13:15:08', '::1'),
(102, 5, 'Andrei', 'Successful Login', '2025-11-26 13:15:26', '::1'),
(103, 10, 'Admin Mayi', 'Successful Login', '2025-11-26 13:24:43', '::1'),
(104, 27, 'analizel', 'Successful Login', '2025-11-26 13:25:57', '::1'),
(105, 10, 'Admin Mayi', 'Successful Login', '2025-11-26 13:31:00', '::1'),
(106, 5, 'Andrei', 'Successful Login', '2025-11-27 00:50:38', '127.0.0.1'),
(107, 3, 'Trishia Mayi', 'Successful Login', '2025-11-27 01:07:40', '::1'),
(108, 5, 'Andrei', 'Successful Login', '2025-11-27 01:10:35', '::1'),
(109, 26, 'ayizaki', 'Successful Login', '2025-11-27 01:11:33', '::1'),
(110, 5, 'Andrei', 'Successful Login', '2025-11-27 01:15:31', '::1'),
(111, 3, 'Trishia Mayi', 'Successful Login', '2025-11-27 01:16:21', '::1'),
(112, 5, 'Andrei', 'Successful Login', '2025-11-27 01:16:42', '::1'),
(113, 26, 'ayizaki', 'Successful Login', '2025-11-27 01:18:16', '::1'),
(114, 5, 'Andrei', 'Successful Login', '2025-11-27 01:19:10', '::1'),
(115, 26, 'ayizaki', 'Successful Login', '2025-11-27 01:24:45', '::1'),
(116, 5, 'Andrei', 'Successful Login', '2025-11-27 01:25:33', '::1'),
(117, 5, 'Andrei', 'Successful Login', '2025-11-27 04:04:40', '::1'),
(118, 26, 'ayizaki', 'Successful Login', '2025-11-27 04:10:56', '::1'),
(119, 26, 'ayizaki', 'Successful Login', '2025-11-27 04:11:38', '::1'),
(120, 5, 'Andrei', 'Successful Login', '2025-11-27 04:11:52', '::1'),
(121, 3, 'Trishia Mayi', 'Successful Login', '2025-11-27 04:12:07', '::1'),
(122, 26, 'ayizaki', 'Successful Login', '2025-11-27 22:27:27', '::1'),
(123, 5, 'Andrei', 'Successful Login', '2025-11-27 22:39:35', '::1'),
(124, 26, 'ayizaki', 'Successful Login', '2025-11-27 22:41:13', '::1'),
(125, 5, 'Andrei', 'Successful Login', '2025-11-27 22:41:27', '::1'),
(126, 26, 'ayizaki', 'Successful Login', '2025-11-27 23:26:49', '::1'),
(127, 26, 'ayizaki', 'Successful Login', '2025-11-27 23:34:38', '::1'),
(128, 26, 'ayizaki', 'Successful Login', '2025-11-27 23:35:30', '::1'),
(129, 3, 'Trishia Mayi', 'Successful Login', '2025-11-28 00:11:11', '::1'),
(130, 5, 'Andrei', 'Successful Login', '2025-11-28 00:11:28', '::1'),
(131, 26, 'ayizaki', 'Successful Login', '2025-11-28 01:38:56', '::1'),
(132, 26, 'ayizaki', 'Successful Login', '2025-11-28 01:42:43', '::1'),
(133, 26, 'ayizaki', 'Successful Login', '2025-11-28 01:42:44', '::1'),
(134, 26, 'ayizaki', 'Successful Login', '2025-11-28 01:42:45', '::1'),
(135, 26, 'ayizaki', 'Successful Login', '2025-11-28 01:42:59', '::1'),
(136, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 01:53:43', '::1'),
(137, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 02:03:02', '::1'),
(138, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 02:03:12', '::1'),
(139, 26, 'ayizaki', 'Successful Login', '2025-11-28 02:03:26', '::1'),
(140, 5, 'Andrei', 'Successful Login', '2025-11-28 02:06:20', '::1'),
(141, 26, 'ayizaki', 'Successful Login', '2025-11-28 03:36:41', '::1'),
(142, 5, 'Andrei', 'Successful Login', '2025-11-28 03:37:58', '::1'),
(143, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:21:50', '::1'),
(144, 3, 'Trishia Mayi', 'Successful Login', '2025-11-28 13:21:57', '::1'),
(145, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:27:23', '::1'),
(146, 3, 'Trishia Mayi', 'Successful Login', '2025-11-28 13:27:46', '::1'),
(147, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:28:30', '::1'),
(148, 27, 'analizel', 'Successful Login', '2025-11-28 13:28:36', '::1'),
(149, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:32:32', '::1'),
(150, 27, 'analizel', 'Successful Login', '2025-11-28 13:35:43', '::1'),
(151, 5, 'Andrei', 'Successful Login', '2025-11-28 13:37:38', '::1'),
(152, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:39:39', '::1'),
(153, 5, 'Andrei', 'Successful Login', '2025-11-28 13:40:05', '::1'),
(154, 26, 'ayizaki', 'Successful Login', '2025-11-28 13:55:29', '::1'),
(155, 5, 'Andrei', 'Successful Login', '2025-11-28 14:06:21', '::1'),
(156, 26, 'ayizaki', 'Successful Login', '2025-11-28 14:09:07', '::1'),
(157, 5, 'Andrei', 'Successful Login', '2025-11-28 14:09:54', '::1'),
(158, 26, 'ayizaki', 'Successful Login', '2025-11-28 15:00:52', '::1'),
(159, 5, 'Andrei', 'Successful Login', '2025-11-28 15:01:53', '::1'),
(160, 28, 'Mayix', 'Successful Login', '2025-11-28 15:12:30', '::1'),
(161, 5, 'Andrei', 'Successful Login', '2025-11-28 15:23:14', '::1'),
(162, 28, 'Mayix', 'Successful Login', '2025-11-28 15:32:24', '::1'),
(163, 5, 'Andrei', 'Successful Login', '2025-11-28 15:36:27', '::1'),
(164, 5, 'Andrei', 'Auto-Login', '2025-11-28 20:00:13', '::1'),
(165, 5, 'Andrei', 'Successful Login', '2025-11-28 20:31:56', '::1'),
(166, 5, 'Andrei', 'Successful Login', '2025-11-28 20:57:45', '::1'),
(167, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 21:09:12', '::1'),
(168, 29, 'Ivsei', 'Successful Login', '2025-11-28 21:50:55', '::1'),
(169, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 21:51:02', '::1'),
(170, 5, 'Andrei', 'Successful Login', '2025-11-28 22:33:17', '::1'),
(171, 28, 'Mayix', 'Successful Login', '2025-11-28 22:47:37', '::1'),
(172, 5, 'Andrei', 'Successful Login', '2025-11-28 23:11:33', '::1'),
(173, 26, 'ayizaki', 'Successful Login', '2025-11-28 23:15:18', '::1'),
(174, 5, 'Andrei', 'Successful Login', '2025-11-28 23:16:37', '::1'),
(175, 28, 'Mayix', 'Successful Login', '2025-11-28 23:19:12', '::1'),
(176, 5, 'Andrei', 'Successful Login', '2025-11-28 23:23:02', '::1'),
(177, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 23:24:02', '::1'),
(178, 5, 'Andrei', 'Successful Login', '2025-11-28 23:26:24', '::1'),
(179, 10, 'Admin Mayi', 'Successful Login', '2025-11-28 23:45:19', '::1'),
(180, 5, 'Andrei', 'Successful Login', '2025-11-28 23:45:23', '::1'),
(181, 28, 'Mayix', 'Successful Login', '2025-11-28 23:51:15', '::1'),
(182, 5, 'Andrei', 'Successful Login', '2025-11-28 23:54:00', '::1'),
(183, 28, 'Mayix', 'Successful Login', '2025-11-28 23:55:21', '::1'),
(184, 5, 'Andrei', 'Successful Login', '2025-11-29 00:07:54', '::1'),
(185, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 00:51:58', '::1'),
(186, 5, 'Andrei', 'Successful Login', '2025-11-29 00:52:15', '::1'),
(187, 5, 'Andrei', 'Successful Login', '2025-11-29 01:27:07', '::1'),
(188, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 10:31:33', '::1'),
(189, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 10:51:40', '::1'),
(190, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 11:24:42', '::1'),
(191, 28, 'Mayix', 'OTP Sent', '2025-11-29 12:08:24', '::1'),
(192, 28, 'Mayix', 'OTP Verified', '2025-11-29 12:08:47', '::1'),
(193, 28, 'Mayix', 'Password Reset Successful', '2025-11-29 12:09:06', '::1'),
(194, NULL, 'Admin Mayi', 'Failed Login Attempt', '2025-11-29 12:09:09', '::1'),
(195, NULL, 'Admin Mayi', 'Failed Login Attempt', '2025-11-29 12:09:15', '::1'),
(196, NULL, 'Admin Mayi', 'Failed Login Attempt', '2025-11-29 12:09:16', '::1'),
(197, 10, 'Admin Mayi', 'OTP Sent', '2025-11-29 12:09:48', '::1'),
(198, 10, 'Admin Mayi', 'OTP Verified', '2025-11-29 12:10:03', '::1'),
(199, 10, 'Admin Mayi', 'Password Reset Successful', '2025-11-29 12:10:14', '::1'),
(200, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 12:10:17', '::1'),
(201, 10, 'Admin Mayi', 'Successful Login', '2025-11-29 12:36:23', '::1'),
(202, 34, 'ivseibaho', 'Successful Login', '2025-11-29 13:37:47', '::1'),
(203, 5, 'Andrei', 'Successful Login', '2025-11-29 20:47:08', '::1'),
(204, 26, 'ayizaki', 'Successful Login', '2025-11-29 22:14:18', '::1'),
(205, 3, 'Trishia Mayi', 'Successful Login', '2025-11-29 22:14:52', '::1'),
(206, 26, 'ayizaki', 'Successful Login', '2025-11-29 22:17:21', '::1'),
(207, 5, 'Andrei', 'Successful Login', '2025-11-29 22:19:49', '::1'),
(208, 5, 'Andrei', 'Successful Login', '2025-11-29 22:24:28', '::1'),
(209, 36, 'Christine', 'Successful Login', '2025-11-29 22:28:23', '::1'),
(210, 36, 'Christine', 'Successful Login', '2025-11-29 22:32:22', '::1'),
(211, 26, 'ayizaki', 'Successful Login', '2025-11-29 23:46:09', '::1'),
(212, 36, 'Christine', 'Successful Login', '2025-11-29 23:48:24', '::1'),
(213, 5, 'Andrei', 'Successful Login', '2025-11-29 23:51:30', '::1'),
(214, 36, 'Christine', 'Successful Login', '2025-11-30 00:00:08', '::1'),
(215, 37, 'Irishmae', 'Successful Login', '2025-11-30 00:41:23', '::1'),
(216, NULL, 'ayizaki', 'Failed Login Attempt', '2025-12-03 00:30:48', '::1'),
(217, NULL, 'ayizaki', 'Failed Login Attempt', '2025-12-03 00:31:10', '::1'),
(218, 26, 'ayizaki', 'Successful Login', '2025-12-03 00:31:25', '::1'),
(219, 3, 'Trishia Mayi', 'Successful Login', '2025-12-03 14:21:35', '::1'),
(220, 26, 'ayizaki', 'Successful Login', '2025-12-03 14:22:22', '::1'),
(221, 3, 'Trishia Mayi', 'Successful Login', '2025-12-03 14:50:23', '::1'),
(222, 26, 'ayizaki', 'Successful Login', '2025-12-03 14:50:41', '::1'),
(223, 3, 'Trishia Mayi', 'Successful Login', '2025-12-03 15:54:46', '::1'),
(224, 26, 'ayizaki', 'Successful Login', '2025-12-03 15:56:53', '::1'),
(225, NULL, 'ayizaki', 'Failed Login Attempt', '2025-12-03 16:58:47', '::1'),
(226, 26, 'ayizaki', 'Successful Login', '2025-12-03 16:59:00', '::1'),
(227, 26, 'ayizaki', 'Successful Login', '2025-12-12 23:55:10', '::1'),
(228, 5, 'Andrei', 'Successful Login', '2025-12-13 00:20:38', '::1'),
(229, 26, 'ayizaki', 'Successful Login', '2025-12-13 00:28:10', '::1'),
(230, NULL, 'Andrei', 'Failed Login Attempt', '2025-12-13 00:40:06', '::1'),
(231, 5, 'Andrei', 'Successful Login', '2025-12-13 00:40:15', '::1'),
(232, 26, 'ayizaki', 'Successful Login', '2025-12-13 01:44:10', '::1'),
(233, 26, 'ayizaki', 'Auto-Login', '2025-12-13 02:20:44', '::1'),
(234, 26, 'ayizaki', 'Auto-Login', '2025-12-13 02:20:47', '::1'),
(235, 5, 'Andrei', 'Successful Login', '2025-12-13 02:21:08', '::1'),
(236, 3, 'Trishia Mayi', 'Successful Login', '2025-12-13 02:44:13', '::1'),
(237, 10, 'Admin Mayi', 'Successful Login', '2025-12-13 02:48:54', '::1'),
(238, 26, 'ayizaki', 'Successful Login', '2025-12-13 04:12:54', '::1'),
(239, 5, 'Andrei', 'Successful Login', '2025-12-13 04:13:28', '::1'),
(240, 26, 'ayizaki', 'Successful Login', '2025-12-13 12:09:48', '::1'),
(241, 5, 'Andrei', 'Successful Login', '2025-12-13 12:12:52', '::1'),
(242, 26, 'ayizaki', 'Successful Login', '2025-12-13 13:49:47', '::1'),
(243, 5, 'Andrei', 'Successful Login', '2025-12-13 13:50:50', '::1'),
(244, 10, 'Admin Mayi', 'Successful Login', '2025-12-13 14:00:59', '::1'),
(245, 5, 'Andrei', 'Successful Login', '2025-12-13 14:02:51', '::1'),
(246, 5, 'Andrei', 'Successful Login', '2025-12-13 14:07:00', '::1'),
(247, NULL, 'patient1', 'Failed Login Attempt', '2025-12-13 14:19:30', '::1'),
(248, 37, 'Irishmae', 'Successful Login', '2025-12-13 14:19:46', '::1'),
(249, 5, 'Andrei', 'Successful Login', '2025-12-13 14:20:46', '::1'),
(250, 37, 'Irishmae', 'Successful Login', '2025-12-13 15:14:41', '::1'),
(251, 5, 'Andrei', 'Successful Login', '2025-12-13 15:15:27', '::1'),
(252, 5, 'Andrei', 'Successful Login', '2025-12-13 21:37:22', '::1'),
(253, 5, 'Andrei', 'Successful Login', '2025-12-13 21:44:52', '::1'),
(254, 38, 'ish', 'Successful Login', '2025-12-13 23:02:34', '::1'),
(255, 5, 'Andrei', 'Successful Login', '2025-12-13 23:03:49', '::1'),
(256, NULL, 'Irishmae', 'Failed Login Attempt', '2025-12-14 00:31:04', '::1'),
(257, 38, 'ish', 'Successful Login', '2025-12-14 00:31:07', '::1'),
(258, 10, 'Admin Mayi', 'Successful Login', '2025-12-14 00:32:41', '::1'),
(259, 10, 'Admin Mayi', 'Successful Login', '2025-12-14 00:36:29', '::1'),
(260, 10, 'Admin Mayi', 'Successful Login', '2025-12-14 00:39:07', '::1'),
(261, 10, 'Admin Mayi', 'Successful Login', '2025-12-14 09:57:05', '::1'),
(262, 5, 'Andrei', 'Successful Login', '2025-12-14 10:00:55', '::1'),
(263, 38, 'ish', 'Successful Login', '2025-12-14 10:02:12', '::1'),
(264, 5, 'Andrei', 'Successful Login', '2025-12-14 10:02:58', '::1'),
(265, NULL, 'Iya2004', 'Failed Login Attempt', '2025-12-14 11:01:14', '::1'),
(266, 5, 'Andrei', 'Successful Login', '2025-12-14 11:01:24', '::1'),
(267, 38, 'ish', 'Successful Login', '2025-12-14 11:16:58', '::1'),
(268, 5, 'Andrei', 'Successful Login', '2025-12-14 11:17:39', '::1'),
(269, 26, 'ayizaki', 'Successful Login', '2025-12-14 13:21:27', '::1'),
(270, 5, 'Andrei', 'Successful Login', '2025-12-14 13:22:33', '::1'),
(271, 39, 'ayizaki', 'Successful Login', '2025-12-14 13:29:34', '::1'),
(272, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:29', '::1'),
(273, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:31', '::1'),
(274, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:32', '::1'),
(275, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:37', '::1'),
(276, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:39', '::1'),
(277, 39, 'ayizaki', 'Auto-Login', '2025-12-14 13:30:39', '::1'),
(278, NULL, 'Unknown/Invalid Token', 'Failed Auto-Login', '2025-12-14 13:30:40', '::1'),
(279, 5, 'Andrei', 'Successful Login', '2025-12-14 13:30:42', '::1'),
(280, 5, 'Andrei', 'Successful Login', '2025-12-14 13:55:24', '::1'),
(281, 39, 'ayizaki', 'Successful Login', '2025-12-14 14:06:24', '::1'),
(282, 5, 'Andrei', 'Successful Login', '2025-12-14 14:07:40', '::1'),
(283, 40, 'mayi', 'Successful Login', '2025-12-14 15:06:27', '::1'),
(284, 5, 'Andrei', 'Successful Login', '2025-12-14 15:07:17', '::1'),
(285, 40, 'mayi', 'Successful Login', '2025-12-14 16:30:45', '::1'),
(286, 5, 'Andrei', 'Successful Login', '2025-12-14 16:31:14', '::1'),
(287, 3, 'Trishia Mayi', 'Successful Login', '2025-12-14 17:27:44', '::1'),
(288, 10, 'Admin Mayi', 'Successful Login', '2025-12-14 17:28:12', '::1'),
(289, 5, 'Andrei', 'Successful Login', '2025-12-14 17:28:56', '::1'),
(290, 5, 'Andrei', 'Successful Login', '2025-12-14 17:53:31', '::1'),
(291, 41, 'etan01', 'Successful Login', '2025-12-14 18:47:54', '::1'),
(292, 5, 'Andrei', 'Successful Login', '2025-12-14 18:49:06', '::1'),
(293, 41, 'etan01', 'Successful Login', '2025-12-14 19:08:12', '::1'),
(294, 42, 'etan01', 'Successful Login', '2025-12-14 19:09:19', '::1'),
(295, 5, 'Andrei', 'Successful Login', '2025-12-14 19:10:04', '::1'),
(296, 43, 'aki', 'Successful Login', '2025-12-14 20:05:22', '::1'),
(297, 5, 'Andrei', 'Successful Login', '2025-12-14 20:05:59', '::1'),
(298, 43, 'aki', 'Successful Login', '2025-12-14 21:15:36', '::1'),
(299, 5, 'Andrei', 'Successful Login', '2025-12-14 21:15:52', '::1'),
(300, 43, 'aki', 'Successful Login', '2025-12-14 21:37:40', '::1'),
(301, 5, 'Andrei', 'Successful Login', '2025-12-14 21:39:38', '::1'),
(302, NULL, 'aki', 'Failed Login Attempt', '2025-12-14 21:57:56', '::1'),
(303, 42, 'etan01', 'Successful Login', '2025-12-14 21:57:59', '::1'),
(304, 5, 'Andrei', 'Successful Login', '2025-12-14 22:00:46', '::1'),
(305, 42, 'etan01', 'Successful Login', '2025-12-14 22:02:56', '::1'),
(306, 40, 'mayi', 'Successful Login', '2025-12-14 22:03:44', '::1'),
(307, 5, 'Andrei', 'Successful Login', '2025-12-14 22:04:45', '::1'),
(308, 44, 'Ishia', 'Successful Login', '2025-12-14 22:18:47', '::1'),
(309, 5, 'Andrei', 'Successful Login', '2025-12-14 22:19:27', '::1'),
(310, 44, 'Ishia', 'Successful Login', '2025-12-14 23:22:13', '::1'),
(311, 5, 'Andrei', 'Successful Login', '2025-12-14 23:31:59', '::1'),
(312, 44, 'Ishia', 'Successful Login', '2025-12-14 23:32:19', '::1'),
(313, 5, 'Andrei', 'Successful Login', '2025-12-14 23:58:27', '::1'),
(314, 44, 'Ishia', 'Successful Login', '2025-12-15 00:01:15', '::1'),
(315, 44, 'Ishia', 'Successful Login', '2025-12-15 00:03:23', '::1'),
(316, 40, 'mayi', 'Successful Login', '2025-12-15 00:12:32', '::1'),
(317, 5, 'Andrei', 'Successful Login', '2025-12-15 00:13:31', '::1'),
(318, 40, 'mayi', 'Successful Login', '2025-12-15 00:13:58', '::1'),
(319, 44, 'Ishia', 'Successful Login', '2025-12-15 00:14:25', '::1'),
(320, 3, 'Trishia Mayi', 'Successful Login', '2025-12-15 00:19:08', '::1'),
(321, 44, 'Ishia', 'Successful Login', '2025-12-15 00:19:44', '::1'),
(322, 40, 'mayi', 'Successful Login', '2025-12-15 00:19:52', '::1'),
(323, 5, 'Andrei', 'Successful Login', '2025-12-15 00:59:37', '::1'),
(324, 45, 'Ishimae', 'Successful Login', '2025-12-15 03:12:30', '::1'),
(325, 5, 'Andrei', 'Successful Login', '2025-12-15 03:13:24', '::1'),
(326, 45, 'Ishimae', 'Successful Login', '2025-12-15 14:55:37', '::1'),
(327, 5, 'Andrei', 'Successful Login', '2025-12-15 14:55:45', '::1'),
(328, 5, 'Andrei', 'Successful Login', '2025-12-15 14:55:59', '::1'),
(329, 5, 'Andrei', 'Successful Login', '2025-12-15 14:57:33', '::1'),
(330, 45, 'Ishimae', 'Successful Login', '2025-12-15 14:58:06', '::1'),
(331, 45, 'Ishimae', 'Successful Login', '2025-12-15 14:59:39', '::1'),
(332, 5, 'Andrei', 'Successful Login', '2025-12-15 14:59:43', '::1'),
(333, 5, 'Andrei', 'Successful Login', '2025-12-15 15:08:35', '::1'),
(334, 45, 'Ishimae', 'Successful Login', '2025-12-15 15:14:31', '::1'),
(335, 5, 'Andrei', 'Successful Login', '2025-12-15 15:18:32', '::1'),
(336, 45, 'Ishimae', 'Successful Login', '2025-12-15 15:26:30', '::1'),
(337, 45, 'Ishimae', 'Successful Login', '2025-12-15 15:26:33', '::1'),
(338, 5, 'Andrei', 'Successful Login', '2025-12-15 15:26:53', '::1'),
(339, 5, 'Andrei', 'Successful Login', '2025-12-15 19:09:23', '::1'),
(340, 5, 'Andrei', 'Successful Login', '2025-12-15 19:52:24', '::1'),
(341, 5, 'Andrei', 'Successful Login', '2025-12-15 22:00:41', '::1'),
(342, 5, 'Andrei', 'Successful Login', '2025-12-15 22:01:17', '::1'),
(343, 45, 'Ishimae', 'Successful Login', '2025-12-15 22:57:45', '::1'),
(344, 5, 'Andrei', 'Successful Login', '2025-12-15 22:58:17', '::1'),
(345, 45, 'Ishimae', 'Successful Login', '2025-12-15 22:59:14', '::1'),
(346, 45, 'Ishimae', 'Successful Login', '2025-12-15 22:59:14', '::1'),
(347, 5, 'Andrei', 'Successful Login', '2025-12-15 22:59:57', '::1'),
(348, 46, 'Jeorgepatient', 'Successful Login', '2025-12-15 23:13:59', '::1'),
(349, 5, 'Andrei', 'Successful Login', '2025-12-15 23:14:38', '::1'),
(350, 10, 'Admin Mayi', 'Successful Login', '2025-12-15 23:19:28', '::1'),
(351, 45, 'Ishimae', 'Successful Login', '2025-12-15 23:19:37', '::1'),
(352, 5, 'Andrei', 'Successful Login', '2025-12-15 23:21:37', '::1'),
(353, 10, 'Admin Mayi', 'Successful Login', '2025-12-15 23:30:26', '::1'),
(354, 5, 'Andrei', 'Successful Login', '2025-12-15 23:30:41', '::1'),
(355, 46, 'Jeorgepatient', 'Successful Login', '2025-12-16 00:12:15', '::1'),
(356, 5, 'Andrei', 'Successful Login', '2025-12-16 00:13:19', '::1'),
(357, NULL, 'cristan', 'Failed Login Attempt', '2025-12-16 00:18:27', '::1'),
(358, NULL, 'Irishmae', 'Failed Login Attempt', '2025-12-16 00:18:33', '::1'),
(359, 45, 'Ishimae', 'Successful Login', '2025-12-16 00:18:51', '::1'),
(360, 5, 'Andrei', 'Successful Login', '2025-12-16 00:19:38', '::1'),
(361, 42, 'etan01', 'Successful Login', '2025-12-16 00:29:22', '::1'),
(362, 5, 'Andrei', 'Successful Login', '2025-12-16 00:29:51', '::1'),
(363, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 01:15:35', '::1'),
(364, 5, 'Andrei', 'Successful Login', '2025-12-16 01:15:40', '::1'),
(365, 42, 'etan01', 'Successful Login', '2025-12-16 02:55:15', '::1'),
(366, 5, 'Andrei', 'Successful Login', '2025-12-16 02:56:34', '::1'),
(367, 5, 'Andrei', 'Successful Login', '2025-12-16 03:23:13', '::1'),
(368, 5, 'Andrei', 'Successful Login', '2025-12-16 03:29:05', '::1'),
(369, 5, 'Andrei', 'Successful Login', '2025-12-16 03:31:31', '::1'),
(370, 5, 'Andrei', 'Successful Login', '2025-12-16 03:35:09', '::1'),
(371, 5, 'Andrei', 'Successful Login', '2025-12-16 03:36:47', '::1'),
(372, 5, 'Andrei', 'Successful Login', '2025-12-16 03:36:50', '::1'),
(373, 5, 'Andrei', 'Successful Login', '2025-12-16 03:37:20', '::1'),
(374, 5, 'Andrei', 'Successful Login', '2025-12-16 03:37:43', '::1'),
(375, 5, 'Andrei', 'Successful Login', '2025-12-16 03:45:12', '::1'),
(376, 5, 'Andrei', 'Successful Login', '2025-12-16 03:45:26', '::1'),
(377, 5, 'Andrei', 'Successful Login', '2025-12-16 03:45:30', '::1'),
(378, 5, 'Andrei', 'Successful Login', '2025-12-16 03:46:27', '::1'),
(379, 5, 'Andrei', 'Successful Login', '2025-12-16 03:46:59', '::1'),
(380, 5, 'Andrei', 'Successful Login', '2025-12-16 03:47:03', '::1'),
(381, 42, 'etan01', 'OTP Sent', '2025-12-16 03:49:43', '::1'),
(382, 5, 'Andrei', 'Successful Login', '2025-12-16 03:49:51', '::1'),
(383, 5, 'Andrei', 'Successful Login', '2025-12-16 03:50:02', '::1'),
(384, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 03:50:48', '::1'),
(385, 5, 'Andrei', 'Successful Login', '2025-12-16 03:51:36', '::1'),
(386, 42, 'etan01', 'Successful Login', '2025-12-16 04:00:26', '::1'),
(387, 5, 'Andrei', 'Successful Login', '2025-12-16 04:02:26', '::1'),
(388, 42, 'etan01', 'Successful Login', '2025-12-16 04:04:56', '::1'),
(389, 5, 'Andrei', 'Successful Login', '2025-12-16 04:06:36', '::1'),
(390, 5, 'Andrei', 'Successful Login', '2025-12-16 04:13:41', '::1'),
(391, 42, 'etan01', 'Successful Login', '2025-12-16 04:14:48', '::1'),
(392, 5, 'Andrei', 'Successful Login', '2025-12-16 04:21:40', '::1'),
(393, 42, 'etan01', 'Successful Login', '2025-12-16 04:22:13', '::1'),
(394, 5, 'Andrei', 'Successful Login', '2025-12-16 04:25:12', '::1'),
(395, 42, 'etan01', 'Successful Login', '2025-12-16 04:25:20', '::1'),
(396, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 04:30:30', '::1'),
(397, 42, 'etan01', 'Successful Login', '2025-12-16 04:30:45', '::1'),
(398, 42, 'etan01', 'Successful Login', '2025-12-16 04:44:30', '::1'),
(399, 5, 'Andrei', 'Successful Login', '2025-12-16 04:46:32', '::1'),
(400, 42, 'etan01', 'Successful Login', '2025-12-16 04:49:17', '::1'),
(401, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 04:56:17', '::1'),
(402, 42, 'etan01', 'Successful Login', '2025-12-16 04:56:56', '::1'),
(403, 5, 'Andrei', 'Successful Login', '2025-12-16 04:59:19', '::1'),
(404, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 04:59:29', '::1'),
(405, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 04:59:41', '::1'),
(406, 5, 'Andrei', 'Successful Login', '2025-12-16 05:00:06', '::1'),
(407, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:01:35', '::1'),
(408, 5, 'Andrei', 'Successful Login', '2025-12-16 05:03:17', '::1'),
(409, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:11:42', '::1'),
(410, 5, 'Andrei', 'Successful Login', '2025-12-16 05:12:12', '::1'),
(411, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:20:22', '::1'),
(412, 5, 'Andrei', 'Successful Login', '2025-12-16 05:22:29', '::1'),
(413, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:24:14', '::1'),
(414, NULL, 'Ishimae', 'Failed Login Attempt', '2025-12-16 05:29:19', '::1'),
(415, NULL, 'Irishmae', 'Failed Login Attempt', '2025-12-16 05:29:25', '::1'),
(416, 42, 'etan01', 'Successful Login', '2025-12-16 05:29:29', '::1'),
(417, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:33:37', '::1'),
(418, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 05:38:58', '::1'),
(419, 42, 'etan01', 'Successful Login', '2025-12-16 05:39:22', '::1'),
(420, 42, 'etan01', 'Successful Login', '2025-12-16 05:39:43', '::1'),
(421, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:47:12', '::1'),
(422, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:50:40', '::1'),
(423, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:50:44', '::1'),
(424, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:51:16', '::1'),
(425, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:51:23', '::1'),
(426, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 05:52:21', '::1'),
(427, 5, 'Andrei', 'Successful Login', '2025-12-16 06:18:32', '::1'),
(428, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 06:20:11', '::1'),
(429, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 06:21:15', '::1'),
(430, 5, 'Andrei', 'Successful Login', '2025-12-16 06:26:32', '::1'),
(431, 5, 'Andrei', 'Successful Login', '2025-12-16 06:26:47', '::1'),
(432, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 06:27:11', '::1'),
(433, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 06:47:13', '::1'),
(434, 42, 'etan01', 'Successful Login', '2025-12-16 06:47:47', '::1'),
(435, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 06:48:03', '::1'),
(436, 42, 'etan01', 'Successful Login', '2025-12-16 06:50:48', '::1'),
(437, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 06:50:54', '::1'),
(438, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 06:52:13', '::1'),
(439, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 06:54:07', '::1'),
(440, 42, 'etan01', 'Successful Login', '2025-12-16 07:07:40', '::1'),
(441, 3, 'Trishia Mayi', 'Successful Login', '2025-12-16 07:08:04', '::1'),
(442, 5, 'Andrei', 'Successful Login', '2025-12-16 07:08:22', '::1'),
(443, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 07:08:42', '::1'),
(444, 10, 'Admin Mayi', 'Successful Login', '2025-12-16 08:28:06', '::1');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_appointments_user` (`user_id`),
  ADD KEY `fk_appointments_service` (`service_id`),
  ADD KEY `fk_appointments_patient_id` (`patient_id`);

--
-- Indexes for table `dental_records`
--
ALTER TABLE `dental_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `dental_supplies`
--
ALTER TABLE `dental_supplies`
  ADD PRIMARY KEY (`supply_id`),
  ADD UNIQUE KEY `unique_supply_batch` (`name`,`expiration_date`);

--
-- Indexes for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_doctor_date` (`doctor_id`,`available_date`);

--
-- Indexes for table `patient`
--
ALTER TABLE `patient`
  ADD PRIMARY KEY (`patient_id`),
  ADD KEY `fk_patient_created_by` (`user_id`);

--
-- Indexes for table `patient_files`
--
ALTER TABLE `patient_files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `patient_recommendations`
--
ALTER TABLE `patient_recommendations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_payments_user_id` (`user_id`),
  ADD KEY `fk_payments_appt_final` (`appointment_id`),
  ADD KEY `fk_payments_patient_final` (`patient_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `supply_usage`
--
ALTER TABLE `supply_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supply_id` (`supply_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_supply_usage_patient` (`patient_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT for table `dental_records`
--
ALTER TABLE `dental_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dental_supplies`
--
ALTER TABLE `dental_supplies`
  MODIFY `supply_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `patient`
--
ALTER TABLE `patient`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `patient_files`
--
ALTER TABLE `patient_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `patient_recommendations`
--
ALTER TABLE `patient_recommendations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=184;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `supply_usage`
--
ALTER TABLE `supply_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=445;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_patient_id` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`patient_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `dental_records`
--
ALTER TABLE `dental_records`
  ADD CONSTRAINT `dental_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`patient_id`);

--
-- Constraints for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  ADD CONSTRAINT `doctor_availability_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `patient`
--
ALTER TABLE `patient`
  ADD CONSTRAINT `fk_patient_created_by` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `patient_files`
--
ALTER TABLE `patient_files`
  ADD CONSTRAINT `patient_files_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_appt_final` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_patient_final` FOREIGN KEY (`patient_id`) REFERENCES `patient` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
