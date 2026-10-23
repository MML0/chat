# Bale bot kit + anonymous random chat (چت ناشناس)

Everything learned from building and fixing the Persian Bale AI bot (GPT_BOTble), packed for the next bot: an **anonymous random chat** for Bale, like Omegle or a mini chat.

## What's here
| | |
|---|---|
| `starter/` | **Tested, reusable PHP**: Bale API client, wallet payments, premium time, update de-duplication, `migrate.php`, `check.php`, and a `webhook.php` skeleton. No Composer needed. |
| `docs/01-bale-api.md` | Endpoints, request formats, update types, links, limits, gotchas |
| `docs/02-payments.md` | The exact wallet payment flow that works, and what broke when it changed |
| `docs/03-deploy-config-migrate-check.md` | Shared-host deploy, config, safe migrations, check page, rollback |
| `docs/04-buttons-and-ux.md` | Inline vs reply keyboards, callback data, Persian UX texts, commands |
| `docs/05-premium-and-security.md` | Paid vs referral premium, admin commands, secrets, things never to upload |
| `docs/06-random-chat-design.md` | **The plan for the random chat bot**: states, matching, relay, safety, premium, tables |
| `docs/07-lessons-learned.md` | Every mistake from the last project and how it was fixed |
| `docs/08-testing.md` | Offline tests and the local end-to-end setup with a fake Bale |
| `docs/09-ai-and-voice.md` | Optional: free-first AI models and premium voice-to-text from the AI bot |

## Quick start (starter)
1. Copy `starter/` to the host and create `config.php` from `config-example.php`.
2. Open `migrate.php?pass=ADMIN_PASSWORD`, then `check.php?pass=ADMIN_PASSWORD`. Everything should show ✔.
3. Open `check.php?pass=ADMIN_PASSWORD&setwebhook=1` to point the bot at this folder.
4. In Bale, try `/start`, `/help`, `/buy` (use the test wallet `WALLET-TEST-1111111111111111` first) and `/invite`.

Tests: `php starter/tests/run.php` (offline, 36 checks) and the end-to-end run in `docs/08-testing.md` (18 checks).
