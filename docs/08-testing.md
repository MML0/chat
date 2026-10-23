# Testing

## 1. Offline unit tests (no DB server, no network)
```sh
php starter/tests/run.php
```
These use SQLite in memory and a fake Bale transport. They cover:
- **Bale client:** form first with JSON fallback, no retry after a network error, arrays sent as JSON text.
- **Payments:** the invoice, refused invoices and a missing token, pre-checkout with `ok=1`, "pending → PAID", duplicates, never-confirmed with the admin's `/grant` line, a manual grant blocking a second credit, and failed or foreign payloads.
- **Premium:** referral ≠ paid, a receipt counts once, stacking, fail closed.
- **De-duplication.**

## 2. End-to-end (real webhook.php + MySQL + fake Bale)
```sh
# a database (local MariaDB example)
mysql -uroot -e "CREATE DATABASE rc CHARACTER SET utf8mb4; CREATE USER 'bot'@'127.0.0.1' IDENTIFIED BY 'botpass'; GRANT ALL ON rc.* TO 'bot'@'127.0.0.1';"

# starter/config.php (never committed): copy config-example.php, then set
#   database → 127.0.0.1 / port 3307 / rc / bot / botpass
#   bale.api_base → 'http://127.0.0.1:8099'
#   bale.webhook_secret, admin.password, admin.chat_id → anything
#   payment.provider_token → 'WALLET-TEST-1111111111111111'

php -S 127.0.0.1:8099 starter/tests/e2e/mock_bale.php &   # fake Bale (logs to tests/e2e/state/)
php -S 127.0.0.1:8098 -t starter &                        # the bot
php starter/tests/e2e/run_e2e.php                         # 18 checks
```

It covers:
- `migrate.php`: password, run twice
- `check.php`: everything ✔, plus `setwebhook`
- the webhook secret
- `/start`, referral, `/buy` buttons
- the full payment: invoice → pre-checkout → successful_payment → inquire → receipt, then a duplicate
- `/help` showing the remaining time
- admin `/grant`

If the sandbox has an HTTP proxy, unset `http_proxy`/`HTTP_PROXY` for these commands.

## 3. On the real Bale (before launch)
- Use the test wallet `WALLET-TEST-1111111111111111` for payments, then the real token.
- Check the ⚠️ items in `01-bale-api.md`, especially `copyMessage` and the reply keyboard, for the random chat.
- Watch the admin chat: payment steps and failures are reported there.
