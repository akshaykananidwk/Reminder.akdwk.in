-- ---------------------------------------------------------------------------
-- Centralised scheduler.
--
-- One server cron runs every minute. Everything else — what jobs exist, when
-- each is due, whether it is enabled, what happened last time — lives here and
-- is managed from Admin → Cron.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `cron_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` VARCHAR(60) NOT NULL COMMENT 'matches the registered job class',
  `label` VARCHAR(120) NOT NULL,
  `job_group` VARCHAR(40) NOT NULL DEFAULT 'general',

  -- Schedule. Three shapes cover everything this application does, and each is
  -- editable from the admin panel without anyone learning cron syntax.
  `schedule_kind` ENUM('every','daily','weekly') NOT NULL DEFAULT 'every',
  `interval_seconds` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'schedule_kind=every',
  `run_at` TIME NULL COMMENT 'local time for daily/weekly',
  `weekday` TINYINT UNSIGNED NULL COMMENT '0=Sunday … 6=Saturday, for weekly',

  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  -- A heavy job is run after the quick ones, so a nightly backup can never
  -- delay the minute-by-minute reminder dispatch.
  `is_heavy` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'lower runs first',
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 300,

  -- State.
  `next_run_at` DATETIME NULL,
  `last_run_at` DATETIME NULL,
  `last_finished_at` DATETIME NULL,
  `last_status` ENUM('never','ok','error','skipped','running','timeout') NOT NULL DEFAULT 'never',
  `last_message` VARCHAR(500) NULL,
  `last_duration_ms` INT UNSIGNED NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_runs` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_failures` INT UNSIGNED NOT NULL DEFAULT 0,

  -- Lock. Held in the database rather than in a file so it works when the
  -- application runs on more than one machine, or when PHP-FPM and the CLI
  -- disagree about whose /tmp is whose.
  `locked_at` DATETIME NULL,
  `locked_by` VARCHAR(64) NULL COMMENT 'host:pid of the holder',

  `is_registered` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = code for this job is gone',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_key` (`job_key`),
  KEY `idx_job_due` (`is_enabled`, `next_run_at`),
  KEY `idx_job_group` (`job_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cron_runs already exists and holds the history. It gains the columns the
-- centralised runner needs: which attempt this was, and the full error text
-- (message is capped at 500 characters and a stack trace does not fit).
SET @has_attempt := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cron_runs' AND column_name = 'attempt'
);
SET @sql := IF(@has_attempt = 0,
  'ALTER TABLE `cron_runs` ADD COLUMN `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_error := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cron_runs' AND column_name = 'error'
);
SET @sql := IF(@has_error = 0,
  'ALTER TABLE `cron_runs` ADD COLUMN `error` TEXT NULL AFTER `message`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The master runner records its own pass, and the admin panel triggers runs, so
-- triggered_by needs both values.
SET @trigger_type := (
  SELECT COLUMN_TYPE FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cron_runs' AND column_name = 'triggered_by'
);
SET @sql := IF(@trigger_type NOT LIKE '%master%',
  'ALTER TABLE `cron_runs` MODIFY COLUMN `triggered_by` ENUM(''cli'',''web'',''admin'',''master'',''retry'') NOT NULL DEFAULT ''cli''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A timeout is a distinct outcome from an error: the job may still be running.
SET @status_type := (
  SELECT COLUMN_TYPE FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'cron_runs' AND column_name = 'status'
);
SET @sql := IF(@status_type NOT LIKE '%timeout%',
  'ALTER TABLE `cron_runs` MODIFY COLUMN `status` ENUM(''running'',''ok'',''error'',''skipped'',''timeout'') NOT NULL DEFAULT ''running''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `is_encrypted`) VALUES
  ('scheduler_budget_seconds','50','cron',0),
  ('scheduler_lock_stale_minutes','30','cron',0),
  ('scheduler_history_days','30','cron',0),
  ('scheduler_enabled','1','cron',0);
