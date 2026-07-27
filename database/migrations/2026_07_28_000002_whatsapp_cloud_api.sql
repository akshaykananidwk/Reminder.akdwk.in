-- Second WhatsApp provider: Meta WhatsApp Cloud API (graph.facebook.com).
--
-- The bulk.akdwk.in gateway stays exactly as it was. Both providers can be
-- configured at the same time; `wa_provider` decides which is tried first and
-- `wa_failover` allows the other to pick up when the first one fails.
--
-- Safe to re-run: every statement is idempotent.

-- Which provider handled each send, so a failure can be traced to the right
-- gateway instead of being guessed at.
SET @column_exists := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'wa_outbound_log'
     AND column_name = 'provider'
);

SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE `wa_outbound_log` ADD COLUMN `provider` VARCHAR(32) NOT NULL DEFAULT ''bulk'' AFTER `message`',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- New settings. INSERT IGNORE keeps any value an operator has already set.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `is_encrypted`) VALUES
  ('wa_provider','bulk','whatsapp',0),
  ('wa_failover','1','whatsapp',0),
  ('wa_cloud_token','','whatsapp',1),
  ('wa_cloud_app_secret','','whatsapp',1),
  ('wa_cloud_phone_id','','whatsapp',0),
  ('wa_cloud_business_id','','whatsapp',0),
  ('wa_cloud_api_version','v23.0','whatsapp',0),
  ('wa_cloud_verify_token','','whatsapp',0),
  ('wa_cloud_template_name','','whatsapp',0),
  ('wa_cloud_template_lang','gu','whatsapp',0);
