-- =============================================================================
-- CIRCLE OF PEER FACILITATORS (CPF) — CONSOLIDATED MYSQL / MARIADB SCHEMA
-- Matches all 23 Laravel migrations (0001_01_01_000000 through 2026_11_22_000018)
-- Target DB: MySQL 8.0+ (Laragon Windows) & MariaDB 10.6+
-- Tables: 41 application, auth, governance, event, handover, and ledger tables
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `cpf`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `cpf`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Reverse dependency order cleanup
DROP TABLE IF EXISTS `adviser_handovers`;
DROP TABLE IF EXISTS `campus_event_notifications`;
DROP TABLE IF EXISTS `campus_event_reflections`;
DROP TABLE IF EXISTS `campus_event_attendances`;
DROP TABLE IF EXISTS `campus_events`;
DROP TABLE IF EXISTS `peer_quotes`;
DROP TABLE IF EXISTS `payment_submissions`;
DROP TABLE IF EXISTS `payment_destinations`;
DROP TABLE IF EXISTS `election_opt_outs`;
DROP TABLE IF EXISTS `election_votes`;
DROP TABLE IF EXISTS `election_candidates`;
DROP TABLE IF EXISTS `elections`;
DROP TABLE IF EXISTS `school_year_archives`;
DROP TABLE IF EXISTS `time_capsules`;
DROP TABLE IF EXISTS `blockchain_verification_logs`;
DROP TABLE IF EXISTS `member_awards`;
DROP TABLE IF EXISTS `digital_certificates`;
DROP TABLE IF EXISTS `member_achievements`;
DROP TABLE IF EXISTS `blockchain_credential_ledgers`;
DROP TABLE IF EXISTS `counselor_gc_messages`;
DROP TABLE IF EXISTS `officer_gc_archives`;
DROP TABLE IF EXISTS `officer_gc_messages`;
DROP TABLE IF EXISTS `officer_gc_channels`;
DROP TABLE IF EXISTS `referral_audit_logs`;
DROP TABLE IF EXISTS `guidance_referrals`;
DROP TABLE IF EXISTS `room_requests`;
DROP TABLE IF EXISTS `campus_counselors`;
DROP TABLE IF EXISTS `campus_rooms`;
DROP TABLE IF EXISTS `member_info_change_requests`;
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
-- 1. CORE LARAVEL AUTH, FORTIFY 2FA, PASSKEYS, SESSIONS, CACHE & QUEUES
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `profile_photo_path` VARCHAR(255) NULL DEFAULT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `temporary_password_issued_at` TIMESTAMP NULL DEFAULT NULL,
    `two_factor_secret` TEXT NULL,
    `two_factor_recovery_codes` TEXT NULL,
    `two_factor_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `remember_token` VARCHAR(100) NULL DEFAULT NULL,
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
    `expiration` BIGINT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
    `key` VARCHAR(255) NOT NULL,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` BIGINT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL,
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
    `connection` VARCHAR(255) NOT NULL,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
    KEY `failed_jobs_connection_queue_failed_at_index` (`connection`, `queue`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passkeys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `credential_id` VARCHAR(255) NOT NULL,
    `credential` JSON NOT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
    KEY `passkeys_user_id_index` (`user_id`),
    CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. CAMPUSES, MEMBERSHIPS, PASSKEY ISSUANCES & CHANGE REQUESTS
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
    `role` VARCHAR(32) NOT NULL DEFAULT 'member',
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
    `enrollment_source` VARCHAR(32) NOT NULL DEFAULT 'online_form',
    `recorded_email` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(80) NOT NULL,
    `middle_name` VARCHAR(80) NULL DEFAULT NULL,
    `last_name` VARCHAR(80) NOT NULL,
    `full_name_last_first_mi` VARCHAR(180) NOT NULL,
    `course` VARCHAR(60) NULL DEFAULT NULL,
    `year_level` VARCHAR(20) NULL DEFAULT NULL,
    `section` VARCHAR(20) NULL DEFAULT NULL,
    `course_year_section` VARCHAR(80) NOT NULL,
    `sex` VARCHAR(20) NOT NULL,
    `gender_identity` VARCHAR(40) NULL DEFAULT NULL,
    `gender_identity_other` VARCHAR(100) NULL DEFAULT NULL,
    `birthdate` DATE NOT NULL,
    `active_email` VARCHAR(255) NOT NULL,
    `active_fb_account` VARCHAR(255) NOT NULL,
    `facebook_profile_url` VARCHAR(500) NULL DEFAULT NULL,
    `cellphone_number` VARCHAR(40) NOT NULL,
    `added_or_approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `is_in_messenger_gc` TINYINT(1) NOT NULL DEFAULT 0,
    `gc_confirmed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `gc_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `has_paid_dues` TINYINT(1) NOT NULL DEFAULT 0,
    `dues_confirmed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `dues_confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campus_memberships_user_campus_unique` (`user_id`, `campus_id`),
    KEY `campus_memberships_campus_status_az_idx` (`campus_id`, `status`, `last_name`, `first_name`),
    KEY `campus_memberships_campus_role_idx` (`campus_id`, `role`),
    CONSTRAINT `campus_memberships_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_memberships_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_memberships_approved_by_fk` FOREIGN KEY (`added_or_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `campus_memberships_gc_confirmed_by_fk` FOREIGN KEY (`gc_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `campus_memberships_dues_confirmed_by_fk` FOREIGN KEY (`dues_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passkey_issuances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `target_user_id` BIGINT UNSIGNED NOT NULL,
    `issued_by_user_id` BIGINT UNSIGNED NOT NULL,
    `enrollment_code_hash` VARCHAR(128) NOT NULL,
    `encrypted_enrollment_code` TEXT NULL,
    `require_2fa` TINYINT(1) NOT NULL DEFAULT 1,
    `status` VARCHAR(24) NOT NULL DEFAULT 'issued',
    `is_locked` TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at` TIMESTAMP NOT NULL,
    `claimed_at` TIMESTAMP NULL DEFAULT NULL,
    `emailed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `passkey_issuances_campus_target_idx` (`campus_id`, `target_user_id`, `status`),
    CONSTRAINT `passkey_issuances_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `passkey_issuances_target_fk` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `passkey_issuances_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_info_change_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_membership_id` BIGINT UNSIGNED NOT NULL,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `requested_changes` JSON NOT NULL,
    `reason` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `adviser_notes` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `micr_campus_status_idx` (`campus_id`, `status`),
    KEY `micr_membership_status_idx` (`campus_membership_id`, `status`),
    CONSTRAINT `micr_membership_fk` FOREIGN KEY (`campus_membership_id`) REFERENCES `campus_memberships` (`id`) ON DELETE CASCADE,
    CONSTRAINT `micr_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `micr_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `micr_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. CAMPUS TESTING ROOM, COUNSELORS, REFERRALS & PRIVATE GROUP CHANNELS
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

CREATE TABLE `campus_counselors` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `specialty` VARCHAR(140) NULL DEFAULT NULL,
    `weekly_vacant_slots` JSON NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `campus_counselors_campus_active_idx` (`campus_id`, `is_active`),
    CONSTRAINT `campus_counselors_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `room_id` BIGINT UNSIGNED NOT NULL,
    `counselor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
    `purpose` VARCHAR(200) NOT NULL,
    `request_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `reviewed_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `review_notes` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `room_requests_slot_lookup_idx` (`room_id`, `request_date`, `start_time`, `end_time`, `status`),
    CONSTRAINT `room_requests_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_room_fk` FOREIGN KEY (`room_id`) REFERENCES `campus_rooms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_counselor_fk` FOREIGN KEY (`counselor_id`) REFERENCES `campus_counselors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `room_requests_requester_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `room_requests_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `guidance_referrals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `submitted_by_user_id` BIGINT UNSIGNED NOT NULL,
    `referral_type` VARCHAR(24) NOT NULL DEFAULT 'peer_referral',
    `student_identifier` VARCHAR(180) NOT NULL,
    `student_course_section` VARCHAR(80) NULL DEFAULT NULL,
    `suggested_priority` VARCHAR(24) NOT NULL DEFAULT 'routine',
    `counselor_confirmed_priority` VARCHAR(24) NULL DEFAULT NULL,
    `triage_response_target` VARCHAR(60) NOT NULL,
    `encrypted_concern_summary` TEXT NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'submitted',
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

CREATE TABLE `officer_gc_channels` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `channel_key` VARCHAR(120) NOT NULL,
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
    `sender_role` VARCHAR(32) NOT NULL,
    `message_body` TEXT NOT NULL,
    `attachment_hdd_path` VARCHAR(255) NULL DEFAULT NULL,
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
    `zip_file_name` VARCHAR(160) NOT NULL,
    `hdd_storage_path` VARCHAR(255) NOT NULL,
    `sha256_checksum` CHAR(64) NOT NULL,
    `total_messages` INT UNSIGNED NOT NULL DEFAULT 0,
    `archived_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `officer_gc_archives_campus_ay_idx` (`campus_id`, `academic_year`),
    CONSTRAINT `officer_gc_archives_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `officer_gc_archives_user_fk` FOREIGN KEY (`archived_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `counselor_gc_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `sender_user_id` BIGINT UNSIGNED NOT NULL,
    `sender_role` VARCHAR(40) NOT NULL,
    `message_body` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `counselor_gc_messages_campus_time_idx` (`campus_id`, `created_at`),
    CONSTRAINT `counselor_gc_messages_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `counselor_gc_messages_sender_fk` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. BLOCKCHAIN CREDENTIALS LEDGER, ACHIEVEMENTS, CERTIFICATES & AWARDS
-- -----------------------------------------------------------------------------
CREATE TABLE `blockchain_credential_ledgers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `block_index` BIGINT UNSIGNED NOT NULL,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `recipient_user_id` BIGINT UNSIGNED NOT NULL,
    `issuer_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `credential_type` VARCHAR(32) NOT NULL,
    `credential_id` BIGINT UNSIGNED NOT NULL,
    `credential_code` VARCHAR(64) NOT NULL,
    `canonical_payload` JSON NOT NULL,
    `payload_hash` CHAR(64) NOT NULL,
    `previous_block_hash` CHAR(64) NOT NULL,
    `block_hash` CHAR(64) NOT NULL,
    `driver` VARCHAR(32) NOT NULL DEFAULT 'local_ledger',
    `network` VARCHAR(64) NOT NULL DEFAULT 'cpf-local-chain',
    `transaction_hash` VARCHAR(128) NULL DEFAULT NULL,
    `contract_address` VARCHAR(128) NULL DEFAULT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'anchored',
    `anchored_at` TIMESTAMP NOT NULL,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `revocation_reason` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `bcl_block_index_unique` (`block_index`),
    UNIQUE KEY `bcl_payload_hash_unique` (`payload_hash`),
    UNIQUE KEY `bcl_block_hash_unique` (`block_hash`),
    KEY `bcl_credential_code_idx` (`credential_code`),
    KEY `bcl_transaction_hash_idx` (`transaction_hash`),
    KEY `bcl_credential_lookup_idx` (`credential_type`, `credential_id`),
    KEY `bcl_campus_recipient_idx` (`campus_id`, `recipient_user_id`),
    KEY `bcl_status_network_idx` (`status`, `network`),
    CONSTRAINT `bcl_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bcl_recipient_fk` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bcl_issuer_fk` FOREIGN KEY (`issuer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_achievements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `achievement_code` VARCHAR(64) NULL DEFAULT NULL,
    `title` VARCHAR(160) NOT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'Peer Facilitation',
    `achieved_on` DATE NOT NULL,
    `description` TEXT NULL,
    `verified_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored',
    `credential_hash` CHAR(64) NULL DEFAULT NULL,
    `blockchain_ledger_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `member_achievements_code_unique` (`achievement_code`),
    KEY `member_achievements_campus_user_idx` (`campus_id`, `user_id`),
    KEY `member_achievements_hash_idx` (`credential_hash`),
    CONSTRAINT `member_achievements_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_achievements_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_achievements_verifier_fk` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `member_achievements_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `digital_certificates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `certificate_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `event_name` VARCHAR(180) NOT NULL,
    `issued_on` DATE NOT NULL,
    `file_hdd_path` VARCHAR(255) NULL DEFAULT NULL,
    `issued_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored',
    `credential_hash` CHAR(64) NULL DEFAULT NULL,
    `blockchain_ledger_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `digital_certificates_code_unique` (`certificate_code`),
    KEY `digital_certificates_campus_user_idx` (`campus_id`, `user_id`),
    KEY `digital_certificates_hash_idx` (`credential_hash`),
    CONSTRAINT `digital_certificates_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `digital_certificates_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `digital_certificates_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `digital_certificates_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_awards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `award_code` VARCHAR(64) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `award_category` VARCHAR(80) NOT NULL DEFAULT 'Peer Service',
    `academic_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    `awarded_on` DATE NOT NULL,
    `citation` TEXT NULL,
    `issued_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `blockchain_status` VARCHAR(24) NOT NULL DEFAULT 'unanchored',
    `credential_hash` CHAR(64) NULL DEFAULT NULL,
    `blockchain_ledger_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `member_awards_code_unique` (`award_code`),
    KEY `member_awards_campus_user_idx` (`campus_id`, `user_id`),
    KEY `member_awards_hash_idx` (`credential_hash`),
    CONSTRAINT `member_awards_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_awards_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `member_awards_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `member_awards_ledger_fk` FOREIGN KEY (`blockchain_ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blockchain_verification_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ledger_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `queried_identifier` VARCHAR(128) NOT NULL,
    `verified_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `verifier_ip` VARCHAR(45) NULL DEFAULT NULL,
    `verification_result` VARCHAR(24) NOT NULL,
    `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `bvl_queried_identifier_idx` (`queried_identifier`),
    CONSTRAINT `bvl_ledger_fk` FOREIGN KEY (`ledger_id`) REFERENCES `blockchain_credential_ledgers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bvl_verifier_fk` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. COMMUNITY HUB, ELECTIONS, PAYMENTS, QUOTES, EVENTS & ADVISER HANDOVER
-- -----------------------------------------------------------------------------
CREATE TABLE `time_capsules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `letter_style` VARCHAR(40) NOT NULL DEFAULT 'classic',
    `encrypted_message` TEXT NOT NULL,
    `unlock_at` TIMESTAMP NOT NULL,
    `is_opened` TINYINT(1) NOT NULL DEFAULT 0,
    `opened_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `time_capsules_user_unlock_idx` (`user_id`, `unlock_at`),
    CONSTRAINT `time_capsules_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `time_capsules_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `school_year_archives` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `archive_type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `zip_file_name` VARCHAR(180) NOT NULL,
    `hdd_storage_path` VARCHAR(255) NOT NULL,
    `sha256_checksum` CHAR(64) NOT NULL,
    `archived_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `sy_archives_lookup_idx` (`campus_id`, `academic_year`, `archive_type`),
    CONSTRAINT `sy_archives_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `sy_archives_user_fk` FOREIGN KEY (`archived_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `elections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
    `current_position` VARCHAR(60) NULL DEFAULT 'president',
    `current_phase` VARCHAR(24) NOT NULL DEFAULT 'voting',
    `round_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `position_results` JSON NULL,
    `opens_at` TIMESTAMP NULL DEFAULT NULL,
    `closes_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by_user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `elections_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `elections_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `election_candidates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `election_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `position` VARCHAR(60) NOT NULL,
    `platform` TEXT NULL,
    `nominated_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `nomination_status` VARCHAR(20) NOT NULL DEFAULT 'accepted',
    `is_tie_contender` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `election_candidates_unique` (`election_id`, `user_id`, `position`),
    CONSTRAINT `election_candidates_election_fk` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `election_candidates_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `election_candidates_nominator_fk` FOREIGN KEY (`nominated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `election_votes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `election_id` BIGINT UNSIGNED NOT NULL,
    `candidate_id` BIGINT UNSIGNED NOT NULL,
    `voter_user_id` BIGINT UNSIGNED NOT NULL,
    `position` VARCHAR(60) NOT NULL,
    `round_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `cast_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `election_votes_one_per_position_round` (`election_id`, `voter_user_id`, `position`, `round_number`),
    KEY `election_votes_election_id_idx` (`election_id`),
    CONSTRAINT `election_votes_election_fk` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `election_votes_candidate_fk` FOREIGN KEY (`candidate_id`) REFERENCES `election_candidates` (`id`) ON DELETE CASCADE,
    CONSTRAINT `election_votes_voter_fk` FOREIGN KEY (`voter_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `election_opt_outs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `election_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `opted_out_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `election_opt_outs_unique` (`election_id`, `user_id`),
    CONSTRAINT `election_opt_outs_election_fk` FOREIGN KEY (`election_id`) REFERENCES `elections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `election_opt_outs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_destinations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `channel` VARCHAR(40) NOT NULL,
    `account_name` VARCHAR(140) NOT NULL,
    `account_number` VARCHAR(80) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `approved_by_adviser_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `payment_destinations_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `payment_destinations_adviser_fk` FOREIGN KEY (`approved_by_adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_submissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `destination_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `purpose` VARCHAR(140) NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `reference_number` VARCHAR(100) NOT NULL,
    `receipt_hdd_path` VARCHAR(255) NULL DEFAULT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `validated_by_treasurer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `validated_at` TIMESTAMP NULL DEFAULT NULL,
    `treasurer_notes` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `payment_submissions_campus_status_idx` (`campus_id`, `status`),
    CONSTRAINT `payment_submissions_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `payment_submissions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `payment_submissions_dest_fk` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `payment_submissions_treasurer_fk` FOREIGN KEY (`validated_by_treasurer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `peer_quotes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `submitted_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `quote_text` TEXT NOT NULL,
    `author_name` VARCHAR(120) NOT NULL DEFAULT 'CPF Peer Circle',
    `mood_tag` VARCHAR(40) NOT NULL DEFAULT 'Calm',
    `is_approved` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `peer_quotes_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE SET NULL,
    CONSTRAINT `peer_quotes_submitter_fk` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campus_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `description` TEXT NULL,
    `location` VARCHAR(160) NULL DEFAULT NULL,
    `event_date` DATE NOT NULL,
    `start_time` VARCHAR(5) NOT NULL,
    `end_time` VARCHAR(5) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pending_approval',
    `reflection_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `pending_reflection_enabled` TINYINT(1) NULL DEFAULT NULL,
    `reflection_requested_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reflection_requested_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by_user_id` BIGINT UNSIGNED NOT NULL,
    `approved_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `cancelled_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `cancellation_reason` VARCHAR(500) NULL DEFAULT NULL,
    `activated_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `campus_events_campus_date_idx` (`campus_id`, `event_date`),
    CONSTRAINT `campus_events_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_events_reflection_req_fk` FOREIGN KEY (`reflection_requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `campus_events_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `campus_events_approver_fk` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `campus_events_canceller_fk` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campus_event_attendances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_event_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `logged_in_at` TIMESTAMP NULL DEFAULT NULL,
    `logged_in_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `logged_out_at` TIMESTAMP NULL DEFAULT NULL,
    `logged_out_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campus_event_attendances_unique` (`campus_event_id`, `user_id`),
    CONSTRAINT `cea_event_fk` FOREIGN KEY (`campus_event_id`) REFERENCES `campus_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cea_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cea_in_by_fk` FOREIGN KEY (`logged_in_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `cea_out_by_fk` FOREIGN KEY (`logged_out_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campus_event_reflections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_event_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `time_capsule_id` BIGINT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `campus_event_reflections_unique` (`campus_event_id`, `user_id`),
    CONSTRAINT `cer_event_fk` FOREIGN KEY (`campus_event_id`) REFERENCES `campus_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cer_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cer_capsule_fk` FOREIGN KEY (`time_capsule_id`) REFERENCES `time_capsules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `campus_event_notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `campus_event_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `message` TEXT NOT NULL,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `campus_event_notifications_user_read_idx` (`user_id`, `read_at`),
    CONSTRAINT `cen_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cen_event_fk` FOREIGN KEY (`campus_event_id`) REFERENCES `campus_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `cen_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `adviser_handovers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campus_id` BIGINT UNSIGNED NOT NULL,
    `from_user_id` BIGINT UNSIGNED NOT NULL,
    `to_user_id` BIGINT UNSIGNED NOT NULL,
    `to_membership_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `outgoing_role` VARCHAR(32) NOT NULL DEFAULT 'member',
    `confirmation_code` VARCHAR(12) NOT NULL,
    `handover_notes` TEXT NULL,
    `initiated_at` TIMESTAMP NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `responded_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `adviser_handovers_campus_status_idx` (`campus_id`, `status`),
    KEY `adviser_handovers_to_user_status_idx` (`to_user_id`, `status`),
    CONSTRAINT `ah_campus_fk` FOREIGN KEY (`campus_id`) REFERENCES `campuses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ah_from_user_fk` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ah_to_user_fk` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ah_to_membership_fk` FOREIGN KEY (`to_membership_id`) REFERENCES `campus_memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
