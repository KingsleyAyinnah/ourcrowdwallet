-- ============================================================
-- OURCR ONLINE - Production Database Migration
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

CREATE DATABASE IF NOT EXISTS `ourcr_online`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `ourcr_online`;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36) NOT NULL,
    `username`              VARCHAR(50) NOT NULL,
    `email`                 VARCHAR(191) NOT NULL,
    `phone`                 VARCHAR(20) NOT NULL,
    `password_hash`         VARCHAR(255) NOT NULL,
    `transaction_pin`       VARCHAR(255) DEFAULT NULL COMMENT 'bcrypt hashed 4-digit PIN',
    `pin_set`               TINYINT(1) NOT NULL DEFAULT 0,
    `first_name`            VARCHAR(100) NOT NULL,
    `last_name`             VARCHAR(100) NOT NULL,
    `state_of_residence`    VARCHAR(100) DEFAULT NULL,
    `avatar`                VARCHAR(255) DEFAULT NULL,
    `role`                  ENUM('user','agent','reseller','admin','superadmin') NOT NULL DEFAULT 'user',
    `status`                ENUM('active','inactive','suspended','banned') NOT NULL DEFAULT 'inactive',
    `email_verified_at`     DATETIME DEFAULT NULL,
    `phone_verified_at`     DATETIME DEFAULT NULL,
    `referral_code`         VARCHAR(20) NOT NULL,
    `referred_by`           BIGINT UNSIGNED DEFAULT NULL,
    `wallet_balance`        DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `bonus_balance`         DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `last_login_at`         DATETIME DEFAULT NULL,
    `last_login_ip`         VARCHAR(45) DEFAULT NULL,
    `remember_token`        VARCHAR(100) DEFAULT NULL,
    `site_color`            VARCHAR(7) DEFAULT '#DC2626',
    `two_fa_enabled`        TINYINT(1) NOT NULL DEFAULT 0,
    `two_fa_secret`         VARCHAR(32) DEFAULT NULL,
    `login_attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`          DATETIME DEFAULT NULL,
    `deleted_at`            DATETIME DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid`      (`uuid`),
    UNIQUE KEY `uq_users_email`     (`email`),
    UNIQUE KEY `uq_users_phone`     (`phone`),
    UNIQUE KEY `uq_users_username`  (`username`),
    UNIQUE KEY `uq_users_referral`  (`referral_code`),
    KEY `idx_users_role`            (`role`),
    KEY `idx_users_status`          (`status`),
    KEY `idx_users_referred_by`     (`referred_by`),
    KEY `idx_users_deleted_at`      (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: email_verifications
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `token`         VARCHAR(100) NOT NULL,
    `otp`           VARCHAR(10) DEFAULT NULL,
    `otp_type`      ENUM('verification','password_reset') NOT NULL DEFAULT 'verification',
    `expires_at`    DATETIME NOT NULL,
    `used_at`       DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_token` (`token`),
    KEY `idx_ev_user_id` (`user_id`),
    CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: password_resets
-- ============================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `token`         VARCHAR(100) NOT NULL,
    `expires_at`    DATETIME NOT NULL,
    `used_at`       DATETIME DEFAULT NULL,
    `ip_address`    VARCHAR(45) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pr_token` (`token`),
    KEY `idx_pr_user_id` (`user_id`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: phone_verifications
-- ============================================================
CREATE TABLE IF NOT EXISTS `phone_verifications` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `otp`           VARCHAR(10) NOT NULL,
    `expires_at`    DATETIME NOT NULL,
    `used_at`       DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pv_user_id` (`user_id`),
    CONSTRAINT `fk_pv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: wallet_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `type`              ENUM('credit','debit') NOT NULL,
    `category`          VARCHAR(50) NOT NULL COMMENT 'deposit|withdrawal|transfer|airtime|data|cable_tv|electricity|betting|waec|jamb|neco|referral_bonus',
    `amount`            DECIMAL(15,2) NOT NULL,
    `fee`               DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `balance_before`    DECIMAL(15,2) NOT NULL,
    `balance_after`     DECIMAL(15,2) NOT NULL,
    `reference`         VARCHAR(100) NOT NULL,
    `description`       TEXT DEFAULT NULL,
    `status`            ENUM('pending','success','failed','reversed') NOT NULL DEFAULT 'pending',
    `meta`              JSON DEFAULT NULL COMMENT 'additional data (vtpass ref, gaps ref, etc)',
    `ip_address`        VARCHAR(45) DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wt_uuid`      (`uuid`),
    UNIQUE KEY `uq_wt_reference` (`reference`),
    KEY `idx_wt_user_id`         (`user_id`),
    KEY `idx_wt_type`            (`type`),
    KEY `idx_wt_category`        (`category`),
    KEY `idx_wt_status`          (`status`),
    KEY `idx_wt_created_at`      (`created_at`),
    CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: deposit_intents
