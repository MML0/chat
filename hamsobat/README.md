# هم‌صحبت (Hamsobat): anonymous random chat for Bale

A Bale bot in plain PHP + MySQL. Everything happens inside Bale: messages, the reply keyboard (the buttons that replace the phone keyboard) and glass (inline) buttons. There is no website and no Mini App, and users never type commands except Bale's own `/start`.

**Features**
- **Profile:** registration one question at a time; Jalali birth date (only the age is shown).
- **Matching:** gender preferences in both directions, and no matching with people you blocked.
- **Anonymous relay:** text, photo, voice, audio, sticker, video and GIF.
- **In chat:** next, end, profile view (with an optional "someone viewed your profile" notice), block and report.
- **Access:** a required channel (checked on the server) and a required first referral.
- **Quota:** daily free chats plus credits earned from referrals.
- **Anonymous inbox:** a personal link (`?start=msg…`) that opens anonymous conversations with replies.
- **Admin:** commands inside Bale, plus a cleanup that runs without cron.

---

## 1. Files

```
hamsobat/
  webhook.php        Bale posts updates here (secret ?k=…)
  migrate.php        creates the tables from database.sql (safe to re-run)
  check.php          self-test + setwebhook (admin password)
  config-example.php copy to config.php (never commit config.php)
  database.sql       the schema (also importable with phpMyAdmin)
  database.php       PDO wrapper: utf8mb4, {table} prefix, transactions with deadlock retry
  bootstrap.php      loads everything (no Composer)
  handlers/          start, profile, matching, chat, referral, inbox, settings
  services/          BaleApi, MatchingService, QuotaService, ReferralService, MessageRelay,
                     Requirements, Moderation, Settings, Ui (all labels), Text (validation, Jalali) …
  admin/commands.php admin commands
  cron/              cleanup.php (every minute, optional), daily_tasks.php (daily, optional)
  storage/logs/      JSON log files (web access denied)
  tests/             run.php, concurrency.php, e2e/ (not uploaded to the host)
```

## 2. Install (shared host, no SSH)

1. **Database:** create a MySQL/MariaDB database and user in the host panel, with charset `utf8mb4`.
2. **Upload:** upload `hamsobat.zip` and extract it, e.g. into `public_html/hamsobat/`. The host needs PHP 8.2+ with the `curl`, `pdo_mysql` and `mbstring` extensions.
3. **Config:** copy `config-example.php` to `config.php` and fill in:
   - **database:** host, name, user, password
   - **bale.bot_token**, and **bale.bot_username** without `@`
   - **bale.webhook_secret**, **admin.password**, **cron_secret:** long random strings, all different
   - **admin.ids:** your Bale user id. Not known yet? Leave it, finish the steps, send `/start` to the bot, then open `check.php`. It lists the newest users with their Bale ids.
4. **Tables:** open `https://YOUR.SITE/hamsobat/migrate.php?pass=ADMIN_PASSWORD`. You should see ✔ for 20 tables.
5. **Self-test:** open `https://YOUR.SITE/hamsobat/check.php?pass=ADMIN_PASSWORD`. Every line should be ✔.
6. **Webhook:** open `https://YOUR.SITE/hamsobat/check.php?pass=ADMIN_PASSWORD&setwebhook=1`. The bot now runs from this folder.
7. **Required channel:**
   - Make the bot an **admin** of the channel; without that, membership can't be checked.
   - In Bale, send the bot: `/setchannel @your_channel https://ble.ir/your_channel`. It tests the check right away.
   - To turn the requirement off: `/setchannel off`.
8. **Optional cron:** call `https://YOUR.SITE/hamsobat/cron/cleanup.php?k=CRON_SECRET` every minute, from the panel's cron or a free external cron service. Without it, the same work runs inside the webhook while people use the bot. The only difference: a lone searcher gets "nobody found" a bit late.

HTTPS is required by Bale for webhooks.

## 3. Configuration

`config.php` holds the secrets and the starting values. Everything else is a **setting**: the admin changes it from inside Bale with `/set name value`, and the saved value wins over `config.php`. Send `/settings` to see them all with explanations. The most important ones:

