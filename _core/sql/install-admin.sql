-- =============================================================
-- Group portal: the ADMIN database (admin accounts only).
-- Run once in phpMyAdmin on the admin database.
-- =============================================================

CREATE TABLE adm_users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(60) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  totp_secret    VARCHAR(255) NULL,         -- encrypted with ADMIN_SECRET
  totp_last_step BIGINT UNSIGNED NULL,      -- blocks re-use of a code
  must_change_pw TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins  INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  created_at     DATETIME NOT NULL,
  last_login_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE adm_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id   INT UNSIGNED NULL,
  action     VARCHAR(40) CHARACTER SET ascii NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE adm_sessions (
  id            CHAR(64) CHARACTER SET ascii NOT NULL,
  user_id       INT UNSIGNED NULL,
  data          MEDIUMBLOB NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY ix_user (user_id),
  KEY ix_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