-- ============================================================
CREATE TABLE IF NOT EXISTS `deposit_intents` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `amount`            DECIMAL(15,2) NOT NULL,
    `sender_name`       VARCHAR(191) NOT NULL,
    `expected_at`       DATETIME NOT NULL,
    `status`            ENUM('pending','matched','unmatched','expired','cancelled') NOT NULL DEFAULT 'pending',
    `gaps_reference`    VARCHAR(100) DEFAULT NULL,
    `wallet_txn_id`     BIGINT UNSIGNED DEFAULT NULL,
    `matched_at`        DATETIME DEFAULT NULL,
    `expires_at`        DATETIME NOT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_di_uuid` (`uuid`),
    KEY `idx_di_user_id`        (`user_id`),
    KEY `idx_di_status`         (`status`),
    KEY `idx_di_gaps_reference` (`gaps_reference`),
    CONSTRAINT `fk_di_user`     FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: gaps_transactions (raw inbound from GAPS statement)
-- ============================================================
CREATE TABLE IF NOT EXISTS `gaps_transactions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gaps_reference`    VARCHAR(100) NOT NULL,
    `transaction_type`  ENUM('credit','debit') NOT NULL DEFAULT 'credit',
    `amount`            DECIMAL(15,2) NOT NULL,
    `sender_name`       VARCHAR(255) DEFAULT NULL,
    `narration`         TEXT DEFAULT NULL,
    `transaction_date`  DATETIME NOT NULL,
    `balance_after`     DECIMAL(15,2) DEFAULT NULL,
    `matched`           TINYINT(1) NOT NULL DEFAULT 0,
    `deposit_intent_id` BIGINT UNSIGNED DEFAULT NULL,
    `processed_at`      DATETIME DEFAULT NULL,
    `raw_data`          JSON DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_gaps_reference` (`gaps_reference`),
    KEY `idx_gt_matched`        (`matched`),
    KEY `idx_gt_txn_date`       (`transaction_date`),
    KEY `idx_gt_sender_name`    (`sender_name`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: vtpass_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `vtpass_transactions` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36) NOT NULL,
    `user_id`               BIGINT UNSIGNED NOT NULL,
    `wallet_txn_id`         BIGINT UNSIGNED DEFAULT NULL,
    `service_id`            VARCHAR(100) NOT NULL,
    `service_type`          VARCHAR(50) NOT NULL,
    `request_id`            VARCHAR(100) NOT NULL,
    `phone`                 VARCHAR(30) DEFAULT NULL,
    `amount`                DECIMAL(15,2) NOT NULL,
    `quantity`              INT UNSIGNED DEFAULT 1,
    `subscription_code`     VARCHAR(100) DEFAULT NULL,
    `variation_code`        VARCHAR(100) DEFAULT NULL,
    `billersCode`           VARCHAR(100) DEFAULT NULL,
    `status`                ENUM('pending','success','failed','reversed') NOT NULL DEFAULT 'pending',
    `vtpass_ref`            VARCHAR(100) DEFAULT NULL,
    `vtpass_response_code`  VARCHAR(10) DEFAULT NULL,
    `vtpass_token`          TEXT DEFAULT NULL COMMENT 'e.g. electricity token',
    `request_payload`       JSON DEFAULT NULL,
    `response_payload`      JSON DEFAULT NULL,
    `retry_count`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `next_retry_at`         DATETIME DEFAULT NULL,
    `ip_address`            VARCHAR(45) DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vtpass_uuid`       (`uuid`),
    UNIQUE KEY `uq_vtpass_request_id` (`request_id`),
    KEY `idx_vt_user_id`    (`user_id`),
    KEY `idx_vt_status`     (`status`),
    KEY `idx_vt_service`    (`service_type`),
    KEY `idx_vt_created_at` (`created_at`),
    CONSTRAINT `fk_vt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: withdrawals
