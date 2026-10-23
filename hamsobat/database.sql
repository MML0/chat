-- ============================================================================
-- Hamsobat (هم‌صحبت) database schema. MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4.
--
-- Either import this file with phpMyAdmin, or open migrate.php (it runs this file
-- and is safe to run again: CREATE TABLE IF NOT EXISTS only, nothing is ever dropped).
--
-- Every name starts with hs_. migrate.php replaces hs_ with config.php → database →
-- table_prefix, so a new version can live next to the old tables (hs2_ …) and a
-- rollback is only pointing the webhook back to the old folder.
--
-- All DATETIME values are UTC. users.id is the internal id. bale_user_id is never
-- shown to other users.
-- ============================================================================

CREATE TABLE IF NOT EXISTS hs_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bale_user_id BIGINT NOT NULL,
  display_name VARCHAR(64) NULL,
  nickname VARCHAR(32) NULL,
  birth_date DATE NULL,
  gender ENUM('m','f') NULL,
  preferred_gender ENUM('m','f','any') NULL,
  city VARCHAR(64) NULL,
  bio VARCHAR(400) NULL,
  photo_file_id VARCHAR(255) NULL,
  referral_code VARCHAR(16) NOT NULL,
  inbox_code VARCHAR(16) NOT NULL,
  referred_by_user_id INT UNSIGNED NULL,
  referral_waived TINYINT(1) NOT NULL DEFAULT 0,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  notify_profile_views TINYINT(1) NOT NULL DEFAULT 1,
  show_city TINYINT(1) NOT NULL DEFAULT 1,
  show_bio TINYINT(1) NOT NULL DEFAULT 1,
  show_photo TINYINT(1) NOT NULL DEFAULT 1,
  state VARCHAR(32) NOT NULL DEFAULT '',
  state_data VARCHAR(1000) NULL,
  channel_verified_at DATETIME NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  suspended_until DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_users_bale (bale_user_id),
  UNIQUE KEY hs_uq_users_nickname (nickname),
  UNIQUE KEY hs_uq_users_referral (referral_code),
  UNIQUE KEY hs_uq_users_inbox (inbox_code),
  KEY hs_ix_users_referred (referred_by_user_id),
  CONSTRAINT hs_fk_users_referred FOREIGN KEY (referred_by_user_id) REFERENCES hs_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hs_user_interests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  interest VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_interests (user_id, interest),
  CONSTRAINT hs_fk_interests_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per invited account (an account can have only one inviter).
