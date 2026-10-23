# Anonymous random chat for Bale (چت ناشناس): design

Goal: two strangers are matched at random and talk through the bot. Neither sees the other's name, username or id. Free to use, with premium extras sold through the Bale wallet (`starter/src/Payments.php`).

The runtime is a webhook only (no daemon or cron), so everything happens when a user sends something.

## 1. User flow
```
/start → rules + "قبول دارم" → gender + age (+ city, optional) → main keyboard
🔍 شروع چت ناشناس → searching ("⏳ در حال پیدا کردن هم‌صحبت…")
   matched → both get "✅ به یک هم‌صحبت ناشناس وصل شدید! سلام کنید 👋"
   messages → copied to the partner (text, photo, voice, sticker, video, file)
⏭ بعدی → ends the chat and searches again    ⛔ پایان → ends the chat
🚩 گزارش → ends + reports + blocks the pair  🚫 بلاک → ends + never match again
```

**Main reply keyboard:**
- Idle: `🔍 شروع چت ناشناس`, `👤 پروفایل من`, `💎 اشتراک ویژه`, `🎁 دعوت دوستان`, `📜 قوانین`
- Chatting: `⏭ بعدی`, `⛔ پایان`, `🚩 گزارش`

Keep the labels in one constant (see `04-buttons-and-ux.md`).

## 2. States (stored on the user row)
`idle` → `searching` → `chatting` → `idle`. Also `onboarding` until the rules are accepted and the profile is set.

`users.partner_id` and `users.session_id` are copied onto the row so relaying a message is one lookup.

## 3. Matching (webhook-safe)
Run inside a MySQL named lock, so two webhooks can't grab the same waiting person:
```sql
SELECT GET_LOCK('rc_match', 3);   -- works on shared-host MySQL/MariaDB
-- 1. drop queue entries older than 10 min (lazy cleanup)
-- 2. find the oldest waiting user W with:
--      W != me, no block between us (either direction), W wasn't my last partner,
--      my filters fit W and W's filters fit me (premium only), both not banned
--    ORDER BY W.is_premium DESC, W.created_at ASC   (premium = priority)
-- 3a. found: delete W from queue, INSERT session, set both users chatting + partner/session, COMMIT
-- 3b. none: INSERT me into queue (state searching)
SELECT RELEASE_LOCK('rc_match');
```
- **After the lock is released,** notify both users (never call Bale while holding the lock).
- **Timeouts:** someone still searching after 10 minutes gets "کسی پیدا نشد، دوباره امتحان کنید 🔍" the next time they send anything. Without cron there's no push; optionally run a cron or a "ping" when one exists.

## 4. Relaying messages
- **Anonymous copy:** use `copyMessage(partner, my_chat, message_id)`, which carries **no "forwarded from"**. **Never `forwardMessage` between users**: it shows who sent it.
  - ⚠️ Verify `copyMessage` on Bale first. If it fails, re-send by `file_id` (`sendPhoto`, `sendVoice`, …) with the caption.
- **Not allowed:** contacts (`contact`), locations, and (configurable) links, `@usernames` or phone numbers in free users' text. Explain politely: `⚠️ ارسال لینک/آیدی در چت ناشناس مجاز نیست.`
- **If the copy fails** (the partner blocked the bot), end the session and tell the sender `طرف مقابل از ربات خارج شد.`
- **Stats:** count messages per session, but don't store the content.
- **Evidence for reports, without storing text:** keep `(chat_id, message_id)` of the last ~20 messages per session for 48 h. The admin can then review a report with `forwardMessage` to the admin chat, and it is purged afterwards.

## 5. Safety and limits
| rule | default |
|---|---|
| Accept the rules before the first chat | required |
| Age gate | Ask the age; keep minors only with minors, or refuse under 13/16; decide with the owner |
| Message rate | 1 per second, 30 per minute per user. Over that: "⏳ کمی آهسته‌تر 🙂" |
| New chats per day (free) | 20 (premium: unlimited) |
| Inactivity | A session with no messages for 15 min ends lazily |
| Report | Ends the chat, blocks the pair, sends the report to the admin |
| Auto-ban | 3 different reporters within 24 h → banned for 24 h + admin alert |
| Admin commands | `/ban <id> [hours]`, `/unban <id>`, `/reports`, `/stats`, `/broadcast` (needs confirmation) |

