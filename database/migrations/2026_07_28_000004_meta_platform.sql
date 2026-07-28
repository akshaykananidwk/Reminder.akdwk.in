-- ---------------------------------------------------------------------------
-- Official Meta WhatsApp Cloud API platform.
--
-- Replaces the bulk.akdwk.in gateway entirely. Everything here is addressed by
-- a `waba_account` row, so one installation can carry many businesses, each
-- with its own WABA, phone numbers, templates, webhooks and billing.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

-- One connected WhatsApp Business Account. Created by Embedded Signup, or by
-- pasting credentials by hand.
CREATE TABLE IF NOT EXISTS `waba_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` INT UNSIGNED NULL COMMENT 'tenant; NULL = the platform itself',
  `name` VARCHAR(190) NOT NULL,
  `waba_id` VARCHAR(32) NOT NULL COMMENT 'WhatsApp Business Account id',
  `business_id` VARCHAR(32) NULL COMMENT 'Meta Business Manager id',
  `access_token` TEXT NULL COMMENT 'AES-256-GCM encrypted',
  `token_type` ENUM('user','system_user','exchanged') NOT NULL DEFAULT 'system_user',
  `token_expires_at` DATETIME NULL COMMENT 'NULL = never (system user token)',
  `app_id` VARCHAR(32) NULL,
  `app_secret` TEXT NULL COMMENT 'AES-256-GCM encrypted; signs webhooks',
  `webhook_verify_token` VARCHAR(64) NULL,
  `webhook_subscribed_at` DATETIME NULL,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'INR',
  `timezone` VARCHAR(60) NOT NULL DEFAULT 'Asia/Kolkata',
  `status` ENUM('pending','active','suspended','disconnected') NOT NULL DEFAULT 'pending',
  `last_error` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_waba` (`waba_id`),
  KEY `idx_waba_owner` (`owner_user_id`),
  KEY `idx_waba_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A phone number registered under a WABA. A WABA can hold several.
CREATE TABLE IF NOT EXISTS `waba_phone_numbers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NOT NULL,
  `phone_number_id` VARCHAR(32) NOT NULL COMMENT 'what /messages is posted to',
  `display_number` VARCHAR(24) NULL,
  `verified_name` VARCHAR(190) NULL,
  `quality_rating` ENUM('GREEN','YELLOW','RED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  `messaging_limit` VARCHAR(32) NULL COMMENT 'TIER_1K, TIER_10K, …',
  `platform_type` VARCHAR(32) NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `registered_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_phone_number_id` (`phone_number_id`),
  KEY `idx_phone_waba` (`waba_account_id`, `is_active`),
  CONSTRAINT `fk_phone_waba` FOREIGN KEY (`waba_account_id`) REFERENCES `waba_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message templates, mirrored from Meta and submitted to it.
CREATE TABLE IF NOT EXISTS `wa_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NOT NULL,
  `meta_template_id` VARCHAR(32) NULL COMMENT 'set once Meta accepts it',
  `name` VARCHAR(512) NOT NULL COMMENT 'lowercase, underscores only',
  `language` VARCHAR(16) NOT NULL DEFAULT 'en',
  `category` ENUM('UTILITY','MARKETING','AUTHENTICATION') NOT NULL DEFAULT 'UTILITY',
  `status` ENUM('LOCAL','PENDING','APPROVED','REJECTED','PAUSED','DISABLED','IN_APPEAL')
      NOT NULL DEFAULT 'LOCAL',
  `rejected_reason` VARCHAR(255) NULL,
  `quality_score` VARCHAR(32) NULL,
  -- The full component array exactly as Meta expects it, so a template can be
  -- resubmitted or exported without rebuilding it from columns.
  `components` JSON NOT NULL,
  `example` JSON NULL,
  `variable_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `submitted_at` DATETIME NULL,
  `approved_at` DATETIME NULL,
  `synced_at` DATETIME NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_name_lang` (`waba_account_id`, `name`, `language`),
  KEY `idx_template_status` (`waba_account_id`, `status`),
  KEY `idx_template_meta` (`meta_template_id`),
  CONSTRAINT `fk_template_waba` FOREIGN KEY (`waba_account_id`) REFERENCES `waba_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A 24-hour billable conversation. Meta bills per conversation, not per
