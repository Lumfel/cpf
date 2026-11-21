-- =============================================================================
-- CIRCLE OF PEER FACILITATORS (CPF) — UPGRADED LARAGON MYSQL / MARIADB SCHEMA v3
-- Includes:
--   1. Atomic Name Fields (first_name, middle_name, last_name) on Memberships
--   2. Role-Based Governance (Adviser in Charge, President, Vice President,
--      Secretary, Treasurer, Member — NO Admin role)
--   3. Passkey Issuance Audit & 2FA / 60-Day Remember Cookie Auth Support
--   4. Single-Room Utilization (Strict 1-Room Rule: Testing Room Only)
--   5. Confidential Guidance Referrals with Clinical Triage (UK MHTS / NHS)
--   6. Private Officers & Adviser WebSocket GC + End-of-School-Year ZIP Archive
-- Target DB: MySQL 8.0+ (Laragon Windows 11) & MariaDB 10.6+ (Debian 4GB Server)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `cpf`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `cpf`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `officer_gc_archives`;
DROP TABLE IF EXISTS `officer_gc_messages`;
DROP TABLE IF EXISTS `officer_gc_channels`;
DROP TABLE IF EXISTS `referral_audit_logs`;
DROP TABLE IF EXISTS `guidance_referrals`;
DROP TABLE IF EXISTS `room_requests`;
DROP TABLE IF EXISTS `campus_rooms`;
DROP TABLE IF EXISTS `passkey_issuances`;
DROP TABLE IF EXISTS `campus_memberships`;
DROP TABLE IF EXISTS `campuses`;
DROP TABLE IF EXISTS `passkeys`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `users`;

-- -----------------------------------------------------------------------------
-- 1. CORE LARAVEL 13 + FORTIFY AUTH, 2FA & PASSKEY TABLES
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `two_factor_secret` TEXT NULL,
    `two_factor_recovery_codes` TEXT NULL,
    `two_factor_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `remember_token` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Hashed token for 2-month (60-day) persistent login cookie',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
    `id` VARCHAR(255) NOT NULL,
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` TEXT NULL,
    `payload` LONGTEXT NOT NULL,
    `last_activity` INT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `sessions_user_id_index` (`user_id`),
    KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
    `key` VARCHAR(255) NOT NULL,
    `value` MEDIUMTEXT NOT NULL,
    `expiration` INT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
    `key` VARCHAR(255) NOT NULL,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` INT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL DEFAULT NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
    `id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL,
    `failed_job_ids` LONGTEXT NOT NULL,
    `options` MEDIUMTEXT NULL,
    `cancelled_at` INT NULL DEFAULT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(255) NOT NULL,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passkeys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `credential_id` VARCHAR(255) NOT NULL,
    `credential` TEXT NOT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
    KEY `passkeys_user_id_index` (`user_id`),
    CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. CAMPUSES & MEMBERSHIPS (Atomic First, Middle, Last Name Columns)