-- ============================================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `user_id`           BIGINT UNSIGNED NOT NULL,
    `wallet_txn_id`     BIGINT UNSIGNED DEFAULT NULL,
    `amount`            DECIMAL(15,2) NOT NULL,
    `fee`               DECIMAL(15,2) NOT NULL DEFAULT '50.00',
    `bank_name`         VARCHAR(100) NOT NULL,
    `account_number`    VARCHAR(20) NOT NULL,
    `account_name`      VARCHAR(191) NOT NULL,
    `status`            ENUM('pending','processing','success','failed','reversed') NOT NULL DEFAULT 'pending',
    `gaps_reference`    VARCHAR(100) DEFAULT NULL,
    `gaps_response`     JSON DEFAULT NULL,
    `processed_by`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'admin user id',
    `processed_at`      DATETIME DEFAULT NULL,
    `failure_reason`    TEXT DEFAULT NULL,
    `ip_address`        VARCHAR(45) DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_withdrawals_uuid` (`uuid`),
    KEY `idx_wd_user_id`    (`user_id`),
    KEY `idx_wd_status`     (`status`),
    KEY `idx_wd_created_at` (`created_at`),
    CONSTRAINT `fk_wd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: transfers
-- ============================================================
CREATE TABLE IF NOT EXISTS `transfers` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36) NOT NULL,
    `sender_id`             BIGINT UNSIGNED NOT NULL,
    `receiver_id`           BIGINT UNSIGNED NOT NULL,
    `amount`                DECIMAL(15,2) NOT NULL,
    `fee`                   DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `sender_txn_id`         BIGINT UNSIGNED DEFAULT NULL,
    `receiver_txn_id`       BIGINT UNSIGNED DEFAULT NULL,
    `description`           VARCHAR(255) DEFAULT NULL,
    `status`                ENUM('pending','success','failed','reversed') NOT NULL DEFAULT 'pending',
    `ip_address`            VARCHAR(45) DEFAULT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_transfers_uuid` (`uuid`),
    KEY `idx_tr_sender_id`   (`sender_id`),
    KEY `idx_tr_receiver_id` (`receiver_id`),
    KEY `idx_tr_status`      (`status`),
    CONSTRAINT `fk_tr_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tr_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: referrals
-- ============================================================
CREATE TABLE IF NOT EXISTS `referrals` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `referrer_id`   BIGINT UNSIGNED NOT NULL,
    `referred_id`   BIGINT UNSIGNED NOT NULL,
    `bonus_amount`  DECIMAL(15,2) NOT NULL DEFAULT '200.00',
    `bonus_paid`    TINYINT(1) NOT NULL DEFAULT 0,
    `status`        ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    `paid_at`       DATETIME DEFAULT NULL,
    `txn_id`        BIGINT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_referrals_referred` (`referred_id`),
    KEY `idx_ref_referrer_id` (`referrer_id`),
    KEY `idx_ref_status`      (`status`),
    CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `type`          ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
    `title`         VARCHAR(255) NOT NULL,
    `message`       TEXT NOT NULL,
    `is_read`       TINYINT(1) NOT NULL DEFAULT 0,
    `read_at`       DATETIME DEFAULT NULL,
    `action_url`    VARCHAR(500) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_user_id`  (`user_id`),
    KEY `idx_notif_is_read`  (`is_read`),
    KEY `idx_notif_created`  (`created_at`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED DEFAULT NULL,
    `action`        VARCHAR(100) NOT NULL,
    `description`   TEXT DEFAULT NULL,
    `subject_type`  VARCHAR(100) DEFAULT NULL,
    `subject_id`    BIGINT UNSIGNED DEFAULT NULL,
    `old_values`    JSON DEFAULT NULL,
    `new_values`    JSON DEFAULT NULL,
    `ip_address`    VARCHAR(45) DEFAULT NULL,
    `user_agent`    TEXT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_al_user_id`     (`user_id`),
    KEY `idx_al_action`      (`action`),
    KEY `idx_al_subject`     (`subject_type`, `subject_id`),
    KEY `idx_al_created_at`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: site_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `site_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `label`         VARCHAR(191) DEFAULT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: announcements
-- ============================================================
CREATE TABLE IF NOT EXISTS `announcements` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255) NOT NULL,
    `message`       TEXT NOT NULL,
    `type`          ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    `target`        ENUM('all','user','admin') NOT NULL DEFAULT 'all',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `starts_at`     DATETIME DEFAULT NULL,
    `ends_at`       DATETIME DEFAULT NULL,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ann_active`    (`is_active`),
    KEY `idx_ann_target`    (`target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: coupons
