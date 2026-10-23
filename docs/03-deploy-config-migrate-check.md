# Deploy, config, migrations, checks, rollback

## The owner's hosting
- Shared PHP hosting in Iran: no SSH, Composer or cron. Files go up as a **zip**, are extracted, and a URL is opened.
- PHP 8.2+ (the AI bot's `vendor/` needs 8.2). MySQL/MariaDB with phpMyAdmin.
- The host can reach Bale and Iranian APIs (MetisAI, GapGPT). **OpenRouter may be unreachable** from Iran. Code must degrade gracefully, not hang: short timeouts and a fallback.

## Deploying a new version (the safe way)
1. Build a zip from git (`git archive`) **without** `config.php`, `tests/` and `storage/`. Include `vendor/` if there is one.
2. Extract it into a **new folder**, such as `bot_v2/`, next to the old one, on the same database.
3. Copy `config.php` from the old folder and add any new keys from `config-example.php`.
4. Open `migrate.php?pass=…`. It creates the tables (`CREATE TABLE IF NOT EXISTS`) and copies old data if needed.
5. Open `check.php?pass=…`. Every line should show ✔.
6. Open `check.php?pass=…&setwebhook=1`. The bot now runs from the new folder.
7. **Rollback:** set the webhook back to the old folder's URL. Nothing else is needed, because the old tables were never touched.

## config.php
- Returns a PHP array and lives in `.gitignore`. `config-example.php` is the documented template.
- **Everything secret goes here:**
  - bot token
  - DB password
  - admin password
  - wallet token
  - API keys
  - webhook secret
- Give new keys defaults in code, so an old `config.php` keeps working. Where a key is required (the wallet token, for example), say so loudly: a user message, an admin alert, and ❌ in `check.php`.

## Migrations
- **Never** `DROP` anything in a migration you upload. The AI bot's original `migrate.php` dropped `users`/`messages`: one wrong click would have deleted everything.
- **Table prefix per version:** `rc_` → `rc2_`. A new version copies data with `INSERT INTO rc2_users SELECT … FROM rc_users` and only **reads** the old tables.
- **Copy only once:** only when the new table is still empty. Running `migrate.php` twice must be harmless.
- **Charset:**
  - `utf8mb4` everywhere, in both the connection (`charset=utf8mb4` in the DSN) and the tables.
  - Persian inserted through a latin1 client becomes `????` or mojibake.
- **Columns:** Bale ids are `BIGINT`. Index the columns you search by (user id, created_at, chat/session id).
- Protect `migrate.php` with the admin password. Compare with `hash_equals`.

## check.php (deployment self-test)
It spends nothing (no paid calls) and shows ✔/❌ for:
- PHP version and extensions (`curl`, `pdo_mysql`, `mbstring`, `json`)
- the DB connection and each table, with row counts
- `getMe` (token), `getWebhookInfo` (where the bot points), and the wallet token
- external services the bot uses: reachability only, never a paid request

`&setwebhook=1` points the webhook at this folder and appends `?k=<webhook_secret>`.

## Webhook security
- Put a random secret in the webhook URL (`webhook.php?k=…`) and reject requests without it (403). Otherwise anyone who learns the URL can post fake updates, including fake payments.
- Never echo or log the full webhook URL or the token.

## Files that must NEVER be on the host
`f.php` (a web file editor with no password) and `ftp.php` (an upload form behind `admin123`) were found on the old host. Each lets anyone take over the server. Delete such files. For deploying, use the hosting panel's file manager.
