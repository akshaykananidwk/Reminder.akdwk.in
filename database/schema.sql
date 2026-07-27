-- ---------------------------------------------------------------------------
-- Krishna Reminder — full database schema
-- MySQL 5.7+ / MariaDB 10.3+ · InnoDB · utf8mb4_unicode_ci
-- All datetimes are stored in UTC; per-user timezones are applied on display.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------- Core settings

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` LONGTEXT NULL,
  `setting_group` VARCHAR(60) NOT NULL DEFAULT 'general',
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(191) NOT NULL,
  `batch` INT UNSIGNED NOT NULL DEFAULT 1,
  `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner','manager','support') NOT NULL DEFAULT 'owner',
  `totp_secret` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- Billing

CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `name_gu` VARCHAR(120) NULL,
  `name_hi` VARCHAR(120) NULL,
  `description` TEXT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `trial_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_reminders_month` INT NOT NULL DEFAULT 200 COMMENT '-1 = unlimited',
  `max_ai_messages_month` INT NOT NULL DEFAULT 200,
  `max_ai_tokens_month` INT NOT NULL DEFAULT 200000,
  `max_devices` INT NOT NULL DEFAULT 1,
  `max_staff` INT NOT NULL DEFAULT 0,
  `call_reminders` TINYINT(1) NOT NULL DEFAULT 1,
  `google_sync` TINYINT(1) NOT NULL DEFAULT 0,
  `api_access` TINYINT(1) NOT NULL DEFAULT 0,
  `full_reports` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------- Users

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NULL,
  `phone` VARCHAR(20) NOT NULL COMMENT 'primary WhatsApp number, digits with country code',
  `password_hash` VARCHAR(255) NULL,
  -- Four-digit app PIN: set on the website, used to sign into the Android app
  -- without an OTP. Bcrypt, throttled and locked out by AppPinService.
  `app_pin_hash` VARCHAR(255) NULL,
  `app_pin_set_at` DATETIME NULL,
  `app_pin_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `app_pin_last_fail_at` DATETIME NULL,
  `app_pin_locked_until` DATETIME NULL,
  `language` ENUM('gu','hi','en') NOT NULL DEFAULT 'en',
  `timezone` VARCHAR(60) NOT NULL DEFAULT 'Asia/Kolkata',
  `city` VARCHAR(120) NULL,
  `avatar` VARCHAR(255) NULL,
  `google_id` VARCHAR(64) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `reminders_paused` TINYINT(1) NOT NULL DEFAULT 0,
  `plan_id` INT UNSIGNED NULL,
  `plan_expires_at` DATETIME NULL,
  `referred_by` INT UNSIGNED NULL,
  `referral_code` VARCHAR(20) NULL,
  `streak_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `best_streak` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_phone` (`phone`),
  UNIQUE KEY `uq_user_email` (`email`),
  UNIQUE KEY `uq_referral_code` (`referral_code`),
  KEY `idx_users_plan` (`plan_id`),
  KEY `idx_users_active` (`is_active`, `deleted_at`),
  CONSTRAINT `fk_users_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` INT UNSIGNED NOT NULL,
  `morning_brief_time` TIME NOT NULL DEFAULT '07:30:00',
  `night_summary_time` TIME NOT NULL DEFAULT '21:30:00',
  `morning_brief_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `night_summary_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `dnd_start` TIME NULL DEFAULT '23:00:00',
  `dnd_end` TIME NULL DEFAULT '06:30:00',
  `dnd_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `holiday_mode_until` DATE NULL,
  `default_time` TIME NOT NULL DEFAULT '09:00:00',
  `default_snooze_min` INT UNSIGNED NOT NULL DEFAULT 5,
  `max_snoozes` INT UNSIGNED NOT NULL DEFAULT 5,
  `call_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `call_gap_minutes` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `ring_seconds` TINYINT UNSIGNED NOT NULL DEFAULT 45,
  `whatsapp_fallback` TINYINT(1) NOT NULL DEFAULT 1,
  `ringtone` VARCHAR(60) NOT NULL DEFAULT 'flute',
  `tts_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `tts_voice` VARCHAR(60) NULL,
  `tts_speed` DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  `wa_notify_created` TINYINT(1) NOT NULL DEFAULT 1,
  `wa_notify_due` TINYINT(1) NOT NULL DEFAULT 1,
  `wa_notify_done` TINYINT(1) NOT NULL DEFAULT 0,
  `wa_notify_missed` TINYINT(1) NOT NULL DEFAULT 1,
  `web_push_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `web_push_subscription` TEXT NULL,
  `theme` ENUM('light','dark','auto') NOT NULL DEFAULT 'auto',
  `google_sync_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `google_tasks_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_numbers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `number` VARCHAR(20) NOT NULL,
  `label` VARCHAR(60) NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_number` (`number`),
  KEY `idx_wa_user` (`user_id`),
  KEY `idx_wa_lookup` (`number`, `is_verified`, `is_active`),
  CONSTRAINT `fk_wa_numbers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `purpose` ENUM('register','login','verify_number','reset_password','delete_account') NOT NULL DEFAULT 'login',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`phone`, `purpose`, `is_used`),
  KEY `idx_otp_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `device_uid` VARCHAR(128) NOT NULL COMMENT 'stable client-generated id',
  `fcm_token` VARCHAR(512) NULL,
  `name` VARCHAR(120) NULL,
  `model` VARCHAR(120) NULL,
  `manufacturer` VARCHAR(80) NULL,
  `os_version` VARCHAR(40) NULL,
  `app_version` VARCHAR(40) NULL,
  `platform` ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  `push_ok` TINYINT(1) NOT NULL DEFAULT 1,
  `last_seen_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_uid` (`user_id`, `device_uid`),
  KEY `idx_devices_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('web','api','admin') NOT NULL DEFAULT 'web',
  `token_hash` VARCHAR(64) NOT NULL,
  `refresh_token_hash` VARCHAR(64) NULL,
  `device_id` INT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `revoked` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `refresh_expires_at` DATETIME NULL,
  `last_used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_token` (`token_hash`),
  KEY `idx_sessions_refresh` (`refresh_token_hash`),
  KEY `idx_sessions_user` (`user_id`, `revoked`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- Taxonomies

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL COMMENT 'NULL = system category',
  `code` VARCHAR(40) NOT NULL,
  `name_gu` VARCHAR(80) NOT NULL,
  `name_hi` VARCHAR(80) NOT NULL,
  `name_en` VARCHAR(80) NOT NULL,
  `icon` VARCHAR(40) NULL,
  `color` VARCHAR(9) NOT NULL DEFAULT '#1B3A6B',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categories_user` (`user_id`),
  CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `color` VARCHAR(9) NOT NULL DEFAULT '#F2B33D',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_user_name` (`user_id`, `name`),
  CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(190) NULL,
  `role` VARCHAR(80) NULL,
  `notes` TEXT NULL,
  `linked_user_id` INT UNSIGNED NULL COMMENT 'set when the contact is also a registered user (staff)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contacts_user` (`user_id`, `deleted_at`),
  CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_contacts_linked` FOREIGN KEY (`linked_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- Reminders

