# Bale wallet payments

Code: `starter/src/Payments.php`, used by `starter/webhook.php`. Tests: `starter/tests/run.php` and the E2E run.

## The flow (as in the live GPT_BOTble bot)
```
/buy → inline buttons (callback "buy:<package>")
     → sendInvoice(chat_id, title, description, payload, provider_token, prices)
     → user taps "pay from wallet"
     → update: pre_checkout_query        → answerPreCheckoutQuery(pre_checkout_query_id, ok=1)   ≤ 10 s!
     → update: message.successful_payment
     → inquireTransaction(transaction_id = telegram_payment_charge_id) → result.status == "paid"
     → credit the package (exactly once per transaction) + receipt to user + note to admin
```

### sendInvoice
| field | value |
|---|---|
| `provider_token` | Your wallet token, `WALLET-…`. Test wallet: `WALLET-TEST-1111111111111111`. Keep it in `config.php`. |
| `prices` | `[{"label": "…", "amount": 1490000}]`, **amount in rial**. In form mode it's sent as JSON text. |
| `payload` | `buy_<package>_<userId>_<time>`. You get it back in `successful_payment.invoice_payload`; check that the user id matches the payer. |

### Accepting the payment (pre-checkout)
- **This is where "something went wrong" comes from.** If Bale doesn't get a valid `ok` answer within about 10 seconds, the payment fails in the user's wallet with that message.
- **Answer first and fast:** in `webhook.php`, `pre_checkout_query` is handled before anything else.
- **Send exactly what worked:** form fields `pre_checkout_query_id=<id>&ok=1`. Changing this to JSON `"ok": true`, and adding a payload check that answered "no", broke payments in the AI bot. The fix was to go back to the old request and **accept every payment** here. The real check comes after payment, with `inquireTransaction`.
- **Logging:** the admin gets a short "pre-checkout تایید شد" line (`payment.debug`), or Bale's exact error if the answer failed.

### Confirming (inquireTransaction)
- **Parameter:** `transaction_id`, per the official docs. It takes the value of `successful_payment.telegram_payment_charge_id`.
- **The transaction can still be `pending`** when `successful_payment` arrives, so ask up to 4 times, 2 seconds apart.
- **Status:** accept `paid` in any letter case. Stop early on a clear failure (`failed`, `canceled` and similar).
- **Never confirmed:** keep the user's money safe. Tell the user to contact support, and send the admin a ready line, `/grant <user> <days> <receipt>`, to use after checking the wallet.

### Never credit twice
- Store every transaction in `payments` with `charge_id UNIQUE` and its status.
- Before crediting, skip if that `charge_id` is already `paid`, **or** if the admin already credited it by hand (`premium.reference` = the receipt).
- `UpdateDeduper` also drops re-delivered updates.

## Packages
Packages are defined in `config.php` → `payment.packages`, in one place: `title`, `description`, `amount` (rial) and `days`.

The AI bot used:

| package | price | days |
|---|---|---|
| هوشمندانه | 2,380,000 rial | 44 (30 + 14 Nowruz gift) |
| اقتصادی | 1,190,000 rial | 20 |
| حرفه‌ای | 3,900,000 rial | 60 |

## When it says "something went wrong"
1. **Admin chat shows "✅ pre-checkout تایید شد" but the payment still fails:** the wallet token is wrong. It must exactly match the token of the wallet the invoice was made for.
2. **Admin chat shows "⚠️ answerPreCheckoutQuery ناموفق": Bale's exact error is in the message.
3. **Nothing in the admin chat:** Bale isn't reaching the webhook. Check `getWebhookInfo` in `check.php`. Also check that `pre_checkout_query` is handled before anything that could fail or be slow.
