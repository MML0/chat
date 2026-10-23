# Lessons learned (from the GPT_BOTble work)

Each mistake that happened, and what to do instead.

## Process
- **Read the code that is actually deployed.** Work first started on a GitHub version (English, no payments) while the live bot was a different Persian Bale project with payments. Ask for the host's files (a zip without `config.php`) before changing anything.
- **Keep the old texts and behaviour** unless asked. The owner cares that the bot feels the same: Persian texts, packages, limits.
- **Commit the original first** (secrets replaced), then the changes. The diff then shows exactly what changed, and rollback is easy.
- **Keep line endings:**
  - The old files used Windows CRLF line endings.
  - Rewriting them with LF made every line look changed in the diff.
  - Keep the file's own line endings when editing.
- **Tell the owner exactly which files changed** and whether `migrate.php` must run. They often upload single files.

## Payments
- **Pre-checkout format:** changing the request to JSON `"ok": true` and adding a payload check broke payments ("something went wrong" in the wallet). The old form-encoded `ok=1` that accepted everything was the working version. Don't "improve" a working payment request without a fallback to the old format.
- **Missing token:** moving the wallet token from code to `config.php` silently disabled `/buy` for an owner who reused the old `config.php`. When a new config key is required, say it in the deploy steps, show it in `check.php`, and alert the admin.
- **Unchecked replies:** ignoring Bale's answers (invoice, pre-checkout) hid every failure. Check `ok`, and send Bale's `description` to the admin.
- **One-shot confirmation:** `inquireTransaction` asked once and compared case-sensitively, which can miss a payment that was still settling. Retry it, and normalise the status.
- **Double credit:** credit once per `charge_id`, including payments the admin credited by hand.

## Database
- **New prefix, never drop:** new tables with a new prefix plus a copy, and `migrate.php` never drops anything. The old `migrate.php` dropped all tables.
- **Charset:**
  - Use `utf8mb4` in the DSN and the tables.
  - Test data inserted with a latin1 client turned into mojibake.
  - A CLI showing `????` for good data means the *client* charset is wrong, not the data.
- **Id columns:** `BIGINT` for messenger ids.

## Webhook
- **De-duplicate re-delivered updates,** keyed on `update_id` plus a body hash. A key on `update_id` alone could drop real messages if ids ever repeat.
- **Secret in the webhook URL.**
- **Answer `pre_checkout_query` before anything slow.**
- **Don't hold DB locks** while calling Bale.

## Testing
- **Fake Bale:** a fake Bale server (`php -S` + a router script) plus a local MariaDB reproduce the whole flow, payments included, without the real API (`docs/08-testing.md`).
- **Mirror the real format:** make the fake as strict as you believe Bale is, but match what the **old working code sent**. A fake that only accepted JSON booleans hid the real problem.
- **OPcache:** PHP's opcode cache can serve an old `config.php` for a couple of seconds after you edit it during tests. Wait about 3 seconds.
- **`pkill` trap:** `pkill -f "php -S …"` inside a shell whose own command line contains that text kills the shell itself. Find PIDs with `ps | awk '/[p]hp -S/'`.

## Security found on the old host
- **Unprotected uploads:** `f.php` (an open web file editor) and `ftp.php` (an upload form with password `admin123`). Delete such files.
- **Secrets in code, all to be rotated:**
  - bot tokens
  - API keys
  - the admin password in a Python script
  - the wallet token
- **Guessable PRO code** (30 possible values).
