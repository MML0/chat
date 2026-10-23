# Project rules (read first)

This repo is for **Bale (بله) messenger bots in plain PHP**. The first one planned is an anonymous random chat bot, like Omegle or a mini chat. The tested building blocks are in `starter/`; everything learned so far is in `docs/`.

## Owner's rules
- **Commits:** author is `MML0 <h1337124lat@gmail.com>`. **No Claude/AI names** anywhere: no `Co-Authored-By`, no session links, nothing in commit messages, code or docs.
- **Language:** every user-facing text is **Persian**, friendly, with emojis like the existing bots. Code, comments and logs are English.
- **Hosting:** shared PHP hosting in Iran. The owner uploads a zip and opens a URL; there's no SSH, Composer or cron.
  - Keep **zero Composer dependencies** if possible (curl + PDO only). If a library is unavoidable, commit `vendor/`.
  - Target PHP 8.1+, MySQL/MariaDB, utf8mb4.
- **Deliverables:** every change must keep `migrate.php` (safe to re-run, never drops) and `check.php` working. Give the owner a deployable zip and exact steps: which files changed, whether to run `migrate.php`.
- **Rollback:** a new version that changes tables uses a **new `table_prefix`** and copies data. Old tables are never modified. Rollback = point the webhook back to the old folder.
- **Secrets:** never in code, docs or commits. They live in `config.php`, which is git-ignored. If you find one, flag it and tell the owner to rotate it.
- **Testing:** before saying it works, run `php starter/tests/run.php` and the E2E in `docs/08-testing.md`. Be honest about what could not be tested against real Bale.
- **Format choices:** when a Bale request format is uncertain, keep what the old working bot sent, and add a fallback rather than replacing it. Payments especially; see `docs/02-payments.md`.

## Where things are
| path | what |
|---|---|
| `hamsobat/` | **The random chat bot (هم‌صحبت).** Read `hamsobat/README.md` first. Tests: `php hamsobat/tests/run.php`, `php hamsobat/tests/concurrency.php`, `php hamsobat/tests/e2e/run_e2e.php` |
| `hamsobat/panel/` | Web admin panel (Persian, RTL). Pages are `panel/views/*.php`; queries in `services/PanelData.php`, actions in `services/PanelActions.php`, login in `services/PanelAuth.php`. Preview with `tests/demo_seed.php` |
| `starter/src/Bale.php` | API client (form first, JSON fallback), buttons, copyMessage, file download |
| `starter/src/Payments.php` | Wallet payments: invoice → pre-checkout → successful_payment → inquireTransaction |
| `starter/src/Premium.php` | Premium time rows (payment / admin / referral), paid-only checks, fail closed |
| `starter/src/UpdateDeduper.php` | Never process a re-delivered update twice |
| `starter/webhook.php` | Update router in the right order. App logic goes at the end. |
| `starter/migrate.php`, `starter/check.php` | Deployment tools |
| `docs/06-random-chat-design.md` | The plan for the random chat bot |