-- message, so cost belongs here and messages point at it.
CREATE TABLE IF NOT EXISTS `wa_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NOT NULL,
  `phone_number_id` VARCHAR(32) NOT NULL,
  `contact_wa_id` VARCHAR(24) NOT NULL,
  `conversation_id` VARCHAR(64) NULL COMMENT 'Meta conversation id',
  `category` ENUM('marketing','utility','authentication','service','referral_conversion','unknown')
      NOT NULL DEFAULT 'unknown',
  `origin_type` VARCHAR(32) NULL,
  `is_billable` TINYINT(1) NOT NULL DEFAULT 1,
  `pricing_model` VARCHAR(32) NULL,
  `price` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'INR',
  `country` VARCHAR(8) NULL,
  `expires_at` DATETIME NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conversation` (`conversation_id`),
  KEY `idx_conv_account_date` (`waba_account_id`, `started_at`),
  KEY `idx_conv_contact` (`contact_wa_id`),
  CONSTRAINT `fk_conv_waba` FOREIGN KEY (`waba_account_id`) REFERENCES `waba_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every message, in or out, with its delivery state and what Meta charged.
CREATE TABLE IF NOT EXISTS `wa_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NOT NULL,
  `phone_number_id` VARCHAR(32) NOT NULL,
  `user_id` INT UNSIGNED NULL COMMENT 'our account this belongs to, when known',
  `conversation_row_id` INT UNSIGNED NULL,
  `wamid` VARCHAR(190) NULL COMMENT 'Meta message id',
  `direction` ENUM('out','in') NOT NULL,
  `contact_wa_id` VARCHAR(24) NOT NULL,
  `message_type` VARCHAR(24) NOT NULL DEFAULT 'text',
  `body` TEXT NULL,
  `caption` VARCHAR(1024) NULL,
  `media_id` VARCHAR(190) NULL,
  `media_mime` VARCHAR(64) NULL,
  `media_sha256` VARCHAR(64) NULL,
  `filename` VARCHAR(255) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `template_name` VARCHAR(512) NULL,
  `template_language` VARCHAR(16) NULL,
  -- Reply context, so a threaded conversation view can be rebuilt.
  `context_wamid` VARCHAR(190) NULL,
  `interactive_type` VARCHAR(32) NULL,
  `interactive_reply_id` VARCHAR(256) NULL,
  `interactive_reply_title` VARCHAR(256) NULL,
  `status` ENUM('queued','sending','sent','delivered','read','failed','deleted')
      NOT NULL DEFAULT 'queued',
  `error_code` INT NULL,
  `error_title` VARCHAR(255) NULL,
  `error_detail` VARCHAR(500) NULL,
  -- Billing, copied from the status webhook. Kept per message as well as per
  -- conversation, because a report by message type has to come from somewhere.
  `billing_category` VARCHAR(32) NULL,
  `price` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `currency` VARCHAR(8) NULL,
  `country` VARCHAR(8) NULL,
  `payload` JSON NULL COMMENT 'raw Meta object, for anything not columnised',
  `sent_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `read_at` DATETIME NULL,
  `failed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wamid` (`wamid`),
  KEY `idx_msg_account_date` (`waba_account_id`, `created_at`),
  KEY `idx_msg_contact` (`contact_wa_id`, `created_at`),
  KEY `idx_msg_status` (`status`),
  KEY `idx_msg_user` (`user_id`),
  KEY `idx_msg_billing` (`waba_account_id`, `billing_category`, `created_at`),
  CONSTRAINT `fk_msg_waba` FOREIGN KEY (`waba_account_id`) REFERENCES `waba_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media uploaded to Meta. Ids are reusable for 30 days, and re-uploading the
