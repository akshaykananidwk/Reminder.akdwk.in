-- Three changes, all idempotent and safe to re-run.
--
-- 1. Four-digit app PIN, set on the website and used to sign into the Android
--    app. Before this the app could only be signed into with a WhatsApp OTP, so
--    whenever the gateway was down nobody could sign in and the app showed an
--    empty list.
-- 2. Telegram as a third delivery channel.
-- 3. The site now defaults to English.

-- ------------------------------------------------------------------ 1. PIN

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'app_pin_hash') = 0,
  'ALTER TABLE `users`
     ADD COLUMN `app_pin_hash` VARCHAR(255) NULL AFTER `password_hash`,
     ADD COLUMN `app_pin_set_at` DATETIME NULL AFTER `app_pin_hash`,
     ADD COLUMN `app_pin_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `app_pin_set_at`,
     ADD COLUMN `app_pin_last_fail_at` DATETIME NULL AFTER `app_pin_attempts`,
     ADD COLUMN `app_pin_locked_until` DATETIME NULL AFTER `app_pin_last_fail_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------- 2. Telegram

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'telegram_chat_id') = 0,
  'ALTER TABLE `users`
     ADD COLUMN `telegram_chat_id` VARCHAR(32) NULL AFTER `google_id`,
     ADD COLUMN `telegram_username` VARCHAR(64) NULL AFTER `telegram_chat_id`,
     ADD COLUMN `telegram_linked_at` DATETIME NULL AFTER `telegram_username`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_telegram') = 0,
  'ALTER TABLE `users` ADD KEY `idx_users_telegram` (`telegram_chat_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One-time codes a user sends to the bot as `/start <code>` to link their
-- account. Short-lived and single use.
CREATE TABLE IF NOT EXISTS `telegram_link_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tg_code` (`code`),
  KEY `idx_tg_user` (`user_id`),
  CONSTRAINT `fk_tg_code_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The outbound queue and log carry a channel so Telegram and WhatsApp share
-- the same worker, retry policy and history.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'wa_outbound_queue' AND column_name = 'channel') = 0,
  'ALTER TABLE `wa_outbound_queue` ADD COLUMN `channel` VARCHAR(16) NOT NULL DEFAULT ''whatsapp'' AFTER `to_number`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'wa_outbound_log' AND column_name = 'channel') = 0,
  'ALTER TABLE `wa_outbound_log` ADD COLUMN `channel` VARCHAR(16) NOT NULL DEFAULT ''whatsapp'' AFTER `to_number`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Every enum that records where something came from has to learn the new
-- channel, or the inserts fail (strict mode) or silently truncate (otherwise).
ALTER TABLE `ai_queue`
  MODIFY COLUMN `source` ENUM('whatsapp','telegram','app','web','api') NOT NULL DEFAULT 'whatsapp';

ALTER TABLE `reminders`
  MODIFY COLUMN `source` ENUM('whatsapp','telegram','app','web','google','api','system') NOT NULL DEFAULT 'web';

ALTER TABLE `notes`
  MODIFY COLUMN `source` ENUM('whatsapp','telegram','app','web','api') NOT NULL DEFAULT 'app';

ALTER TABLE `templates`
  MODIFY COLUMN `channel` ENUM('whatsapp','telegram','push','email') NOT NULL DEFAULT 'whatsapp';

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `is_encrypted`) VALUES
  ('tg_enabled','0','telegram',0),
  ('tg_bot_token','','telegram',1),
  ('tg_bot_username','','telegram',0),
  ('tg_webhook_secret','','telegram',0),
  ('tg_send_reminders','1','telegram',0);

-- ------------------------------------------------------- 3. English default

UPDATE `settings` SET `setting_value` = 'en'
 WHERE `setting_key` = 'default_language' AND `setting_value` = 'gu';

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'language'
      AND column_default = 'gu') = 1,
  'ALTER TABLE `users` MODIFY COLUMN `language` ENUM(''gu'',''hi'',''en'') NOT NULL DEFAULT ''en''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
