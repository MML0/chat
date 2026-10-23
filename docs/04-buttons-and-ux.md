# Buttons, commands and Persian UX

## Two kinds of buttons
| | inline (glass) buttons | reply keyboard |
|---|---|---|
| where | Under one message | Replaces the phone keyboard, stays visible |
| sends | `callback_query` with hidden `data` | A normal text message with the button's label |
| good for | Choices about that message: buy package, pick model, report, confirm | The app's main actions: 🔍 شروع / ⏭ بعدی / ⛔ پایان |
| Bale | ✔ used | ⚠️ test first |

`Bale::sendInlineButtons()` and `Bale::sendReplyKeyboard()` in `starter/src/Bale.php` build both kinds.

## Rules that avoid bugs
1. **Answer every callback** (`answerCallbackQuery`), even with empty text, or the button keeps loading.
2. **Validate `callback_data` against an allow-list.** The old bot wrote any `setmodel_<anything>` straight into the DB. Check that the value is one you offered.
3. **Callback data is short and structured:** `action:arg` (`buy:month`, `report:7`, `gender:f`). Keep it under 64 bytes.
4. **Check permissions on the server for every button,** not only when showing it. A PRO-only button must re-check PRO when pressed.
5. **Reply-keyboard labels arrive as text.** Match them exactly, emoji included, and keep the labels in **one constant** used by both the keyboard and the handler.
6. **Commands:**
   - Strip `@botusername` and lowercase before matching.
   - `/start ref123` carries the referral.
   - Admin commands check the **sender id** (`from.id`) in a **private chat**, never the chat id or anything the user types.
7. **Groups:** ignore them unless the bot is made for groups. The AI bot `die()`s on group messages.

## Persian texts (style of the existing bots)
- Short, friendly, with emojis: `👋 خوش آمدید!`, `✅ …`, `❌ …`, `⏳ …`, `🎉 …`, `🔒 …`.
- Errors never show internals. Example: `⚠️ GPT پاسخ نداد. لطفاً دوباره تلاش کنید.`, while the details go to the admin.
- A limit message explains how to unlock it: `…برای ارتقا /getpro (رایگان با معرفی به دیگران) یا /buypro (خرید)`.
- Show remaining premium in `/help`: `زمان باقی‌مانده پرو: ۳ روز و ۵ ساعت` (`Premium::remainingText()`).
- Prices are shown in **toman** (`۲۳۸ هزار تومان`) but sent in **rial**.

## Commands from the AI bot (reuse the names)
`/start`, `/help`, `/newchat`, `/setmodel`, `/getpro` (referral link and banner), `/buypro`.

Admin-only:
- `/grantpremium <id> <days> [receipt]`
- `/revokepremium <id>`
- `/premiumstatus [id]`
- `/routingstats`

The starter uses the shorter `/buy`, `/invite`, `/grant` and `/revoke`; rename them freely.

## Referral (from the AI bot)
- Link: `https://ble.ir/<bot>?start=ref<userId>`.
- Reward the inviter **only for a brand-new user** (no DB row yet), never for self-referral.
- The AI bot gave +6 hours of PRO per invite.
- Referral time is **free** time, so it must never unlock paid-only features (`Premium::isActive($id, paidOnly: true)`).