CREATE TABLE IF NOT EXISTS `reminders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `short_code` VARCHAR(12) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `type` ENUM('task','payment','call','meeting','medicine','birthday','bill','note','other') NOT NULL DEFAULT 'task',
  `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `category_id` INT UNSIGNED NULL,
  `contact_id` INT UNSIGNED NULL,
  `start_at` DATETIME NOT NULL COMMENT 'UTC — first due time',
  `end_at` DATETIME NULL COMMENT 'UTC — recurrence end date',
  `all_day` TINYINT(1) NOT NULL DEFAULT 0,
  `recurrence` JSON NULL,
  `recurrence_count` INT UNSIGNED NULL,
  `materialised_until` DATETIME NULL,
  `call_reminder` TINYINT(1) NOT NULL DEFAULT 1,
  `advance_alerts` JSON NULL COMMENT 'minutes before, e.g. [1440,60,10]',
  `snooze_default_min` INT UNSIGNED NOT NULL DEFAULT 5,
  `call_attempts` TINYINT UNSIGNED NULL,
  `amount` DECIMAL(12,2) NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `person_name` VARCHAR(120) NULL,
  `person_phone` VARCHAR(20) NULL,
  `location` VARCHAR(255) NULL,
  `color` VARCHAR(9) NULL,
  `source` ENUM('whatsapp','app','web','google','api','system') NOT NULL DEFAULT 'web',
  `source_ref` VARCHAR(120) NULL,
  `assigned_to` INT UNSIGNED NULL,
  `parent_id` INT UNSIGNED NULL COMMENT 'follow-up chain parent',
  `status` ENUM('active','completed','cancelled','paused') NOT NULL DEFAULT 'active',
  `ai_confidence` DECIMAL(4,3) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder_shortcode` (`user_id`, `short_code`),
  KEY `idx_reminders_user_status` (`user_id`, `status`, `deleted_at`),
  KEY `idx_reminders_start` (`start_at`),
  KEY `idx_reminders_type` (`user_id`, `type`),
  KEY `idx_reminders_assigned` (`assigned_to`),
  CONSTRAINT `fk_reminders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminders_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reminders_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reminders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reminders_parent` FOREIGN KEY (`parent_id`) REFERENCES `reminders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminder_tags` (
  `reminder_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`reminder_id`, `tag_id`),
  KEY `idx_reminder_tags_tag` (`tag_id`),
  CONSTRAINT `fk_rt_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rt_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminder_occurrences` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reminder_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `due_at` DATETIME NOT NULL COMMENT 'UTC',
  `original_due_at` DATETIME NULL,
  `sequence` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('pending','notified','done','snoozed','missed','cancelled') NOT NULL DEFAULT 'pending',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NULL,
  `snooze_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `notified_at` DATETIME NULL,
  `done_at` DATETIME NULL,
  `done_via` ENUM('app','whatsapp','web','api','auto') NULL,
  `done_by` INT UNSIGNED NULL,
  `advance_sent` JSON NULL COMMENT 'which advance alerts already fired',
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_occurrence` (`reminder_id`, `due_at`),
  KEY `idx_occ_due_status` (`due_at`, `status`),
  KEY `idx_occ_user_status` (`user_id`, `status`, `due_at`),
  KEY `idx_occ_next_attempt` (`next_attempt_at`, `status`),
  CONSTRAINT `fk_occ_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_occ_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subtasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reminder_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `is_done` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subtasks_reminder` (`reminder_id`),
  CONSTRAINT `fk_subtasks_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `reminder_id` INT UNSIGNED NULL,
  `payment_id` INT UNSIGNED NULL,
  `note_id` INT UNSIGNED NULL,
  `kind` ENUM('image','pdf','audio','other') NOT NULL DEFAULT 'other',
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(120) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_user` (`user_id`),
  KEY `idx_attach_reminder` (`reminder_id`),
  CONSTRAINT `fk_attach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attach_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reminder_id` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NOT NULL,
  `assigned_to_user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','accepted','done','rejected','missed') NOT NULL DEFAULT 'pending',
  `responded_at` DATETIME NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assign_to` (`assigned_to_user_id`, `status`),
  KEY `idx_assign_reminder` (`reminder_id`),
  CONSTRAINT `fk_assign_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_to` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- Deliveries