-- ============================================================
CREATE TABLE IF NOT EXISTS `coupons` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`              VARCHAR(50) NOT NULL,
    `type`              ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    `value`             DECIMAL(10,2) NOT NULL,
    `min_amount`        DECIMAL(15,2) DEFAULT NULL,
    `max_discount`      DECIMAL(15,2) DEFAULT NULL,
    `usage_limit`       INT UNSIGNED DEFAULT NULL,
    `usage_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `user_limit`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'per user limit',
    `valid_from`        DATETIME DEFAULT NULL,
    `valid_until`       DATETIME DEFAULT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`        BIGINT UNSIGNED DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coupon_code` (`code`),
    KEY `idx_coupon_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: coupon_usages
-- ============================================================
CREATE TABLE IF NOT EXISTS `coupon_usages` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coupon_id`     INT UNSIGNED NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `order_ref`     VARCHAR(100) DEFAULT NULL,
    `discount`      DECIMAL(10,2) NOT NULL,
    `used_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cu_coupon_id` (`coupon_id`),
    KEY `idx_cu_user_id`   (`user_id`),
    CONSTRAINT `fk_cu_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cu_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: support_tickets
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_number` VARCHAR(20) NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `subject`       VARCHAR(255) NOT NULL,
    `priority`      ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status`        ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `assigned_to`   BIGINT UNSIGNED DEFAULT NULL,
    `closed_at`     DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_number` (`ticket_number`),
    KEY `idx_st_user_id`    (`user_id`),
    KEY `idx_st_status`     (`status`),
    KEY `idx_st_priority`   (`priority`),
    CONSTRAINT `fk_st_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: support_messages
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_messages` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id`     BIGINT UNSIGNED NOT NULL,
    `sender_id`     BIGINT UNSIGNED NOT NULL,
    `message`       TEXT NOT NULL,
    `attachment`    VARCHAR(255) DEFAULT NULL,
    `is_admin`      TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sm_ticket_id` (`ticket_id`),
    KEY `idx_sm_sender_id` (`sender_id`),
    CONSTRAINT `fk_sm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sm_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: rate_limits
-- ============================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier`    VARCHAR(191) NOT NULL COMMENT 'ip or user_id',
    `action`        VARCHAR(100) NOT NULL,
    `attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `last_attempt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `blocked_until` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rl_identifier_action` (`identifier`, `action`),
    KEY `idx_rl_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: cron_jobs
-- ============================================================
CREATE TABLE IF NOT EXISTS `cron_jobs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `last_run_at`   DATETIME DEFAULT NULL,
    `next_run_at`   DATETIME DEFAULT NULL,
    `status`        ENUM('idle','running','success','failed') NOT NULL DEFAULT 'idle',
    `run_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `last_output`   TEXT DEFAULT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cron_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: admin_roles (RBAC)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_roles` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(50) NOT NULL,
    `slug`          VARCHAR(50) NOT NULL,
    `description`   VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: admin_permissions
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_permissions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `slug`          VARCHAR(100) NOT NULL,
    `group`         VARCHAR(50) NOT NULL DEFAULT 'general',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_perm_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: role_permissions (pivot)
-- ============================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`)       REFERENCES `admin_roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `admin_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_roles (pivot — links users to admin roles)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `role_id`       INT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