| setting | default | meaning |
|---|---|---|
| `daily_free_chats` | 5 | free *matched* chats per day (searching costs nothing) |
| `required_referrals` | 1 | qualified referrals needed before the first chat (0 = off) |
| `referral_reward_credits` | 3 | extra chats per qualified referral, used after the daily free ones |
| `referral_requires_channel` | 1 | a referral counts only when the friend also joined the channel |
| `referral_daily_reward_cap` | 20 | rewarded referrals per inviter per day |
| `required_channel` / `channel_link` / `require_channel` | – | the channel gate (`/setchannel`) |
| `search_timeout_seconds` | 30 | how long one search waits |
| `rematch_cooldown_minutes` | 10 | the same two people are not matched again for this long |
| `chat_idle_minutes` | 30 | a chat without messages ends by itself (0 = never) |
| `min_age` / `max_age` | 18 / 80 | registration age limits. Under-18s and adults are **never** matched, even if you lower `min_age` |
| `block_links` | 1 | blocks links, @usernames and phone numbers in chats, bios and the inbox |
| `allowed_types` | text,photo,voice,audio,sticker,video,animation | what can be sent in a chat (documents are off) |
| `message_retention_days` | 7 | chat messages are kept this long for report review, longer only while a report is open |
| `msg_rate_per_minute`, `inbox_rate_per_hour`, `report_rate_per_day` | 30, 10, 10 | anti-spam limits |
| `auto_suspend_reports` | 3 | number of different reporters within 24 h that triggers a 24 h suspension |
| `feature_inbox`, `feature_media`, `feature_profile_view_notify`, `maintenance` | on, on, on, off | feature switches |

Examples: `/set daily_free_chats 7`, `/feature inbox off`, `/maintenance on`.

## 4. Admin commands (only for `admin.ids`)

- **Overview:**
  - `/admin`: help and stats
  - `/stats`
  - `/settings`, `/set`, `/feature`, `/maintenance`
  - `/setchannel`
- **Reports:**
  - `/reports`: open reports
  - `/report <n>`: the reason, the last messages of that chat (🔴 reported, 🔵 reporter), and buttons: suspend 24 h, suspend permanently, done, dismiss
- **Users:**
  - `/user <id|nickname>`: shows the Bale id, for admins only
  - `/suspend <user> [hours]`, `/unsuspend <user>`
  - `/credit <user> <n>`
  - `/unlock <user>`: lets them chat without a referral, useful for the first users
- **Maintenance:** `/errors` (recent errors, secrets redacted) and `/cleanup`.

Admins skip the channel and referral requirements, so the owner can test right away.

## 5. How it works

- **Main menu (reply keyboard):** `🔎 پیدا کردن هم‌صحبت` / `👤 پروفایل من` · `📥 پیام‌های ناشناس` / `👥 دعوت دوستان` · `⚙️ تنظیمات`.
  - **Searching:** the keyboard switches to `❌ لغو جست‌وجو`.
  - **Chatting:** `➡️ نفر بعدی` · `🏁 پایان چت` / `👤 دیدن پروفایل` · `🚫 مسدود کردن` · `🚩 گزارش`.
  - **Label matching:** labels are matched ignoring emoji variation selectors and half-spaces (`services/Ui.php`).
- **Matching (`services/MatchingService.php`):**
  - Every queue or chat change runs in one transaction that first locks the `matching` row of `{locks}` (`SELECT … FOR UPDATE`). Two webhooks can't take the same waiting user, and two people who search at the same moment are matched instead of both waiting.
  - `{active_chats}` has `user_id` as PRIMARY KEY, so "one chat per user" is also a database rule.
  - The chat row, the removal from the queue and the quota spend for **both** users commit together. A chat is counted only when two people are actually matched.
