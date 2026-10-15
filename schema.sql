CREATE TABLE users (
  telegram_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  chat_id BIGINT NOT NULL,
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tablets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  telegram_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tablets_user (telegram_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  telegram_id BIGINT UNSIGNED NOT NULL,
  tablet_id INT UNSIGNED NOT NULL,
  intake_date DATE NOT NULL,
  taken_at DATETIME NOT NULL,
  UNIQUE KEY uniq_daily_intake (telegram_id, tablet_id, intake_date),
  KEY idx_logs_date (telegram_id, intake_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
