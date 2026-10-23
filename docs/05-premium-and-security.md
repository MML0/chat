# Premium, admin powers and security

## Premium = rows, not a flag
`starter/src/Premium.php`: each grant is a row (`source`, `reference`, `starts_at`, `expires_at`, `status`).
- **Sources:**
  - `payment`: Bale wallet, confirmed by `inquireTransaction`
  - `admin`: manual, after you checked a card-to-card payment
  - `referral`: free
- **Paid-only features ask `isActive($id, true)`.** Referral or free time never unlocks them. This was the rule for voice-to-text in the AI bot.
- **New time stacks** after the current end.
- **A receipt counts once:** granting the same `reference` twice changes nothing.
- **Fail closed:** any DB error means no access to paid features.
- **Never trust the client:** usernames like "premium_vip", Telegram/Bale Premium badges, "I already paid" messages or starting the bot give nothing.

## Admin
- **Who counts as admin:** `config.php → admin.chat_id`, checked against `from.id` in a private chat.
- **Admin alerts:** payment problems, Bale errors and budget limits. Keep them short and **never include keys or tokens**; redact them.
- **Monitoring copies:** the AI bot copied every chat to a monitor account. For an **anonymous chat app, don't do this.** Keep at most what moderation needs (reports), and say so in the bot's rules.

## Secrets checklist
- **All secrets go in `config.php`** (git-ignored): bot token, DB password, admin password, wallet token, API keys.
- **Secrets found in code before, all to be rotated:**
  - a second bot token in `ir_side_transport.php`
  - the admin password in `send_to_all_users.py`
  - the wallet token in `buttons.php`
  - the GapGPT key in the voice snippet
- **Logs and admin messages never contain:** tokens, keys, `Authorization` headers, or the webhook URL with its secret.
- **Webhook:** `webhook.php?k=<secret>` plus a 403 for anything else.
- **Admin pages** (`migrate.php`, `check.php`, `get_users.php`, `send_msg.php`) need the admin password, compared with `hash_equals`.
- **No file editors or upload pages on the host** (`f.php`, `ftp.php`).

## Known weak spots in the AI bot (don't copy them)
- **Guessable code:** a "secret" PRO code with only 30 possible values (`pro` + (hour×3+4) % 30) gave anyone 7 days of PRO.
- **Unchecked model button:** `setmodel_<anything>` was written to the DB without an allow-list. Fixed in the new version.
- **Monitor copies:** every user message and AI reply went to a personal account.
