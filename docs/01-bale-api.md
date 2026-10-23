# Bale Bot API: what we use and what we learned

Bale's Bot API is a Telegram Bot API clone. Most Telegram docs apply; differences are noted below.

> ✔ = used in the live GPT_BOTble bot. ⚠️ = Telegram-compatible but **not yet seen working on Bale**, so test it with the real bot (or the test wallet) before relying on it.

## Base URLs
| what | URL |
|---|---|
| API | `https://tapi.bale.ai/bot<TOKEN>/<method>` ✔ |
| file download | `https://tapi.bale.ai/file/bot<TOKEN>/<file_path>` (path from `getFile`) |
| deep link / referral | `https://ble.ir/<bot_username>?start=ref123` ✔ (arrives as `/start ref123`) |
| official docs (Persian) | https://docs.bale.ai/ |

Keep the base URL in config (`'api_base'`). That lets tests point it at a fake server.

## Request format
Both **form fields** and **JSON** bodies work for normal methods ✔. For **payments, use form fields**, as the working bot did; see `02-payments.md`.
- **Form:** arrays such as `prices` and `reply_markup` are sent as JSON *text*; booleans as `1`/`0`.
- **Default:** `starter/src/Bale.php` sends form first and retries once as JSON only if Bale answers `ok:false`. It never retries after a network error, so nothing is sent twice.
- **Answers** look like `{"ok":true,"result":…}` or `{"ok":false,"description":"…"}`. Always check `ok`, and send the admin the `description` when something important fails.

## Methods
| method | used for | |
|---|---|---|
| `getMe` | Check the token | ✔ |
| `setWebhook?url=…` / `getWebhookInfo` | Point the bot at a folder / see where it points | ✔ |
| `sendMessage` (`parse_mode: Markdown`) | Text | ✔ |
| `sendMessage` + `reply_markup.inline_keyboard` | Glass buttons | ✔ |
| `answerCallbackQuery` | Stop the button's loading spinner | ✔ |
| `sendChatAction` (`typing`) | "Typing…" | ✔ |
| `forwardMessage` | Forward with "forwarded from" | ✔ |
| `getFile` + file URL | Download voice/files | ✔ (voice-to-text) |
| `sendInvoice`, `answerPreCheckoutQuery`, `inquireTransaction` | Wallet payments | ✔ |
| `copyMessage` | Send a copy **without** "forwarded from" (needed for anonymity) | ⚠️ |
| `sendPhoto` / `sendVoice` / `sendVideo` / `sendDocument` / `sendSticker` with `file_id` | Re-send media by id (fallback if `copyMessage` doesn't work) | ⚠️ |
| `reply_markup.keyboard` (reply keyboard) | Persistent bottom buttons | ⚠️ |
| `editMessageText`, `deleteMessage` | Update or clean messages | ⚠️ |

## Updates (webhook body)
- **`message`:** has `text`, or media (`photo`, `voice`, `audio`, `video`, `document`, `sticker`, `animation`), or `successful_payment`.
- **`callback_query`:** `id`, `data`, `from`, `message.chat.id`.
- **`pre_checkout_query`:** `id`, `from`, `invoice_payload`, `total_amount`, `currency`. It must be answered within about 10 seconds.
- **`update_id`:** Bale **re-delivers** updates when the webhook is slow or errors. De-duplicate by `update_id` plus a hash of the body (`UpdateDeduper`).
- **Group messages** arrive too; the bots ignore them unless they are made for groups.
- **Voice objects** normally carry `file_id`, `file_unique_id`, `duration`, `mime_type` and `file_size`. The code tolerates a missing duration or MIME type.

## Gotchas
- Answer every `callback_query`, even with empty text, or the button keeps spinning.
- `callback_data` is short (Telegram limit 64 bytes). Use `action:arg`, such as `buy:month` or `report:123`.
- **Markdown:**
  - An unclosed `*` or `_` in user or AI text makes `sendMessage` fail.
  - Send user-generated text **without** `parse_mode`.
  - The old bot auto-detected Markdown, which also breaks on things like `snake_case`.
- Messages are limited to about 4096 characters. Split long texts into chunks of about 4000.
- A user who blocked the bot makes every send fail (`ok:false`). Treat that as "user left": end their chat and stop messaging them.
- Ids are big numbers. Store them as `BIGINT`, not `INT`.
- Prices are in **rial** (1 toman = 10 rial). `2,380,000` rial = 238,000 toman.
- **Channel membership (`getChatMember`):**
  - `chat_id` may be `@channelusername` or the numeric id, and **the bot must be an admin of the channel**.
  - Bale answers `Bad Request: no such group or user` in several cases: the bot isn't an admin, the @name is wrong (private channels have none), and possibly when the user simply hasn't joined.
  - To tell them apart, check the bot itself first (`getChatMember(channel, bot id)`). Hamsobat does this in `services/Requirements.php`.
  - The numeric id comes from `getChat`, from a forwarded channel post (`forward_from_chat.id`), or from the `my_chat_member` update when the bot is added.
- **No date picker for bots:** reply-keyboard buttons can only be text, `request_contact` or `request_location`; nothing returns a date. Build a picker from inline buttons (year → month → day, edited in place). Hamsobat does this in `hamsobat/handlers/profile.php`.
- **Fields Bale may reject as INVALID INPUT:** `is_persistent` (reply keyboard) and `link_preview_options`. Buttons with empty or blank text are refused too, so never pad a calendar grid with blank buttons. Seen by [another Bale bot project](https://github.com/ftmehoseini/RastaTTBot/pull/1).
- **Editing:** prefer `editMessageText` (with a new `reply_markup`) over `editMessageReplyMarkup`, which not every Telegram-compatible platform implements.
