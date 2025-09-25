-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 25, 2025 at 04:35 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `isiqueue`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(500) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `dob` date DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `password` varchar(100) NOT NULL DEFAULT 'BspMadang@123',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fcm_token` varchar(255) DEFAULT NULL,
  `fcm_device` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `full_name`, `first_name`, `last_name`, `dob`, `email`, `address`, `password`, `must_change_password`, `created_at`, `updated_at`, `fcm_token`, `fcm_device`) VALUES
(1, NULL, 'Ethan', 'Wakon', '2025-08-07', 'ewakon@gmail.com', 'Nabasa', 'MyNewPass123', 0, '2025-08-18 01:08:03', '2025-09-19 08:31:05', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(2, NULL, 'Gedix', 'Atage', '2025-08-01', 'gatage@gmail.com', 'Wali', 'Gatage', 0, '2025-08-19 13:08:55', '2025-09-18 02:45:51', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(3, NULL, 'John', 'Peter', '2013-03-19', 'jpeter@gmail.com', 'Kalibobo', 'Jpeter', 0, '2025-08-19 16:14:16', '2025-09-19 08:31:58', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(4, NULL, 'Tonny', 'Daniel', '2025-08-16', 'td@gmail.com', 'Newtown', 'Tdaniel', 0, '2025-08-19 16:16:14', '2025-09-19 08:32:45', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(5, NULL, 'Bob', 'Bob', '2025-08-14', 'bobb@gmail.com', 'Panu', 'Bobobob', 0, '2025-08-19 16:23:13', '2025-09-24 21:23:05', NULL, NULL),
(6, NULL, 'Ben', 'Ten', '2025-08-03', 'bten@gmail.com', 'Jais Aben', 'Bten123', 0, '2025-08-19 16:33:40', '2025-09-19 08:34:05', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(7, NULL, 'Donald', 'Trump', '1991-03-17', 'dtrump@gmail.com', 'Karkar', 'Dtrump', 0, '2025-08-20 00:21:45', '2025-09-18 04:30:57', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(8, NULL, 'Arthur', 'King', '2025-08-15', 'ak@gmail.com', 'Machine Gun', 'Aking123', 0, '2025-08-20 03:46:35', '2025-09-19 08:35:20', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(9, NULL, 'Joanne', 'Kila', '2003-02-09', 'jkila@gmail.com', '3 Line', 'BspMadang@123', 1, '2025-08-20 12:22:11', '2025-08-20 12:22:11', NULL, NULL),
(10, NULL, 'Jonathan', 'Bai', '2025-08-18', 'jbai@gmail.com', 'Newtown', 'Jbai123', 0, '2025-08-20 12:39:31', '2025-09-22 13:38:32', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(11, NULL, 'Jay', 'Puka', '2025-08-16', 'jpuks@gmail.com', 'Kalibobo', 'Jpuks123', 0, '2025-08-20 12:40:50', '2025-08-20 17:28:35', NULL, NULL),
(12, NULL, 'Quinten', 'Murunata', '2025-08-30', 'quitenm@gmail.com', 'Kusbau', 'murunataA04', 0, '2025-08-20 13:46:06', '2025-08-20 13:55:46', NULL, NULL),
(13, NULL, 'Noah', 'Ipang', '2025-08-08', 'nipang@gamil.com', 'Kusbau', 'Nipang', 0, '2025-08-20 16:25:41', '2025-08-20 16:27:08', NULL, NULL),
(14, NULL, 'Bilel', 'Funumari', '1972-08-28', 'dfunumari@gmail.com', 'Kusbau', '375837', 0, '2025-08-20 17:52:13', '2025-08-20 17:55:27', NULL, NULL),
(15, NULL, 'Betty', 'Toea', '2025-08-02', 'btoea@gmail.com', 'Sisiak', 'Btoea123', 0, '2025-08-20 22:43:00', '2025-09-19 08:22:45', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(16, NULL, 'Makis', 'Miur', '2025-08-01', 'makis@gmail.com', 'Kina Beach', 'Makis123', 0, '2025-08-21 12:39:39', '2025-08-25 15:24:53', NULL, NULL),
(17, NULL, 'Edward', 'Kuka', '2025-08-14', 'ekuka@gmail.com', 'Wagol', 'BspMadang@123', 1, '2025-08-22 10:24:37', '2025-08-22 10:24:37', NULL, NULL),
(18, NULL, 'Peter', 'Kila', '2025-08-09', 'pk@gmail.com', 'Panu', 'PKila123', 0, '2025-08-24 22:11:13', '2025-09-22 13:38:00', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(19, NULL, 'Gray', 'Gray', '2025-08-17', 'gray@gmail.com', 'Kalibobo', 'Gray123', 0, '2025-08-24 23:19:31', '2025-09-22 13:39:02', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(20, NULL, 'Smith', 'Mapoia', '2025-08-10', 'sm@gmail.com', 'Nabasa', 'Smith123', 0, '2025-08-25 12:43:44', '2025-08-25 12:45:02', NULL, NULL),
(21, NULL, 'Jojo', 'Jojo', '2025-08-22', 'jo@gmail.com', 'Kalibobo', 'BspMadang@123', 1, '2025-08-27 12:35:25', '2025-08-27 12:35:25', NULL, NULL),
(22, NULL, 'Bruce', 'Bill', '2016-07-13', 'bb@gmail.com', 'Kalibobo', 'BspMadang@123', 1, '2025-08-27 16:27:31', '2025-08-27 16:27:31', NULL, NULL),
(23, NULL, 'Jackie', 'Chan', '2025-08-02', 'jc@gmail.com', 'Wali', 'BspMadang@123', 1, '2025-08-27 16:43:33', '2025-08-27 16:43:33', NULL, NULL),
(24, NULL, 'Arnold', 'John', '2025-08-14', 'aj@gmail.com', 'Kusbau', 'BspMadang@123', 1, '2025-08-27 16:50:59', '2025-08-27 16:50:59', NULL, NULL),
(25, NULL, 'Grace', 'Pawih', '2009-12-26', 'gpawih@gmail.com', 'Newtown', 'gpawih', 0, '2025-08-27 21:05:27', '2025-08-27 21:07:09', NULL, NULL),
(26, NULL, 'Gray', 'Boko', '2025-09-28', 'grayb@gmail.com', 'Wali', 'Grayboko', 0, '2025-09-12 11:33:49', '2025-09-12 11:34:46', NULL, NULL),
(27, NULL, 'Nigel', 'Welly', '2025-09-18', 'nwelly@gmail.com', 'Wali', 'Nwelly123', 0, '2025-09-14 13:06:59', '2025-09-14 13:13:18', NULL, NULL),
(28, NULL, 'Happy', 'Independence', '2025-09-14', 'hi@gmail.com', 'Town', 'HappyInd', 0, '2025-09-18 01:30:13', '2025-09-18 01:31:06', NULL, NULL),
(29, NULL, 'Ronald', 'Pidian', '2025-09-06', 'rpidian@gmail.com', 'Kinabeach', 'Rpidian', 0, '2025-09-22 13:44:26', '2025-09-22 13:45:43', 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android'),
(30, NULL, 'Bruno', 'Mars', '2025-09-06', 'bmars@gmail.com', 'BrunoM', 'BrunoM', 0, '2025-09-23 09:19:12', '2025-09-23 09:20:54', NULL, NULL),
(31, NULL, 'Akon', 'Boko', '2025-09-25', 'aboko@gmail.com', 'Kalibobo', 'Akon123', 0, '2025-09-23 19:25:51', '2025-09-23 19:26:28', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_tokens`
--