CREATE TABLE IF NOT EXISTS `deliveries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurrence_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `kind` ENUM('call','advance','fallback','assignment') NOT NULL DEFAULT 'call',
  `attempt_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('queued','sent','failed','answered') NOT NULL DEFAULT 'queued',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deliveries_occ` (`occurrence_id`),
  KEY `idx_deliveries_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_deliveries_occ` FOREIGN KEY (`occurrence_id`) REFERENCES `reminder_occurrences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` INT UNSIGNED NOT NULL,
  `channel` ENUM('fcm','whatsapp','webpush','email') NOT NULL,
  `target` VARCHAR(255) NULL COMMENT 'device id or phone number',
  `attempt_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `sent_at` DATETIME NULL,
  `result` ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  `response` TEXT NULL,
  `latency_ms` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_delivery` (`delivery_id`),
  CONSTRAINT `fk_attempts_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_responses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurrence_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` ENUM('done','snooze','reschedule','cancel','dismiss','open') NOT NULL,
  `source` ENUM('app','whatsapp','web','api','watch') NOT NULL DEFAULT 'app',
  `payload` JSON NULL,
  `device_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_responses_occ` (`occurrence_id`),
  KEY `idx_responses_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_responses_occ` FOREIGN KEY (`occurrence_id`) REFERENCES `reminder_occurrences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_responses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- Notes