## 6. Premium (💎 اشتراک ویژه)
Ideas, ordered by value. Each is checked on the server for every use.
1. **Gender filter:** "only girls/boys". Show an estimate of how many are waiting, so people aren't surprised by long waits.
2. **Priority matching:** premium users are matched first.
3. **Unlimited** new chats per day.
4. **See the partner's profile:** gender, age range and city; never the name or id.
5. **Age-range filter.**
6. **Send photo/video/voice:** free users could be limited to text and stickers. Decide with the owner.
7. **Mutual ID exchange (📇):** both press within the chat, and only then each receives the other's @username.

**Packages:** `config.php → payment.packages` (rial).

**Referral:** the inviter gets **free** premium time (`Premium::REFERRAL`), for example 1 day. Decide whether referral time unlocks everything or not.

## 7. Tables (add to `migrate.php`, prefix `rc_`)
```sql
-- users: add these columns to the starter's rc_users
gender ENUM('m','f') NULL, age TINYINT NULL, city VARCHAR(64) NULL,
state ENUM('onboarding','idle','searching','chatting') NOT NULL DEFAULT 'onboarding',
partner_id BIGINT NULL, session_id BIGINT NULL, last_partner_id BIGINT NULL,
rules_accepted_at DATETIME NULL, banned_until DATETIME NULL,
chats_today INT NOT NULL DEFAULT 0, chats_day DATE NULL

CREATE TABLE rc_queue (
  user_id BIGINT PRIMARY KEY, gender ENUM('m','f') NULL, age TINYINT NULL,
  want_gender ENUM('m','f') NULL, want_age_min TINYINT NULL, want_age_max TINYINT NULL,
  is_premium TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
  INDEX (is_premium, created_at)
);
CREATE TABLE rc_sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_a BIGINT NOT NULL, user_b BIGINT NOT NULL,
  started_at DATETIME NOT NULL, last_message_at DATETIME NOT NULL, ended_at DATETIME NULL,
  ended_by BIGINT NULL, end_reason VARCHAR(16) NULL, messages INT NOT NULL DEFAULT 0,
  INDEX (user_a), INDEX (user_b)
);
CREATE TABLE rc_session_refs (            -- evidence for reports, purged after 48 h
  session_id BIGINT NOT NULL, from_user BIGINT NOT NULL, chat_id BIGINT NOT NULL,
  message_id BIGINT NOT NULL, created_at DATETIME NOT NULL, INDEX (session_id), INDEX (created_at)
);
CREATE TABLE rc_reports (
  id INT AUTO_INCREMENT PRIMARY KEY, session_id BIGINT NULL, reporter BIGINT NOT NULL,
  reported BIGINT NOT NULL, reason VARCHAR(32) NULL, created_at DATETIME NOT NULL,
  handled TINYINT(1) NOT NULL DEFAULT 0, INDEX (reported, created_at)
);
CREATE TABLE rc_blocks (
  blocker BIGINT NOT NULL, blocked BIGINT NOT NULL, created_at DATETIME NOT NULL,
  PRIMARY KEY (blocker, blocked)
);
CREATE TABLE rc_rate (                    -- per-user message rate window
  user_id BIGINT PRIMARY KEY, window_start INT NOT NULL, count INT NOT NULL
);
```

## 8. Build order (milestones)
1. **MVP:**
   - onboarding (rules, gender, age)
   - main keyboard
   - search/match with the lock, relay with `copyMessage`
   - next/stop, blocked-bot handling, rate limit
   - report, block, admin `/ban`
2. **Premium:** reuse `Payments` and `Premium`; gender filter, priority, unlimited chats, profile view, mutual ID exchange.
3. **Operations:** `/stats` (waiting, chatting, chats today, revenue), report review, broadcast, auto-ban.

## 9. Test plan
- **Unit tests** (SQLite, like `starter/tests/run.php`):
  - filters both ways, blocks, last-partner rule, priority
  - rate limit, state changes
  - Lock: SQLite runs one test at a time, so test the lock call through a small interface.
- **E2E** (`docs/08-testing.md`): two fake users, A searches → waits; B searches → both matched. A text gets copied to B; A "next" tells B; report → block → never matched again.
- **On the real Bale before launch** (⚠️ list from `01-bale-api.md`):
  - `copyMessage`
  - the reply keyboard
  - re-sending by `file_id`
  - the error a send returns when a user blocked the bot

## 10. Open questions for the owner
- Minimum age, and whether minors and adults are kept apart.
- Which media free users may send.
- Exact premium features, prices (rial) and package lengths.
- Whether referral premium unlocks everything or only some features.
- Bot username, rules text, support account.
