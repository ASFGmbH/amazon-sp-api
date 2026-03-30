-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: dedi2432.your-server.de
-- Erstellungszeit: 30. Mrz 2026 um 16:27
-- Server-Version: 10.11.14-MariaDB-0+deb12u2
-- PHP-Version: 8.4.19

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `asfmtm_db0`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `amazon_listing_inventory`
--

CREATE TABLE `amazon_listing_inventory` (
  `id` int(10) UNSIGNED NOT NULL,
  `model` varchar(50) NOT NULL,
  `parent_sku` varchar(80) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `damen_size` varchar(10) NOT NULL,
  `herren_size` varchar(10) NOT NULL,
  `asin` varchar(20) DEFAULT NULL,
  `current_stock_quantity` int(11) NOT NULL DEFAULT 0,
  `last_pushed_quantity` int(11) NOT NULL DEFAULT 0,
  `last_feed_id` varchar(40) DEFAULT NULL,
  `last_sync_status` enum('seeded','submitted','error') NOT NULL DEFAULT 'seeded',
  `last_sync_message` text DEFAULT NULL,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `price_markup`
--

CREATE TABLE `price_markup` (
  `id` int(10) UNSIGNED NOT NULL,
  `price_group` varchar(50) NOT NULL,
  `markup_type` enum('absolute','percent') NOT NULL DEFAULT 'absolute',
  `markup_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ring_color`
--

CREATE TABLE `ring_color` (
  `id` int(10) UNSIGNED NOT NULL,
  `model` varchar(50) NOT NULL,
  `amazon_color` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `amazon_listing_inventory`
--
ALTER TABLE `amazon_listing_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_sku` (`sku`),
  ADD UNIQUE KEY `uniq_model_pair` (`model`,`damen_size`,`herren_size`),
  ADD KEY `idx_model` (`model`),
  ADD KEY `idx_last_sync_status` (`last_sync_status`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indizes für die Tabelle `price_markup`
--
ALTER TABLE `price_markup`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_price_group` (`price_group`);

--
-- Indizes für die Tabelle `ring_color`
--
ALTER TABLE `ring_color`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_model` (`model`);

--
-- Indizes für die Tabelle `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_setting_key` (`setting_key`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `amazon_listing_inventory`
--
ALTER TABLE `amazon_listing_inventory`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `price_markup`
--
ALTER TABLE `price_markup`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `ring_color`
--
ALTER TABLE `ring_color`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
