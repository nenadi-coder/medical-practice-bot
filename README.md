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

Before deploying, run the migration to create the session state table:
```sql
SOURCE migrations/001_telegram_sessions.sql;
```
Or copy-paste the file contents into your database client.

---

## Database Configuration

The DB connection parameters can also be set via environment variables (defaults to the values in `includes/config.php`):

| Variable | Default |
|---|---|
| `DB_HOST` | *(DigitalOcean host)* |
| `DB_NAME` | `defaultdb` |
| `DB_USERNAME` | `doadmin` |
| `DB_PASSWORD` | *(stored in config)* |
| `TELEGRAM_BOT_TOKEN` | *(must be set — no default)* |
| `DEFAULT_DOCTOR_ID` | `1` (doctor assigned to Telegram bookings) |

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