-- -----------------------------------------------------------------------------
CREATE TABLE `campuses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(32) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `current_academic_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    `adviser_name` VARCHAR(150) NULL DEFAULT NULL,
    `gc_group_name` VARCHAR(150) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campuses_code_unique` (`code`),
    UNIQUE KEY `campuses_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campus_memberships` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('adviser', 'president', 'vice_president', 'secretary', 'treasurer', 'member') NOT NULL DEFAULT 'member',
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `enrollment_source` ENUM('online_form', 'officer_added') NOT NULL DEFAULT 'online_form',
    `recorded_email` VARCHAR(255) NOT NULL,

    -- Atomic Name Fields (Separate First, Middle, and Last Name)
    `first_name` VARCHAR(80) NOT NULL,
    `middle_name` VARCHAR(80) NULL DEFAULT NULL,
    `last_name` VARCHAR(80) NOT NULL,
    `full_name_last_first_mi` VARCHAR(180) NOT NULL COMMENT 'Formatted "Last, First M." for strict A-Z index sorting',

    `course_year_section` VARCHAR(80) NOT NULL,
    `sex` ENUM('Male', 'Female') NOT NULL,
    `gender_identity` VARCHAR(40) NULL DEFAULT NULL,
    `gender_identity_other` VARCHAR(100) NULL DEFAULT NULL,
    `birthdate` DATE NOT NULL,
    `active_email` VARCHAR(255) NOT NULL,
    `active_fb_account` VARCHAR(255) NOT NULL,
    `cellphone_number` VARCHAR(40) NOT NULL,
    `added_or_approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `is_in_messenger_gc` TINYINT(1) NOT NULL DEFAULT 0,
    `gc_confirmed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `gc_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `remarks` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campus_memberships_user_campus_unique` (`user_id`, `campus_id`),
    KEY `campus_memberships_campus_status_az_idx` (`campus_id`, `status`, `last_name`, `first_name`),
    KEY `campus_memberships_campus_role_idx` (`campus_id`, `role`),
    CONSTRAINT `campus_memberships_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_memberships_campus_id_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_memberships_approved_by_fk` FOREIGN KEY (`added_or_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `campus_memberships_gc_confirmed_by_fk` FOREIGN KEY (`gc_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. PASSKEY ISSUANCES (Adviser, President, Vice President, Secretary)
-- -----------------------------------------------------------------------------
CREATE TABLE `passkey_issuances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `target_user_id` BIGINT UNSIGNED NOT NULL,
    `issued_by_user_id` BIGINT UNSIGNED NOT NULL,
    `enrollment_code_hash` VARCHAR(128) NOT NULL,
    `require_2fa` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('issued', 'claimed', 'revoked', 'expired') NOT NULL DEFAULT 'issued',
    `expires_at` TIMESTAMP NOT NULL,
    `claimed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `passkey_issuances_campus_target_idx` (`campus_id`, `target_user_id`, `status`),
    CONSTRAINT `passkey_issuances_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `passkey_issuances_target_fk` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `passkey_issuances_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. SINGLE-ROOM UTILIZATION (STRICT 1-ROOM RULE: TESTING ROOM ONLY)
-- -----------------------------------------------------------------------------
CREATE TABLE `campus_rooms` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `room_code` VARCHAR(32) NOT NULL DEFAULT 'TESTING_ROOM',
    `room_name` VARCHAR(100) NOT NULL DEFAULT 'Testing Room',
    `capacity` TINYINT UNSIGNED NOT NULL DEFAULT 6,
    `is_single_allowed_room` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campus_rooms_one_room_per_campus` (`campus_id`, `is_single_allowed_room`),
    CONSTRAINT `campus_rooms_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `room_id` BIGINT UNSIGNED NOT NULL,
    `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
    `purpose` VARCHAR(200) NOT NULL,
    `request_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `status` ENUM('pending', 'approved', 'declined', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `reviewed_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `review_notes` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `room_requests_slot_lookup_idx` (`room_id`, `request_date`, `start_time`, `end_time`, `status`),
    CONSTRAINT `room_requests_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_room_fk` FOREIGN KEY (`room_id`) REFERENCES `campus_rooms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_user_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. CONFIDENTIAL GUIDANCE REFERRALS & AUDIT LOGS (UK MHTS / NHS TRIAGE)
-- -----------------------------------------------------------------------------
CREATE TABLE `guidance_referrals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `submitted_by_user_id` BIGINT UNSIGNED NOT NULL,
    `referral_type` ENUM('self_counseling', 'peer_referral') NOT NULL DEFAULT 'peer_referral',
    `student_identifier` VARCHAR(180) NOT NULL,
    `student_course_section` VARCHAR(80) NULL DEFAULT NULL,
    `suggested_priority` ENUM('emergency', 'very_urgent', 'urgent', 'semi_urgent', 'routine') NOT NULL DEFAULT 'routine',
    `counselor_confirmed_priority` ENUM('emergency', 'very_urgent', 'urgent', 'semi_urgent', 'routine') NULL DEFAULT NULL,
    `triage_response_target` VARCHAR(60) NOT NULL,
    `encrypted_concern_summary` TEXT NOT NULL,
    `status` ENUM('submitted', 'triaged', 'in_session', 'referred_external', 'closed') NOT NULL DEFAULT 'submitted',
    `assigned_counselor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `guidance_referrals_campus_priority_idx` (`campus_id`, `suggested_priority`, `status`),
    CONSTRAINT `guidance_referrals_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `guidance_referrals_submitter_fk` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `guidance_referrals_counselor_fk` FOREIGN KEY (`assigned_counselor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referral_audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `referral_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(60) NOT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `referral_audit_logs_ref_idx` (`referral_id`, `created_at`),
    CONSTRAINT `referral_audit_logs_ref_fk` FOREIGN KEY (`referral_id`) REFERENCES `guidance_referrals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `referral_audit_logs_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. OFFICERS & ADVISER PRIVATE WEBSOCKET GC + END-OF-SCHOOL-YEAR ZIP ARCHIVE
--    * Accessible strictly to Adviser, President, Vice President, Secretary, Treasurer.
--    * WebSocket private channel: `private-campus.{campus_id}.officers-gc.{academic_year}`
--    * At End of School Year: Laravel exports messages + media to a `.zip` on the
--      512 GB HDD (`/mnt/hdd/cpf_storage/archives/...`), records SHA-256 checksum
--      in `officer_gc_archives`, and resets `officer_gc_messages` for the new AY.
-- -----------------------------------------------------------------------------
CREATE TABLE `officer_gc_channels` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-2027',
    `channel_key` VARCHAR(120) NOT NULL COMMENT 'WebSocket private channel identifier',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `reset_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `officer_gc_channels_unique` (`campus_id`, `academic_year`),
    CONSTRAINT `officer_gc_channels_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `officer_gc_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel_id` BIGINT UNSIGNED NOT NULL,
    `sender_user_id` BIGINT UNSIGNED NOT NULL,
    `sender_role` ENUM('adviser', 'president', 'vice_president', 'secretary', 'treasurer') NOT NULL,
    `message_body` TEXT NOT NULL,
    `attachment_hdd_path` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional file stored on 512 GB HDD',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `officer_gc_messages_channel_time_idx` (`channel_id`, `created_at`),
    CONSTRAINT `officer_gc_messages_channel_fk` FOREIGN KEY (`channel_id`) REFERENCES `officer_gc_channels` (`id`) ON DELETE CASCADE,
    CONSTRAINT `officer_gc_messages_sender_fk` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `officer_gc_archives` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `zip_file_name` VARCHAR(160) NOT NULL COMMENT 'e.g. cpf_talisay_officers_gc_2026-2027.zip',
    `hdd_storage_path` VARCHAR(255) NOT NULL COMMENT 'Stored on 512 GB HDD: /mnt/hdd/cpf_storage/archives/...',
    `sha256_checksum` CHAR(64) NOT NULL COMMENT 'Integrity hash of archived ZIP bundle',
    `total_messages` INT UNSIGNED NOT NULL DEFAULT 0,
    `archived_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `officer_gc_archives_campus_ay_idx` (`campus_id`, `academic_year`),
    CONSTRAINT `officer_gc_archives_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `officer_gc_archives_actor_fk` FOREIGN KEY (`archived_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. SEED DATA (Campuses, 1 Testing Room, Officers GC Channel, 7 Sample Users)
-- -----------------------------------------------------------------------------
INSERT INTO `campuses` (`id`, `code`, `name`, `slug`, `current_academic_year`, `adviser_name`, `gc_group_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'TALISAY', 'CHMSU Talisay (Main Campus)', 'talisay', '2026-2027', 'Dr. Elena V. Montemayor, RGC', 'CPF Talisay Official Peer GC (AY 2026–2027)', 1, NOW(), NOW()),
(2, 'FORTUNE_TOWNE', 'CHMSU Fortune Towne Campus', 'fortune-towne', '2026-2027', 'Prof. Marco L. Villanueva, RGC', 'CPF Fortune Towne Official Peer GC', 1, NOW(), NOW()),
(3, 'ALIJIS', 'CHMSU Alijis Campus', 'alijis', '2026-2027', 'Prof. Clarissa D. Soriano, RGC', 'CPF Alijis Official Peer GC', 1, NOW(), NOW()),
(4, 'BINALBAGAN', 'CHMSU Binalbagan Campus', 'binalbagan', '2026-2027', 'Prof. Nathaniel P. Mendoza, RGC', 'CPF Binalbagan Official Peer GC', 1, NOW(), NOW());

INSERT INTO `campus_rooms` (`id`, `campus_id`, `room_code`, `room_name`, `capacity`, `is_single_allowed_room`, `created_at`, `updated_at`) VALUES
(1, 1, 'TESTING_ROOM', 'Testing Room (Guidance Wing)', 6, 1, NOW(), NOW());

INSERT INTO `officer_gc_channels` (`id`, `campus_id`, `academic_year`, `channel_key`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '2026-2027', 'private-campus.1.officers-gc.2026-2027', 1, NOW(), NOW());

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `created_at`, `updated_at`) VALUES
(1, 'Montemayor, Elena V.', 'adviser@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(2, 'Mercado, Sofia R.', 'president@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(3, 'Bautista, Miguel A.', 'vp@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(4, 'Alvarez, Hannah G.', 'secretary@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(5, 'Delgado, Liam C.', 'treasurer@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(6, 'Reyes, Katrina M.', 'katrina@cpf.chmsu.edu.ph', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW()),
(7, 'Tibo-oc, Paul Felippe G.', 'paulfelippegtibooc@gmail.com', NOW(), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW(), NOW());

INSERT INTO `campus_memberships` (
    `id`, `user_id`, `campus_id`, `role`, `status`, `enrollment_source`, `recorded_email`,
    `first_name`, `middle_name`, `last_name`, `full_name_last_first_mi`,
    `course_year_section`, `sex`, `gender_identity`, `gender_identity_other`,
    `birthdate`, `active_email`, `active_fb_account`, `cellphone_number`,
    `added_or_approved_by`, `approved_at`, `is_in_messenger_gc`, `gc_confirmed_by`, `gc_confirmed_at`,
    `remarks`, `created_at`, `updated_at`
) VALUES
(1, 1, 1, 'adviser', 'approved', 'officer_added', 'adviser@cpf.chmsu.edu.ph', 'Elena', 'Villanueva', 'Montemayor', 'Montemayor, Elena V.', 'Guidance Services / Adviser', 'Female', 'Woman', NULL, '1984-05-14', 'adviser@cpf.chmsu.edu.ph', 'Elena Montemayor RGC (Blue blazer at Guidance Office)', '0917-555-0101', 1, NOW(), 1, 1, NOW(), NULL, NOW(), NOW()),
(2, 2, 1, 'president', 'approved', 'officer_added', 'president@cpf.chmsu.edu.ph', 'Sofia', 'Reyes', 'Mercado', 'Mercado, Sofia R.', 'BS Psychology 4A', 'Female', 'Woman', NULL, '2004-09-21', 'president@cpf.chmsu.edu.ph', 'Sofia Mercado (Wearing CPF cream lanyard)', '0917-555-0102', 1, NOW(), 1, 1, NOW(), NULL, NOW(), NOW()),
(3, 3, 1, 'vice_president', 'approved', 'officer_added', 'vp@cpf.chmsu.edu.ph', 'Miguel', 'Alvarez', 'Bautista', 'Bautista, Miguel A.', 'BSIT 4B', 'Male', 'Man', NULL, '2004-08-19', 'vp@cpf.chmsu.edu.ph', 'Miguel Bautista (Navy polo at campus courtyard)', '0917-555-0106', 1, NOW(), 1, 2, NOW(), NULL, NOW(), NOW()),
(4, 4, 1, 'secretary', 'approved', 'officer_added', 'secretary@cpf.chmsu.edu.ph', 'Hannah', 'Gomez', 'Alvarez', 'Alvarez, Hannah G.', 'BSIS 3A', 'Female', 'Woman', NULL, '2005-02-11', 'secretary@cpf.chmsu.edu.ph', 'Hannah Alvarez (White Filipiniana portrait)', '0917-555-0103', 1, NOW(), 1, 2, NOW(), NULL, NOW(), NOW()),
(5, 5, 1, 'treasurer', 'approved', 'officer_added', 'treasurer@cpf.chmsu.edu.ph', 'Liam', 'Cruz', 'Delgado', 'Delgado, Liam C.', 'BSIT 3B', 'Male', 'Man', NULL, '2005-07-08', 'treasurer@cpf.chmsu.edu.ph', 'Liam Delgado (Holding acoustic guitar outdoors)', '0917-555-0104', 1, NOW(), 1, 2, NOW(), NULL, NOW(), NOW()),
(6, 6, 1, 'member', 'approved', 'online_form', 'katrina@cpf.chmsu.edu.ph', 'Katrina', 'Mendoza', 'Reyes', 'Reyes, Katrina M.', 'BSIT 4A', 'Female', 'Woman', NULL, '2004-11-03', 'katrina@cpf.chmsu.edu.ph', 'Katrina Reyes (Yellow cardigan in library)', '0917-555-0105', 2, NOW(), 0, NULL, NULL, NULL, NOW(), NOW()),
(7, 7, 1, 'member', 'pending', 'online_form', 'paulfelippegtibooc@gmail.com', 'Paul Felippe', 'G.', 'Tibo-oc', 'Tibo-oc, Paul Felippe G.', 'BSIT 4A', 'Male', 'Man', NULL, '2004-06-15', 'paulfelippegtibooc@gmail.com', 'Paul Felippe Tibo-oc (CHMSU BSIT uniform portrait)', '0917-555-0199', NULL, NULL, 0, NULL, NULL, NULL, NOW(), NOW());

INSERT INTO `officer_gc_messages` (`channel_id`, `sender_user_id`, `sender_role`, `message_body`, `created_at`) VALUES
(1, 1, 'adviser', 'Welcome Officers! Please verify the pending A–Z applicants and issue Passkeys once confirmed.', NOW()),
(1, 2, 'president', 'Noted Dr. Montemayor! Miguel (VP) and I are reviewing the new BSIT/BSIS forms now.', NOW());

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
-- v5 UPGRADE: BLOCKCHAIN-VERIFIED ACHIEVEMENTS, CERTIFICATES, AND AWARDS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `blockchain_credential_ledgers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `block_index` BIGINT UNSIGNED NOT NULL,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `recipient_user_id` BIGINT UNSIGNED NOT NULL,
    `issuer_user_id` BIGINT UNSIGNED NULL,
    `credential_type` VARCHAR(32) NOT NULL COMMENT 'achievement, certificate, award',
    `credential_id` BIGINT UNSIGNED NOT NULL,
    `credential_code` VARCHAR(64) NOT NULL,
    `canonical_payload` JSON NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `previous_block_hash` CHAR(64) NOT NULL,
    `block_hash` CHAR(64) NOT NULL,
    `driver` VARCHAR(32) NOT NULL DEFAULT 'local_ledger',
    `network` VARCHAR(64) NOT NULL DEFAULT 'cpf-local-chain',
    `transaction_hash` VARCHAR(128) NULL,
    `contract_address` VARCHAR(128) NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'anchored',
    `anchored_at` TIMESTAMP NOT NULL,
    `revoked_at` TIMESTAMP NULL,
    `revocation_reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `bcl_block_index_unique` (`block_index`),
    UNIQUE KEY `bcl_payload_hash_unique` (`payload_hash`),
    UNIQUE KEY `bcl_block_hash_unique` (`block_hash`),
    KEY `bcl_credential_code_idx` (`credential_code`),
    KEY `bcl_credential_lookup_idx` (`credential_type`, `credential_id`),
    KEY `bcl_campus_recipient_idx` (`campus_id`, `recipient_user_id`),
    KEY `bcl_status_network_idx` (`status`, `network`),
    CONSTRAINT `bcl_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bcl_recipient_fk` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bcl_issuer_fk` FOREIGN KEY (`issuer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_awards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `award_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `award_category` VARCHAR(80) NOT NULL DEFAULT 'Peer Service',
    `academic_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    `awarded_on` DATE NOT NULL,
    `citation` TEXT NULL,
    `issued_by_user_id` BIGINT UNSIGNED NULL,
    `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored',
    `credential_hash` CHAR(64) NULL,
    `blockchain_ledger_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `member_awards_code_unique` (`award_code`),
    KEY `member_awards_campus_user_idx` (`campus_id`, `user_id`),
    KEY `member_awards_hash_idx` (`credential_hash`),
    CONSTRAINT `member_awards_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_awards_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_awards_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `member_awards_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `member_achievements`
    ADD COLUMN `achievement_code` VARCHAR(64) NULL UNIQUE AFTER `user_id`,
    ADD COLUMN `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored' AFTER `verified_by_user_id`,
    ADD COLUMN `credential_hash` CHAR(64) NULL AFTER `blockchain_status`,
    ADD COLUMN `blockchain_ledger_id` BIGINT UNSIGNED NULL AFTER `credential_hash`,
    ADD KEY `member_achievements_hash_idx` (`credential_hash`),
    ADD CONSTRAINT `member_achievements_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL;

ALTER TABLE `digital_certificates`
    ADD COLUMN `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored' AFTER `issued_by_user_id`,
    ADD COLUMN `credential_hash` CHAR(64) NULL AFTER `blockchain_status`,
    ADD COLUMN `blockchain_ledger_id` BIGINT UNSIGNED NULL AFTER `credential_hash`,
    ADD KEY `digital_certificates_hash_idx` (`credential_hash`),
    ADD CONSTRAINT `digital_certificates_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `blockchain_verification_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ledger_id` BIGINT UNSIGNED NULL,
    `queried_identifier` VARCHAR(128) NOT NULL,
    `verified_by_user_id` BIGINT UNSIGNED NULL,
    `verifier_ip` VARCHAR(45) NULL,
    `verification_result` VARCHAR(24) NOT NULL COMMENT 'valid, tampered, revoked, not_found',
    `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `bvl_queried_identifier_idx` (`queried_identifier`),
    CONSTRAINT `bvl_ledger_fk` FOREIGN KEY (`ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bvl_verifier_fk` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