CREATE TABLE `customer_tokens` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `fcm_token` varchar(255) NOT NULL,
  `device_id` varchar(191) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_tokens`
--

INSERT INTO `customer_tokens` (`id`, `customer_id`, `fcm_token`, `device_id`, `last_seen_at`) VALUES
(9, 202, 'ABC123TOKEN', 'android', '2025-09-22 14:34:41'),
(11, 7, 'eg2MOe3qRGyjSMsnZpH87D:APA91bGo_2Ybq3HQJV9BxTWwlTsLsJeUD7Ze8zowxfrsxfmmym0vZk7bmhSrkPEjiq_wRZg_23tpjwpxh1_1kUrcwkVdf_369HegrxJ_3ENpTSL8NeWh8_w', 'android', '2025-09-25 04:27:40'),
(28, 7, 'c7CECn61S1SlR2GNSw9wHK:APA91bG_oCbqSafYkIQn2QWKBDWQ06MxIYYIswHX4SKasFx2aLWDA8oPEa4T-IDG_HiqMe8KwmoEXMk4SleYnsM_DI5St5q0Nwgpp5faKvRJG-dmTnCZpv0', 'android', '2025-09-23 01:21:06');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` char(1) NOT NULL,
  `service` varchar(100) NOT NULL,
  `sla_minutes` int(11) DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `code`, `service`, `sla_minutes`, `is_active`) VALUES