-- same file wastes bandwidth and quota.
CREATE TABLE IF NOT EXISTS `wa_media` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NOT NULL,
  `phone_number_id` VARCHAR(32) NOT NULL,
  `media_id` VARCHAR(190) NOT NULL COMMENT 'Meta media id',
  `sha256` CHAR(64) NOT NULL COMMENT 'of the local file, for reuse',
  `mime_type` VARCHAR(64) NOT NULL,
  `filename` VARCHAR(255) NULL,
  `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
  `local_path` VARCHAR(512) NULL,
  `expires_at` DATETIME NULL COMMENT 'Meta drops media after ~30 days',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_hash` (`waba_account_id`, `sha256`),
  KEY `idx_media_id` (`media_id`),
  CONSTRAINT `fk_media_waba` FOREIGN KEY (`waba_account_id`) REFERENCES `waba_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every webhook Meta sends, stored before it is processed. Replaying a bad
-- deploy without this means the events are simply gone.
CREATE TABLE IF NOT EXISTS `wa_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_id` VARCHAR(32) NULL,
  `field` VARCHAR(64) NOT NULL COMMENT 'messages, message_template_status_update, …',
  `event_key` VARCHAR(190) NULL COMMENT 'dedup key, e.g. wamid + status',
  `payload` JSON NOT NULL,
  `signature_valid` TINYINT(1) NOT NULL DEFAULT 0,
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `error` VARCHAR(500) NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_key` (`event_key`),
  KEY `idx_event_field` (`field`, `received_at`),
  KEY `idx_event_pending` (`processed`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw Graph API calls, for support and for proving what was sent.
CREATE TABLE IF NOT EXISTS `wa_api_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waba_account_id` INT UNSIGNED NULL,
  `method` VARCHAR(8) NOT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `request` JSON NULL COMMENT 'tokens redacted before storing',
  `http_code` SMALLINT UNSIGNED NULL,
  `response` JSON NULL,
  `error_code` INT NULL,
  `latency_ms` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_apilog_account` (`waba_account_id`, `created_at`),
  KEY `idx_apilog_error` (`error_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The Meta rate card, per country and category.
--
-- Meta does NOT put the amount in the status webhook — it sends the pricing
-- *category* and leaves the price to the published rate card. So the amount has
-- to come from a table an operator can keep current, and the billing dashboard
-- has to say plainly that these are configured rates, not an invoice.
CREATE TABLE IF NOT EXISTS `wa_price_rates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_code` VARCHAR(8) NOT NULL COMMENT 'ISO-2, or DEFAULT for the fallback',
  `category` ENUM('marketing','utility','authentication','service','referral_conversion')
      NOT NULL,
  `price` DECIMAL(12,6) NOT NULL DEFAULT 0,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
  `effective_from` DATE NULL,
  `source` VARCHAR(120) NULL COMMENT 'where the operator got this number',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate` (`country_code`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zeroed placeholders rather than invented numbers: a wrong price shown with
-- confidence is worse than no price at all. Admin → WhatsApp → Pricing fills
-- these in from the current Meta rate card.
INSERT IGNORE INTO `wa_price_rates` (`country_code`, `category`, `price`, `currency`, `source`) VALUES
  ('DEFAULT','marketing',0,'USD','not set — enter the current Meta rate'),
  ('DEFAULT','utility',0,'USD','not set — enter the current Meta rate'),
  ('DEFAULT','authentication',0,'USD','not set — enter the current Meta rate'),
  ('DEFAULT','service',0,'USD','not set — enter the current Meta rate'),
  ('DEFAULT','referral_conversion',0,'USD','free tier'),
  ('IN','marketing',0,'INR','not set — enter the current Meta rate'),
  ('IN','utility',0,'INR','not set — enter the current Meta rate'),
  ('IN','authentication',0,'INR','not set — enter the current Meta rate'),
  ('IN','service',0,'INR','not set — enter the current Meta rate');

-- Settings for the platform itself (the Meta app used for Embedded Signup).
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `is_encrypted`) VALUES
  ('meta_app_id','','meta',0),
  ('meta_app_secret','','meta',1),
  ('meta_config_id','','meta',0),
  ('meta_graph_version','v23.0','meta',0),
  ('meta_system_user_token','','meta',1),
  ('meta_webhook_verify_token','','meta',0),
  ('meta_only_mode','1','meta',0),
  ('meta_price_markup_percent','0','meta',0);

-- Existing installs are switched to the official API. The bulk gateway
-- credentials are left in place rather than deleted: if a number turns out not
-- to be registered with the Cloud API yet, turning meta_only_mode off is a
-- one-click way back, and deleting them would make that impossible.
UPDATE `settings` SET `setting_value` = 'cloud' WHERE `setting_key` = 'wa_provider';
