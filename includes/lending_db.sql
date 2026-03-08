-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 08, 2026 at 02:15 PM
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
-- Database: `lending_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_accounts`
--

CREATE TABLE `tbl_accounts` (
  `fullName` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(16) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_accounts`
--

INSERT INTO `tbl_accounts` (`fullName`, `email`, `password`) VALUES
(NULL, 'main@admin.edu', 'admin123');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_lending`
--

CREATE TABLE `tbl_lending` (
  `id` int(11) NOT NULL,
  `equipmentName` varchar(255) NOT NULL,
  `displayName` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `imageURL` varchar(500) DEFAULT NULL,
  `available` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE tbl_requests ( 
  request_id INT AUTO_INCREMENT PRIMARY KEY, 
  student_id VARCHAR(50) NOT NULL, 
  student_name VARCHAR(255) NOT NULL, 
  equipment_name VARCHAR(255) NOT NULL, 
  status VARCHAR(20) NOT NULL DEFAULT 'Waiting', 
  request_date DATETIME DEFAULT CURRENT_TIMESTAMP 
  );
-- Column "reason" added
ALTER TABLE tbl_requests
ADD COLUMN reason VARCHAR(255) NULL;



CREATE TABLE tbl_inventory (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    image_path VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Column "is_archived" added
-- 0 → Active (Inventory)
-- 1 → Archived
ALTER TABLE tbl_inventory
ADD COLUMN is_archived TINYINT(1) DEFAULT 0;

CREATE TABLE tbl_users (
    fullname VARCHAR(255) NOT NULL,
    student_id VARCHAR(255) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL
);

INSERT INTO tbl_requests (student_id,student_name,equipment_name,status)
VALUES
("2023-001","Juan Dela Cruz","Canon DSLR","Waiting"),
("2023-002","Maria Santos","Projector","Waiting"),
("2023-003","John Doe","Volleyball","Waiting");
--
-- Dumping data for table `tbl_lending`
--

INSERT INTO `tbl_lending` (`id`, `equipmentName`, `displayName`, `description`, `imageURL`, `available`) VALUES
(1, 'dwaddad', 'dadw', '', 'dadad', 1),
(2, 'Fan', 'Fred', 'hadjh', 'https://laptop.image', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_accounts`
--
ALTER TABLE `tbl_accounts`
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `tbl_lending`
--
ALTER TABLE `tbl_lending`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_lending`
--
ALTER TABLE `tbl_lending`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