(1, 'W', 'Withdrawal', 0, 1),
(2, 'D', 'Deposit', 0, 1),
(3, 'C', 'Customer Service', 0, 1),
(4, 'E', 'Enquiry', 0, 1),
(5, 'L', 'Loan', 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `id` int(11) NOT NULL,
  `first_name` varchar(60) NOT NULL,
  `last_name` varchar(60) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`id`, `first_name`, `last_name`, `email`, `password`, `created_at`) VALUES
(1, 'Graham', 'Boko', 'gboko@gmail.com', 'Gboko123', '2025-09-12 02:04:19'),
(2, 'Lyall', 'Dale', 'ldale@gmail.com', 'Ldale123', '2025-09-12 02:04:19');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `ticket_no` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'waiting',
  `notified_pos5` tinyint(1) NOT NULL DEFAULT 0,
  `notified_pos3` tinyint(1) NOT NULL DEFAULT 0,
  `priority` tinyint(4) NOT NULL DEFAULT 0,
  `counter_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `called_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `hold_count` int(11) NOT NULL DEFAULT 0,
  `last_hold_at` datetime DEFAULT NULL,
  `last_recall_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `ticket_no`, `customer_id`, `service_id`, `status`, `notified_pos5`, `notified_pos3`, `priority`, `counter_id`, `created_at`, `called_at`, `completed_at`, `hold_count`, `last_hold_at`, `last_recall_at`, `cancelled_at`) VALUES
(1, 'W1', 7, 1, 'served', 0, 0, 0, NULL, '2025-09-24 18:27:44', '2025-09-24 18:28:00', '2025-09-24 18:28:14', 0, NULL, NULL, NULL),
(2, 'C1', 7, 3, 'served', 0, 0, 0, NULL, '2025-09-24 18:28:51', '2025-09-24 18:28:59', '2025-09-24 18:29:11', 0, NULL, NULL, NULL),
(3, 'W1', 7, 1, 'cancelled', 0, 0, 0, NULL, '2025-09-24 18:29:19', '2025-09-24 18:30:03', NULL, 3, NULL, '2025-09-25 04:30:03', '2025-09-25 04:30:30');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_sequence`
--

CREATE TABLE `ticket_sequence` (
  `seq_date` date NOT NULL,
  `last_seq` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_sequence`
--

INSERT INTO `ticket_sequence` (`seq_date`, `last_seq`) VALUES
('2025-08-19', 2),
('2025-08-20', 20),
('2025-08-24', 49),
('2025-08-25', 22),
('2025-08-27', 18);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customers_email` (`email`),
  ADD KEY `idx_customers_last_first` (`last_name`,`first_name`),
  ADD KEY `idx_customers_created_at` (`created_at`);

--
-- Indexes for table `customer_tokens`
--
ALTER TABLE `customer_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_token` (`fcm_token`),
  ADD KEY `idx_customer` (`customer_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `uniq_code` (`code`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_no` (`ticket_no`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_service` (`service_id`),
  ADD KEY `idx_tickets_status_hold` (`status`,`last_hold_at`);

--
-- Indexes for table `ticket_sequence`
--
ALTER TABLE `ticket_sequence`
  ADD PRIMARY KEY (`seq_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `customer_tokens`
--
ALTER TABLE `customer_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