CREATE TABLE IF NOT EXISTS hs_referrals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inviter_user_id INT UNSIGNED NOT NULL,
  invited_user_id INT UNSIGNED NOT NULL,
  status ENUM('pending','qualified') NOT NULL DEFAULT 'pending',
  qualified_at DATETIME NULL,
  reward_credits INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_referrals_invited (invited_user_id),
  KEY hs_ix_referrals_inviter (inviter_user_id, status, qualified_at),
  CONSTRAINT hs_fk_referrals_inviter FOREIGN KEY (inviter_user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_referrals_invited FOREIGN KEY (invited_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per user (UNIQUE user_id), so nobody can be in the queue twice.
-- status = waiting while searching. The row is reused for the next search.
CREATE TABLE IF NOT EXISTS hs_chat_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  preferred_gender ENUM('m','f','any') NOT NULL DEFAULT 'any',
  queued_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status ENUM('waiting','matched','cancelled','expired') NOT NULL DEFAULT 'waiting',
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_queue_user (user_id),
  KEY hs_ix_queue_wait (status, queued_at),
  KEY hs_ix_queue_expire (status, expires_at),
  CONSTRAINT hs_fk_queue_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every random chat. user_one = the one who waited longer. *_credit = what each spent
-- (free daily chat or a bonus credit) so it can be refunded if the match fails.
CREATE TABLE IF NOT EXISTS hs_chats (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_one_id INT UNSIGNED NOT NULL,
  user_two_id INT UNSIGNED NOT NULL,
  status ENUM('active','ended') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  ended_by_user_id INT UNSIGNED NULL,
  end_reason VARCHAR(16) NULL,
  usage_date DATE NOT NULL,
  user_one_credit ENUM('free','credit') NULL,
  user_two_credit ENUM('free','credit') NULL,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY hs_ix_chats_one (user_one_id, user_two_id, started_at),
  KEY hs_ix_chats_two (user_two_id, user_one_id, started_at),
  KEY hs_ix_chats_status (status, last_message_at),
  CONSTRAINT hs_fk_chats_one FOREIGN KEY (user_one_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_chats_two FOREIGN KEY (user_two_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who is chatting right now. The PRIMARY KEY on user_id makes it impossible (at the
-- database level) for one user to be in two active chats. Relaying a message = 1 lookup.
CREATE TABLE IF NOT EXISTS hs_active_chats (
  user_id INT UNSIGNED NOT NULL,
  chat_id INT UNSIGNED NOT NULL,
  partner_user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id),
  KEY hs_ix_active_chat (chat_id),
  CONSTRAINT hs_fk_active_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_active_chat FOREIGN KEY (chat_id) REFERENCES hs_chats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- usage_date is the day in the app's timezone, so there is nothing to reset at midnight.
-- successful_chats = all matched chats that day, bonus_credits_used = how many of them used a credit.
CREATE TABLE IF NOT EXISTS hs_daily_usage (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  usage_date DATE NOT NULL,
  successful_chats INT UNSIGNED NOT NULL DEFAULT 0,
  bonus_credits_used INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_usage_day (user_id, usage_date),
  CONSTRAINT hs_fk_usage_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hs_user_credits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  credit_balance INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_credits_user (user_id),
  CONSTRAINT hs_fk_credits_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every credit given or spent. UNIQUE (user, reason, ref) = a reward or a spend can never happen twice.
CREATE TABLE IF NOT EXISTS hs_credit_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(16) NOT NULL,
  ref_id VARCHAR(64) NOT NULL,
  balance_after INT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_ledger_ref (user_id, reason, ref_id),
  CONSTRAINT hs_fk_ledger_user FOREIGN KEY (user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Random-chat messages (for reports). No media binaries: only Bale file ids.
-- Deleted after settings → message_retention_days, except chats with an open report.
CREATE TABLE IF NOT EXISTS hs_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id INT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  message_type VARCHAR(16) NOT NULL,
  text_content TEXT NULL,
  bale_file_id VARCHAR(255) NULL,
  source_message_id BIGINT NULL,
  created_at DATETIME NOT NULL,
  delivery_status ENUM('sent','failed','filtered') NOT NULL,
  PRIMARY KEY (id),
  KEY hs_ix_messages_chat (chat_id, id),
  KEY hs_ix_messages_created (created_at),
  CONSTRAINT hs_fk_messages_chat FOREIGN KEY (chat_id) REFERENCES hs_chats (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_messages_sender FOREIGN KEY (sender_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anonymous inbox: one session per (link owner, visitor). Replies stay inside it and
-- never touch random chats.
CREATE TABLE IF NOT EXISTS hs_anonymous_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id INT UNSIGNED NOT NULL,
  visitor_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  last_message_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_anon_pair (owner_user_id, visitor_user_id),
  KEY hs_ix_anon_visitor (visitor_user_id),
  CONSTRAINT hs_fk_anon_owner FOREIGN KEY (owner_user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_anon_visitor FOREIGN KEY (visitor_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sender_user_id is internal only (abuse handling) and is never shown to the recipient.
CREATE TABLE IF NOT EXISTS hs_anonymous_inbox (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  recipient_user_id INT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  reply_to_id INT UNSIGNED NULL,
  message_type VARCHAR(16) NOT NULL,
  text_content TEXT NULL,
  bale_file_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  status ENUM('new','seen','blocked') NOT NULL DEFAULT 'new',
  PRIMARY KEY (id),
  KEY hs_ix_inbox_recipient (recipient_user_id, status, id),
  KEY hs_ix_inbox_sender (sender_user_id, created_at),
  CONSTRAINT hs_fk_inbox_session FOREIGN KEY (session_id) REFERENCES hs_anonymous_sessions (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_inbox_recipient FOREIGN KEY (recipient_user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_inbox_sender FOREIGN KEY (sender_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hs_profile_views (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_owner_id INT UNSIGNED NOT NULL,
  viewer_user_id INT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL,
  notification_sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY hs_ix_views_pair (profile_owner_id, viewer_user_id, notification_sent_at),
  KEY hs_ix_views_at (viewed_at),
  CONSTRAINT hs_fk_views_owner FOREIGN KEY (profile_owner_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_views_viewer FOREIGN KEY (viewer_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A block works in both directions for matching and stops inbox messages from the blocked user.
CREATE TABLE IF NOT EXISTS hs_blocked_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  blocker_user_id INT UNSIGNED NOT NULL,
  blocked_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY hs_uq_block_pair (blocker_user_id, blocked_user_id),
  KEY hs_ix_block_blocked (blocked_user_id),
  CONSTRAINT hs_fk_block_blocker FOREIGN KEY (blocker_user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_block_blocked FOREIGN KEY (blocked_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hs_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_user_id INT UNSIGNED NOT NULL,
  reported_user_id INT UNSIGNED NOT NULL,
  chat_id INT UNSIGNED NULL,
  inbox_message_id INT UNSIGNED NULL,
  reason VARCHAR(16) NOT NULL,
  details VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY hs_ix_reports_status (status, id),
  KEY hs_ix_reports_reported (reported_user_id, created_at),
  KEY hs_ix_reports_reporter (reporter_user_id, created_at),
  CONSTRAINT hs_fk_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES hs_users (id) ON DELETE CASCADE,
  CONSTRAINT hs_fk_reports_reported FOREIGN KEY (reported_user_id) REFERENCES hs_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Values the admin changes from inside Bale (/set). Defaults live in services/Settings.php.
-- Names starting with _ are internal timestamps (last cleanup, last alert …).
CREATE TABLE IF NOT EXISTS hs_settings (
  name VARCHAR(64) NOT NULL,
  value VARCHAR(1000) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Row locks used as a mutex: matching locks the 'matching' row (SELECT … FOR UPDATE)
-- so two webhooks can never pick the same waiting user or both go to the queue.
CREATE TABLE IF NOT EXISTS hs_locks (
  name VARCHAR(32) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hs_locks (name) VALUES ('matching');

CREATE TABLE IF NOT EXISTS hs_rate_limits (
  user_id INT UNSIGNED NOT NULL,
  bucket VARCHAR(16) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bale re-delivers updates when the webhook is slow. update_id + body hash, kept 2 days.
CREATE TABLE IF NOT EXISTS hs_updates_seen (
  update_key VARCHAR(80) NOT NULL,
  seen_at DATETIME NOT NULL,
  PRIMARY KEY (update_key),
  KEY hs_ix_seen_at (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Errors the admin can read with /errors (tokens are redacted before saving).
CREATE TABLE IF NOT EXISTS hs_error_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  level VARCHAR(8) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  context TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY hs_ix_errors_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
