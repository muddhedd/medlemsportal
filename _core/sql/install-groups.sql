-- Medlemsportal – the GROUPS database. Run once on an empty database.

-- ---- groups-schema.sql ----
CREATE TABLE grp_groups (
  code             CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug             VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  country          VARCHAR(100) NOT NULL DEFAULT '',
  city             VARCHAR(100) NOT NULL DEFAULT '',
  name             VARCHAR(150) NOT NULL,
  profile          VARCHAR(10)  CHARACTER SET ascii NOT NULL DEFAULT 'default',
  lang             CHAR(2)      CHARACTER SET ascii NOT NULL DEFAULT 'sv',
  status           ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  address          TEXT NULL,
  landlord         VARCHAR(200) NULL,
  landlord_contact TEXT NULL,
  created_at       DATETIME NOT NULL,
  deleted_at       DATETIME NULL,
  PRIMARY KEY (code),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Old addresses keep working after a rename (redirect).
CREATE TABLE grp_slug_history (
  old_slug   VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (old_slug),
  CONSTRAINT fk_slug_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One account per person; may be a member of several groups.
-- No names, e-mail or phone numbers: only an anonymous username and an
-- optional display name the user chooses themselves.
CREATE TABLE grp_users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  home_group      CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
  password_hash   VARCHAR(255) NOT NULL,
  must_change_pw  TINYINT(1) NOT NULL DEFAULT 1,
  otp_expires_at  DATETIME NULL,
  display_name    VARCHAR(60) NULL,
  failed_logins   INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  created_at      DATETIME NOT NULL,
  last_login_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  KEY ix_home (home_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grp_memberships (
  user_id    INT UNSIGNED NOT NULL,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status     ENUM('active','invited') NOT NULL DEFAULT 'active',
  invited_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, group_code),
  KEY ix_group (group_code, status),
  CONSTRAINT fk_mem_user  FOREIGN KEY (user_id)    REFERENCES grp_users (id)    ON DELETE CASCADE,
  CONSTRAINT fk_mem_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed roles have a role_key (chair, treasurer, keyholder, admin); custom roles have a name.
CREATE TABLE grp_roles (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role_key   VARCHAR(20) CHARACTER SET ascii NULL,
  name       VARCHAR(60) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_key (group_code, role_key),
  CONSTRAINT fk_role_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom roles: their permissions. Fixed roles: only the switched-on optional permissions.
CREATE TABLE grp_role_perms (
  role_id INT UNSIGNED NOT NULL,
  perm    VARCHAR(40) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (role_id, perm),
  CONSTRAINT fk_perm_role FOREIGN KEY (role_id) REFERENCES grp_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A user can hold several roles in a group (small groups often double up).
CREATE TABLE grp_member_roles (
  user_id    INT UNSIGNED NOT NULL,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  KEY ix_group (group_code),
  CONSTRAINT fk_mr_user  FOREIGN KEY (user_id)    REFERENCES grp_users (id)    ON DELETE CASCADE,
  CONSTRAINT fk_mr_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_mr_role  FOREIGN KEY (role_id)    REFERENCES grp_roles (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity log, cleaned automatically after 90 days.
CREATE TABLE grp_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(40) CHARACTER SET ascii NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_group_time (group_code, created_at),
  KEY ix_time (created_at),
  CONSTRAINT fk_log_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions (ids stored hashed).
CREATE TABLE grp_sessions (
  id            CHAR(64) CHARACTER SET ascii NOT NULL,
  user_id       INT UNSIGNED NULL,
  data          MEDIUMBLOB NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY ix_user (user_id),
  KEY ix_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- step2-meetings.sql ----
-- A meeting = a series (one-off or recurring). Single dates are calculated.
CREATE TABLE grp_meeting_series (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code      CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title           VARCHAR(100) NOT NULL,
  description     TEXT NULL,
  start_date      DATE NOT NULL,
  start_time      TIME NOT NULL,
  end_time        TIME NULL,
  is_open         TINYINT(1) NOT NULL DEFAULT 1,
  recur           ENUM('none','daily','weekly','monthly_date','monthly_nth') NOT NULL DEFAULT 'weekly',
  interval_n      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  nth             TINYINT NULL,              -- 1–4, or -1 = last (monthly_nth only)
  until_date      DATE NULL,
  default_user_id INT UNSIGNED NULL,         -- "always X on Wednesdays"
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_group (group_code, start_date),
  CONSTRAINT fk_ms_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_ms_user  FOREIGN KEY (default_user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only dates that differ from their series get a row here.
CREATE TABLE grp_meeting_occ (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code   CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  series_id    INT UNSIGNED NOT NULL,
  occ_date     DATE NOT NULL,
  cancelled    TINYINT(1) NOT NULL DEFAULT 0,
  start_time   TIME NULL,                    -- NULL = as the series
  end_time     TIME NULL,
  is_open      TINYINT(1) NULL,
  note         VARCHAR(500) NULL,
  resp_mode    ENUM('default','user','none') NOT NULL DEFAULT 'default',
  resp_user_id INT UNSIGNED NULL,
  unavailable  TINYINT(1) NOT NULL DEFAULT 0, -- responsible person can't come
  updated_at   DATETIME NOT NULL,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_series_date (series_id, occ_date),
  KEY ix_group_date (group_code, occ_date),
  CONSTRAINT fk_mo_group  FOREIGN KEY (group_code)   REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_mo_series FOREIGN KEY (series_id)    REFERENCES grp_meeting_series (id) ON DELETE CASCADE,
  CONSTRAINT fk_mo_user   FOREIGN KEY (resp_user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- step3-formal.sql ----
-- Remembers that a group's default meeting types were created once.
CREATE TABLE grp_formal_setup (
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (group_code),
  CONSTRAINT fk_fs_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grp_formal_types (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name       VARCHAR(80) NOT NULL,
  position   INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_group (group_code),
  CONSTRAINT fk_ft_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Template: fixed headings + pre-filled text. kind 'other' = where suggestions go.
CREATE TABLE grp_formal_template_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code   CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type_id      INT UNSIGNED NOT NULL,
  position     INT NOT NULL DEFAULT 0,
  heading      VARCHAR(150) NOT NULL,
  default_text TEXT NULL,
  is_decision  TINYINT(1) NOT NULL DEFAULT 0,
  kind         ENUM('normal','other') NOT NULL DEFAULT 'normal',
  PRIMARY KEY (id),
  KEY ix_type (type_id, position),
  CONSTRAINT fk_fti_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_fti_type  FOREIGN KEY (type_id) REFERENCES grp_formal_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A planned / started / saved meeting. writer_user_id = who is writing the minutes now.
CREATE TABLE grp_formal_meetings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code     CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type_id        INT UNSIGNED NOT NULL,
  meeting_date   DATE NOT NULL,
  start_time     TIME NULL,
  place          VARCHAR(200) NULL,
  status         ENUM('planned','started','saved') NOT NULL DEFAULT 'planned',
  attendees      SMALLINT UNSIGNED NULL,     -- how many, never who
  writer_user_id INT UNSIGNED NULL,
  started_at     DATETIME NULL,
  saved_at       DATETIME NULL,
  saved_by       INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL,
  created_by     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_group_date (group_code, meeting_date),
  KEY ix_type_status (type_id, status, meeting_date),
  CONSTRAINT fk_fm_group  FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_fm_type   FOREIGN KEY (type_id) REFERENCES grp_formal_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_writer FOREIGN KEY (writer_user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agenda items = minutes items. Two levels (parent_id).
CREATE TABLE grp_formal_items (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code         CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  meeting_id         INT UNSIGNED NOT NULL,
  parent_id          INT UNSIGNED NULL,
  position           INT NOT NULL DEFAULT 0,
  heading            VARCHAR(150) NOT NULL,
  agenda_text        TEXT NULL,
  is_decision        TINYINT(1) NOT NULL DEFAULT 0,
  kind               ENUM('normal','other','tabled','suggestion','carried') NOT NULL DEFAULT 'normal',
  carried_decision   VARCHAR(20) CHARACTER SET ascii NULL,
  minutes_text       MEDIUMTEXT NULL,
  include_agenda     TINYINT(1) NOT NULL DEFAULT 1,
  decision           ENUM('no_action','tabled_decision','tabled_discussion','decided') NULL,
  carried_to_item_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_meeting (meeting_id, parent_id, position),
  KEY ix_tabled (group_code, decision, carried_to_item_id),
  CONSTRAINT fk_fi_group   FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_fi_meeting FOREIGN KEY (meeting_id) REFERENCES grp_formal_meetings (id) ON DELETE CASCADE,
  CONSTRAINT fk_fi_parent  FOREIGN KEY (parent_id) REFERENCES grp_formal_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_fi_carried FOREIGN KEY (carried_to_item_id) REFERENCES grp_formal_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anonymous suggestion box: no user id, date only.
CREATE TABLE grp_suggestions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type_id    INT UNSIGNED NOT NULL,
  text       TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  item_id    INT UNSIGNED NULL,
  used_at    DATETIME NULL,
  removed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_pending (group_code, type_id, item_id),
  CONSTRAINT fk_sg_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_sg_type  FOREIGN KEY (type_id) REFERENCES grp_formal_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_sg_item  FOREIGN KEY (item_id) REFERENCES grp_formal_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Addenda/comments after the minutes are locked.
CREATE TABLE grp_formal_addenda (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  meeting_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  text       TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_meeting (meeting_id),
  CONSTRAINT fk_fa_group   FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_fa_meeting FOREIGN KEY (meeting_id) REFERENCES grp_formal_meetings (id) ON DELETE CASCADE,
  CONSTRAINT fk_fa_user    FOREIGN KEY (user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- step4-economy.sql ----
-- Automatic scheduling of formal meetings (one rule per meeting type).
CREATE TABLE grp_formal_schedules (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code      CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  type_id         INT UNSIGNED NOT NULL,
  start_date      DATE NOT NULL,
  start_time      TIME NULL,
  place           VARCHAR(200) NULL,
  recur           ENUM('daily','weekly','monthly_date','monthly_nth') NOT NULL DEFAULT 'monthly_nth',
  interval_n      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  nth             TINYINT NULL,
  until_date      DATE NULL,
  generated_until DATE NULL,                 -- meetings are created up to this date
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_type (type_id),
  CONSTRAINT fk_fsc_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_fsc_type  FOREIGN KEY (type_id) REFERENCES grp_formal_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remembers that a group's default accounts were created once.
CREATE TABLE grp_economy_setup (
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (group_code),
  CONSTRAINT fk_es_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grp_accounts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name       VARCHAR(60) NOT NULL,
  position   INT NOT NULL DEFAULT 0,
  close_date DATE NULL,                      -- next closing date, set by the treasurer
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_group (group_code),
  CONSTRAINT fk_acc_group FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Financial periods. Exactly one open period (closed_at IS NULL) per account.
CREATE TABLE grp_periods (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code  CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  account_id  INT UNSIGNED NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NULL,
  opening_ore BIGINT NOT NULL DEFAULT 0,
  closing_ore BIGINT NULL,
  opened_at   DATETIME NOT NULL,
  closed_at   DATETIME NULL,
  closed_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_account (account_id, closed_at),
  CONSTRAINT fk_per_group   FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_per_account FOREIGN KEY (account_id) REFERENCES grp_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions: never edited or deleted. Amounts in öre, always positive.
CREATE TABLE grp_transactions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code    CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  period_id     INT UNSIGNED NOT NULL,
  ver_no        INT UNSIGNED NOT NULL,       -- voucher number within the period
  tx_date       DATE NOT NULL,
  type          ENUM('deposit','withdrawal') NOT NULL,
  amount_ore    BIGINT UNSIGNED NOT NULL,
  comment       VARCHAR(300) NOT NULL,
  correction_of INT UNSIGNED NULL,           -- this entry reverses another
  transfer_id   INT UNSIGNED NULL,           -- the two legs of a transfer share this
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,           -- automatic date and time stamp
  PRIMARY KEY (id),
  UNIQUE KEY uq_period_ver (period_id, ver_no),
  KEY ix_account (account_id, period_id),
  KEY ix_transfer (transfer_id),
  CONSTRAINT fk_tx_group   FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES grp_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_period  FOREIGN KEY (period_id) REFERENCES grp_periods (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- step5-keys.sql ----
CREATE TABLE grp_keys (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code     CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label          VARCHAR(40) NOT NULL,          -- the unique ID/marking, e.g. N1
  kind           ENUM('key','tag') NOT NULL DEFAULT 'key',
  description    VARCHAR(200) NULL,
  status         ENUM('available','lent','lost','retired') NOT NULL DEFAULT 'available',
  holder_user_id INT UNSIGNED NULL,
  lent_at        DATETIME NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_group (group_code, status),
  KEY ix_holder (holder_user_id),
  CONSTRAINT fk_key_group  FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_key_holder FOREIGN KEY (holder_user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full history per key (who had it, when, who registered the change).
CREATE TABLE grp_key_log (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_code     CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  key_id         INT UNSIGNED NOT NULL,
  action         ENUM('registered','lent','returned','lost','found','changed','retired') NOT NULL,
  holder_user_id INT UNSIGNED NULL,
  by_user_id     INT UNSIGNED NULL,
  note           VARCHAR(200) NULL,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_key (key_id),
  CONSTRAINT fk_kl_group  FOREIGN KEY (group_code) REFERENCES grp_groups (code) ON DELETE CASCADE,
  CONSTRAINT fk_kl_key    FOREIGN KEY (key_id) REFERENCES grp_keys (id) ON DELETE CASCADE,
  CONSTRAINT fk_kl_holder FOREIGN KEY (holder_user_id) REFERENCES grp_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_kl_by     FOREIGN KEY (by_user_id) REFERENCES grp_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- step6-language.sql ----
ALTER TABLE grp_users ADD COLUMN lang CHAR(2) CHARACTER SET ascii NULL AFTER display_name;

-- ---- step6b-info.sql ----
ALTER TABLE grp_groups
  ADD COLUMN district   VARCHAR(200) NULL AFTER landlord_contact,
  ADD COLUMN other_info TEXT NULL AFTER district;

-- ---- step7-contact.sql ----
ALTER TABLE grp_users
  ADD COLUMN full_name VARCHAR(100) NULL AFTER lang,
  ADD COLUMN phone     VARCHAR(40)  NULL AFTER full_name,
  ADD COLUMN email     VARCHAR(150) NULL AFTER phone;

ALTER TABLE grp_groups
  ADD COLUMN contact_fields TINYINT(1) NOT NULL DEFAULT 0 AFTER other_info;
