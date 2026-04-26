# Medical Practice Bot

A PHP web application for Shifa Medical Center with a Telegram bot integration for patients.

---

## Features

- **Patient portal** — register, login, book/cancel appointments.
- **Doctor portal** — view patients, write notes and prescriptions, mark appointments complete.
- **Nurse dashboard** — confirm/cancel/complete appointments, send SMS reminders.
- **Telegram bot** — patients can check appointments, queue status, and **book new appointments** directly in Telegram via `/askappointment`.

---

## Telegram Bot Setup

### 1. Create a bot with BotFather

1. Open Telegram and search for `@BotFather`.
2. Send `/newbot` and follow the prompts.
3. Copy the **bot token** you receive (format: `123456789:ABCDEF...`).

> ⚠️ **Never commit the bot token to source control.**

### 2. Set the `TELEGRAM_BOT_TOKEN` environment variable

The application reads the token from the environment variable `TELEGRAM_BOT_TOKEN`.

**Linux / Apache (in your shell or `/etc/environment`):**
```bash
export TELEGRAM_BOT_TOKEN="your_token_here"
```

**Apache VirtualHost (`/etc/apache2/sites-available/your-site.conf`):**
```apache
SetEnv TELEGRAM_BOT_TOKEN "your_token_here"
```

**Nginx + PHP-FPM (in the `www.conf` pool file):**
```ini
env[TELEGRAM_BOT_TOKEN] = your_token_here
```

**cPanel / Hosting control panels:**  
Look for *Environment Variables* or *PHP Settings* in your control panel.

### 3. Register the webhook

Point Telegram to your `telegram_bot.php` file:
```
https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://yourdomain.com/telegram_bot.php
```

### 4. Run the DB migration

#### Option A — direct database client
Connect to your database and run:
```sql
SOURCE migrations/001_telegram_sessions.sql;
```
Or copy-paste the file contents into your database client (phpMyAdmin, TablePlus, etc.).

#### Option B — migration runner endpoint (DigitalOcean Managed DB workaround)

If you cannot connect to the database directly (e.g. port 25060 is blocked by your ISP),
use the included secret-protected endpoint instead:

1. **Set `MIGRATION_KEY`** in DigitalOcean App Platform  
   App → **Settings → App-Level Environment Variables** → add:
   ```
   MIGRATION_KEY = <a long random string of your choice>
   ```
   Mark it as **Encrypted/Secret**.  
   ⚠️ **Always set this variable before deploying.** Without it the endpoint falls back to a weak built-in default that is easy to guess.

2. **Deploy** — push (or force-redeploy) so the new file `admin/run_migrations.php` is live.

3. **Visit the endpoint once** in your browser (replace the key with the value you set):
   ```
   https://shifacenter.me/admin/run_migrations.php?key=<MIGRATION_KEY>
   ```
   You should see:
   ```
   OK: migration applied successfully.
   ```

4. **Remove or lock the file** after the migration succeeds — either:
   - Delete `admin/run_migrations.php` from the repo and redeploy, **or**
   - Change `MIGRATION_KEY` to a new secret value so the old URL no longer works.

---

## Database Configuration

All connection parameters **must** be supplied as environment variables.
No secrets are stored in source code.

| Variable | Required | Description |
|---|---|---|
| `DB_HOST` | ✅ | Database hostname (e.g. DigitalOcean managed DB host) |
| `DB_PORT` | ✅ | Database port — **`25060`** for DigitalOcean managed MySQL |
| `DB_NAME` | ✅ | Database / schema name |
| `DB_USERNAME` | ✅ | Database user |
| `DB_PASSWORD` | ✅ | Database password |
| `TELEGRAM_BOT_TOKEN` | ✅ | Telegram bot token from BotFather |
| `DEFAULT_DOCTOR_ID` | optional | Doctor assigned to Telegram bookings (default: `1`) |
| `MIGRATION_KEY` | optional | Secret key protecting `/admin/run_migrations.php` |
| `DB_SSL` | optional | Set to `true` to force TLS for DB connections. Defaults to `true` when `DB_PORT=25060` |
| `DB_CA_PATH` | optional | Absolute path to the TLS CA certificate (default: `ca-certificate.crt` in repo root) |

> ⚠️ **DigitalOcean managed MySQL** listens on port **25060** and **requires TLS**.
> SSL is automatically enabled when `DB_PORT=25060` (the default). The included
> `ca-certificate.crt` is used unless you override `DB_CA_PATH`.

---

## `/askappointment` Bot Command Flow

1. Patient sends `/askappointment`.
2. Bot asks for the **date** (`YYYY-MM-DD`).
3. Bot asks for the **time** (`HH:MM`).
4. Bot checks availability in the `appointments` table (slot is taken if a `scheduled` or `confirmed` appointment already exists for the same doctor/date/time).
5. If available: bot creates a `scheduled` appointment with:
   - `doctor_id = 1` (default doctor)
   - `send_sms = 0`
   - `queue_number = MAX(queue_number) + 1` for that doctor/date among active appointments
   - `notes = 'Booked via Telegram bot'`
6. Bot confirms booking and shows the queue number.
7. The new appointment appears automatically in the nurse dashboard under **Telegram Bookings** (filter `?filter=telegram`).

Send `/cancel_booking` at any time to abort an in-progress booking flow.

---

## Password Handling

- **New registrations** — passwords are hashed with `bcrypt` (`password_hash`).
- **Existing plaintext passwords** (legacy data) — the patient login performs a backward-compatible check: if `password_verify` fails (not a bcrypt hash), it falls back to a plaintext comparison and, on success, **automatically migrates the stored password to bcrypt**.
- Doctor and nurse logins use `password_verify` (bcrypt only).