CREATE TABLE IF NOT EXISTS `notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NULL,
  `body` TEXT NOT NULL,
  `source` ENUM('whatsapp','app','web','api') NOT NULL DEFAULT 'app',
  `source_ref` VARCHAR(120) NULL,
  `converted_reminder_id` INT UNSIGNED NULL,
  `pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notes_user` (`user_id`, `deleted_at`),
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_reminder` FOREIGN KEY (`converted_reminder_id`) REFERENCES `reminders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Payments

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `reminder_id` INT UNSIGNED NULL,
  `contact_id` INT UNSIGNED NULL,
  `party_name` VARCHAR(160) NOT NULL,
  `direction` ENUM('payable','receivable') NOT NULL DEFAULT 'payable',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `due_date` DATE NOT NULL,
  `status` ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `is_emi` TINYINT(1) NOT NULL DEFAULT 0,
  `emi_total` INT UNSIGNED NULL,
  `emi_index` INT UNSIGNED NULL,
  `note` VARCHAR(255) NULL,
  `receipt_attachment_id` INT UNSIGNED NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payments_user` (`user_id`, `status`, `due_date`),
  KEY `idx_payments_reminder` (`reminder_id`),
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `method` VARCHAR(60) NULL,
  `note` VARCHAR(255) NULL,
  `attachment_id` INT UNSIGNED NULL,
  `paid_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ptx_payment` (`payment_id`),
  KEY `idx_ptx_user` (`user_id`, `paid_at`),
  CONSTRAINT `fk_ptx_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptx_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- WhatsApp

CREATE TABLE IF NOT EXISTS `wa_inbound_raw` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `from_number` VARCHAR(20) NOT NULL,
  `gateway_message_id` VARCHAR(191) NULL,
  `message_type` VARCHAR(30) NOT NULL DEFAULT 'text',
  `body` TEXT NULL,
  `media_url` VARCHAR(512) NULL,
  `payload` JSON NULL,
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `handled_by` ENUM('command','ai','fallback','ignored') NULL,
  `ai_result` JSON NULL,
  `reply_sent` TEXT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_gateway_msg` (`gateway_message_id`),
  KEY `idx_wa_in_user` (`user_id`, `received_at`),
  KEY `idx_wa_in_processed` (`processed`),
  CONSTRAINT `fk_wa_in_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unknown_inbound` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_number` VARCHAR(20) NOT NULL,
  `body` TEXT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  `invite_sent_at` DATETIME NULL,
  `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unknown_number` (`from_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_outbound_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `to_number` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `media_url` VARCHAR(512) NULL,
  `template_key` VARCHAR(60) NULL,
  `priority` TINYINT NOT NULL DEFAULT 5 COMMENT '1 = highest',
  `status` ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NULL,
  `last_error` VARCHAR(500) NULL,
  `scheduled_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_waq_status` (`status`, `priority`, `next_attempt_at`),
  KEY `idx_waq_user` (`user_id`),
  CONSTRAINT `fk_waq_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_outbound_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NULL,
  `to_number` VARCHAR(20) NOT NULL,
  `message` TEXT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'bulk',
  `request_id` VARCHAR(64) NULL,
  `http_code` SMALLINT UNSIGNED NULL,
  `response` TEXT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `latency_ms` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_walog_user` (`user_id`, `created_at`),
  KEY `idx_walog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- AI

CREATE TABLE IF NOT EXISTS `ai_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `inbound_id` INT UNSIGNED NULL,
  `source` ENUM('whatsapp','app','web','api') NOT NULL DEFAULT 'whatsapp',
  `text` TEXT NOT NULL,
  `media_url` VARCHAR(512) NULL,
  `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `result` JSON NULL,
  `error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aiq_status` (`status`, `created_at`),
  KEY `idx_aiq_user` (`user_id`),
  CONSTRAINT `fk_aiq_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aiq_inbound` FOREIGN KEY (`inbound_id`) REFERENCES `wa_inbound_raw` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `model` VARCHAR(60) NOT NULL,
  `purpose` VARCHAR(40) NOT NULL DEFAULT 'parse',
  `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `latency_ms` INT UNSIGNED NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `cached` TINYINT(1) NOT NULL DEFAULT 0,
  `error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ailogs_user_date` (`user_id`, `created_at`),
  KEY `idx_ailogs_created` (`created_at`),
  CONSTRAINT `fk_ailogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_cache` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` CHAR(64) NOT NULL,
  `response` LONGTEXT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_cache_key` (`cache_key`),
  KEY `idx_ai_cache_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Google

CREATE TABLE IF NOT EXISTS `google_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `google_user_id` VARCHAR(64) NULL,
  `email` VARCHAR(190) NULL,
  `access_token` TEXT NULL,
  `refresh_token` TEXT NULL,
  `token_expires_at` DATETIME NULL,
  `scopes` VARCHAR(500) NULL,
  `calendar_id` VARCHAR(190) NOT NULL DEFAULT 'primary',
  `tasklist_id` VARCHAR(190) NULL,
  `sync_token` VARCHAR(255) NULL,
  `last_sync_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_user` (`user_id`),
  CONSTRAINT `fk_google_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_sync_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `reminder_id` INT UNSIGNED NULL,
  `google_kind` ENUM('event','task') NOT NULL DEFAULT 'event',
  `google_id` VARCHAR(191) NOT NULL,
  `etag` VARCHAR(191) NULL,
  `local_updated_at` DATETIME NULL,
  `remote_updated_at` DATETIME NULL,
  `direction` ENUM('push','pull','both') NOT NULL DEFAULT 'both',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_map` (`user_id`, `google_kind`, `google_id`),
  KEY `idx_gmap_reminder` (`reminder_id`),
  CONSTRAINT `fk_gmap_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gmap_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `reminders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------- Subscriptions & money

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `discount_type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `plan_id` INT UNSIGNED NULL,
  `max_uses` INT UNSIGNED NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `valid_from` DATE NULL,
  `valid_until` DATE NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','active','expired','cancelled','grace') NOT NULL DEFAULT 'pending',
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `coupon_id` INT UNSIGNED NULL,
  `payment_method` VARCHAR(40) NULL,
  `payment_ref` VARCHAR(120) NULL,
  `approved_by` INT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subs_user` (`user_id`, `status`),
  KEY `idx_subs_ends` (`ends_at`, `status`),
  CONSTRAINT `fk_subs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subs_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_subs_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(40) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `subscription_id` INT UNSIGNED NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` ENUM('draft','unpaid','paid','refunded','void') NOT NULL DEFAULT 'unpaid',
  `billing_name` VARCHAR(160) NULL,
  `billing_address` TEXT NULL,
  `gstin` VARCHAR(20) NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_no` (`invoice_no`),
  KEY `idx_invoices_user` (`user_id`),
  CONSTRAINT `fk_invoices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referrals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_id` INT UNSIGNED NOT NULL,
  `referred_id` INT UNSIGNED NOT NULL,
  `status` ENUM('signed_up','converted','cancelled') NOT NULL DEFAULT 'signed_up',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `converted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referred` (`referred_id`),
  KEY `idx_referrer` (`referrer_id`),
  CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `referral_id` INT UNSIGNED NULL,
  `subscription_id` INT UNSIGNED NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `type` ENUM('earned','payout','adjustment') NOT NULL DEFAULT 'earned',
  `status` ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_commissions_user` (`user_id`, `status`),
  CONSTRAINT `fk_comm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comm_referral` FOREIGN KEY (`referral_id`) REFERENCES `referrals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- Platform plumbing

CREATE TABLE IF NOT EXISTS `templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(60) NOT NULL,
  `lang` ENUM('gu','hi','en') NOT NULL DEFAULT 'gu',
  `channel` ENUM('whatsapp','push','email') NOT NULL DEFAULT 'whatsapp',
  `subject` VARCHAR(190) NULL,
  `body` TEXT NOT NULL,
  `variables` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template` (`template_key`, `lang`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job` VARCHAR(60) NOT NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `duration_ms` INT UNSIGNED NULL,
  `rows_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('running','ok','error','skipped') NOT NULL DEFAULT 'running',
  `message` VARCHAR(500) NULL,
  `triggered_by` ENUM('cli','web','admin') NOT NULL DEFAULT 'cli',
  PRIMARY KEY (`id`),
  KEY `idx_cron_job` (`job`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `error_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` VARCHAR(20) NOT NULL DEFAULT 'error',
  `message` VARCHAR(1000) NOT NULL,
  `file` VARCHAR(255) NULL,
  `line` INT UNSIGNED NULL,
  `trace` MEDIUMTEXT NULL,
  `url` VARCHAR(255) NULL,
  `user_id` INT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_errors_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('admin','user','system') NOT NULL DEFAULT 'admin',
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(120) NOT NULL,
  `target_type` VARCHAR(60) NULL,
  `target_id` INT UNSIGNED NULL,
  `details` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_type`, `actor_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(190) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `context` VARCHAR(40) NOT NULL DEFAULT 'user',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_identifier` (`identifier`, `created_at`),
  KEY `idx_login_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rl_key` VARCHAR(190) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_key` (`rl_key`),
  KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blocklist` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('ip','number','email') NOT NULL DEFAULT 'number',
  `value` VARCHAR(190) NOT NULL,
  `reason` VARCHAR(255) NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_block` (`kind`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL DEFAULT 'Default',
  `key_prefix` VARCHAR(12) NOT NULL,
  `key_hash` VARCHAR(64) NOT NULL,
  `scopes` VARCHAR(255) NOT NULL DEFAULT 'reminders:read,reminders:write',
  `last_used_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apikey_hash` (`key_hash`),
  KEY `idx_apikeys_user` (`user_id`),
  CONSTRAINT `fk_apikeys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_webhooks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `secret` VARCHAR(120) NULL,
  `events` VARCHAR(255) NOT NULL DEFAULT 'reminder.created,reminder.due,reminder.done',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_status` SMALLINT UNSIGNED NULL,
  `last_called_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_webhooks_user` (`user_id`),
  CONSTRAINT `fk_webhooks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `idem_key` VARCHAR(120) NOT NULL,
  `endpoint` VARCHAR(120) NOT NULL,
  `response` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idem` (`user_id`, `idem_key`),
  KEY `idx_idem_created` (`created_at`),
  CONSTRAINT `fk_idem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `summaries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `summary_date` DATE NOT NULL,
  `kind` ENUM('morning','night','weekly','monthly') NOT NULL DEFAULT 'night',
  `done_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `pending_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `missed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `body` TEXT NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_summary` (`user_id`, `summary_date`, `kind`),
  CONSTRAINT `fk_summaries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `streaks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `streak_date` DATE NOT NULL,
  `all_done` TINYINT(1) NOT NULL DEFAULT 0,
  `done_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_streak` (`user_id`, `streak_date`),
  CONSTRAINT `fk_streaks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` TEXT NULL,
  `kind` VARCHAR(40) NOT NULL DEFAULT 'info',
  `link` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` TEXT NOT NULL,
  `audience` VARCHAR(60) NOT NULL DEFAULT 'all',
  `channels` VARCHAR(60) NOT NULL DEFAULT 'whatsapp,app',
  `recipients` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('draft','queued','sent') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `update_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_version` VARCHAR(40) NULL,
  `to_version` VARCHAR(40) NULL,
  `commit_sha` VARCHAR(40) NULL,
  `commit_message` VARCHAR(500) NULL,
  `status` ENUM('running','success','failed','rolled_back') NOT NULL DEFAULT 'running',
  `steps` JSON NULL,
  `backup_id` INT UNSIGNED NULL,
  `error` TEXT NULL,
  `admin_id` INT UNSIGNED NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('database','files','full') NOT NULL DEFAULT 'full',
  `filename` VARCHAR(255) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `trigger_source` ENUM('cron','admin','update') NOT NULL DEFAULT 'cron',
  `status` ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  `error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backups_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Content

CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(120) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` LONGTEXT NULL,
  `meta_title` VARCHAR(190) NULL,
  `meta_description` VARCHAR(300) NULL,
  `lang` ENUM('gu','hi','en') NOT NULL DEFAULT 'en',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(160) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `excerpt` VARCHAR(500) NULL,
  `body` LONGTEXT NULL,
  `cover_image` VARCHAR(255) NULL,
  `meta_title` VARCHAR(190) NULL,
  `meta_description` VARCHAR(300) NULL,
  `lang` ENUM('gu','hi','en') NOT NULL DEFAULT 'gu',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `published_at` DATETIME NULL,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_slug` (`slug`),
  KEY `idx_posts_published` (`is_published`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faqs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question` VARCHAR(300) NOT NULL,
  `answer` TEXT NOT NULL,
  `lang` ENUM('gu','hi','en') NOT NULL DEFAULT 'gu',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faqs_lang` (`lang`, `is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `city` VARCHAR(120) NULL,
  `business` VARCHAR(160) NULL,
  `body` TEXT NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `avatar` VARCHAR(255) NULL,
  `lang` ENUM('gu','hi','en') NOT NULL DEFAULT 'gu',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(190) NULL,
  `subject` VARCHAR(190) NULL,
  `message` TEXT NOT NULL,
  `ip` VARCHAR(45) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contactmsg_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