- **Relay (`services/MessageRelay.php`):**
  - The bot re-sends each message itself (`sendMessage` / `sendPhoto` … with Bale's `file_id`), so the receiver sees no name or id.
  - `copyMessage` (also anonymous) is only a fallback. `forwardMessage` is never used between users.
  - If the partner blocked the bot, the chat ends. If that happens right at matching time, both get their chat credit back.
- **Referrals (`services/ReferralService.php`):**
  - Only a new account counts: one created through the link, or created less than 60 minutes ago without a finished profile.
  - One inviter per account; self-referral is impossible.
  - A referral qualifies after the friend finishes the profile (and joins the channel, if that setting is on).
  - The reward is a ledger row keyed on the referral. It can never be given twice, even if Bale re-sends the update.
- **Anonymous inbox:**
  - Opening an `msg` link never counts as a referral.
  - Each visitor ↔ owner pair is a separate anonymous session.
  - If the owner is in a random chat, only a short notice arrives; the content waits in the inbox, so it can't be mistaken for the chat partner.
  - A blocked sender is not told. Their messages are stored as blocked and never delivered.
- **Privacy:**
  - **Never shown to other users:** Bale ids, real names (the display name is chosen by the user), exact birth dates.
  - **Optional:** city, bio and photo can each be hidden in Settings.
  - **Retention:** chat messages are kept for `message_retention_days` for report review, then deleted. The guide tells users this.
- **Idempotency:** each update's `update_id` + body hash is stored; a re-delivered update is ignored.
- **Search timeouts and idle chats:** `webhook.php` runs a throttled cleanup (at most every 10 s) on every update, and `cron/cleanup.php` does the same on demand.

## 6. Bale API: what was verified

docs.bale.ai could not be reached from the build machine. The methods were checked against Bale SDKs that implement docs.bale.ai, and against the live GPT_BOTble bot.

| feature | status |
|---|---|
| `sendMessage`, inline buttons + `answerCallbackQuery`, deep link `https://ble.ir/<bot>?start=<payload>` | ✔ used in the live bot |
| `getChatMember`, `copyMessage`, `editMessageText`, `editMessageReplyMarkup`, `sendPhoto/Voice/Audio/Video/Animation/Document/Sticker` | listed by the SDKs that implement docs.bale.ai |
| reply keyboard (`keyboard`, `resize_keyboard`, `remove_keyboard`), inline `url` buttons | shown in the SDK examples |
| `getChatMember` status names (`member`, `administrator`, `creator`, `left`, `kicked`) | Telegram names, assumed |
| `my_chat_member` update when a user blocks the bot | handled if Bale sends it; otherwise the next failed send ends the chat |
| colored buttons | not used |

The code always sends form fields first (like the old working bot), then JSON as a fallback.

**First test on the real bot (10 minutes, two Bale accounts):**
1. `/start` → welcome + the `📝 تکمیل پروفایل` keyboard appears.
2. Register both accounts. Use `/unlock` for the second one, or invite it with the first account's link.
3. Both press `🔎 پیدا کردن هم‌صحبت` → both get "هم‌صحبت پیدا شد" and the chat keyboard.
4. Send text, a photo and a voice message: they arrive **without** "forwarded from".
5. `/setchannel …` and check that a non-member sees the join button, and a member passes.
6. Open your `📥 پیام‌های ناشناس` link from the other account and send something.

If any step fails, send `/errors` to the bot. It shows Bale's exact answer.

## 7. Deployment checklist

- [ ] PHP 8.2+, `curl`, `pdo_mysql`, `mbstring` (check.php)
- [ ] Database created with `utf8mb4`
- [ ] `config.php` filled; secrets long, random and different; `admin.ids` set
- [ ] `migrate.php` → 20 tables ✔
- [ ] `check.php` → everything ✔ (token, username, tables, log folder)
- [ ] `setwebhook=1` → ✔ (HTTPS)
- [ ] Bot is an admin of the required channel; `/setchannel` reports ✔
- [ ] The first-test list above, on the real bot
- [ ] Optional: cron for `cron/cleanup.php` (every minute) and `cron/daily_tasks.php` (daily)
- [ ] No old helper files on the host (file editors, upload forms)
- [ ] `tests/` is not uploaded (the zip doesn't contain it)

**Upgrades and rollback:**
- A new version that changes tables uses a new `table_prefix` (`hs2_`) in a new folder, then `migrate.php` and `setwebhook=1` there.
- Rollback = `setwebhook=1` from the old folder.
- `migrate.php` never drops or alters anything.

## 8. Tests

They need a MySQL/MariaDB database whose name contains `test` (and `e2e` for the end-to-end run). The defaults are `127.0.0.1:3307`, user `hs`/`hs`; override them with `HS_TEST_DB_*` environment variables.

```sh
php tests/run.php              # 204 checks: validation, registration, referrals + abuse, channel gate,
                               # quota boundaries, matching rules, block, relay, reports, inbox, admin
php tests/concurrency.php 40   # 50 parallel processes searching at once: invariants hold
php tests/e2e/run_e2e.php      # real webhook.php / migrate.php / check.php / cron over HTTP + fake Bale
```
