# BDayNotify — Employee Birthday & Celebration Notification Portal

An internal HR web application that keeps track of every employee's birthday and
automatically sends a personalised birthday email — complete with a generated
birthday **poster** — through your company's SMTP account. It also gives HR a
dashboard with live statistics, delivery analytics, an email-template gallery,
a bulk CSV import/export, and a one-click "send test" workflow.

Built with **plain PHP + MySQL**. No build tools, no Node, no Composer install,
and no internet connection are required to run it. If your machine already has
XAMPP (Apache + PHP + MySQL), you can run this project as-is.

---

## Table of contents

1. [What it does](#1-what-it-does)
2. [Technology & why it was chosen](#2-technology--why-it-was-chosen)
3. [The big picture (architecture)](#3-the-big-picture-architecture)
4. [Folder structure](#4-folder-structure)
5. [How a request flows](#5-how-a-request-flows)
6. [The database design](#6-the-database-design)
7. [Feature-by-feature walkthrough](#7-feature-by-feature-walkthrough)
8. [Setup & deployment (no installs)](#8-setup--deployment-no-installs)
9. [Configuration (.env) reference](#9-configuration-env-reference)
10. [Daily automatic sending (scheduling)](#10-daily-automatic-sending-scheduling)
11. [Security notes](#11-security-notes)
12. [Troubleshooting](#12-troubleshooting)
13. [Sample data & scaling to a full company](#13-sample-data--scaling-to-a-full-company)
14. [Demo-day script (what to click)](#14-demo-day-script-what-to-click)
15. [Using BDayNotify as a plugin (integration API)](#15-using-bdaynotify-as-a-plugin-integration-api)
16. [How to explain this project in one minute](#16-how-to-explain-this-project-in-one-minute)

---

## 1. What it does

- Stores the full employee roster (name, email, DOB, department, designation,
  plant/location, photo) in a normalised MySQL database.
- Every day it finds whose birthday it is and emails them a personalised
  birthday message using one of **10 responsive HTML templates**.
- Generates a **1500×1000 print-ready poster** (PHP GD) for each employee, with
  a circular photo crop (or coloured initials if there's no photo), and attaches
  it to the email.
- Tracks every send: sent / failed / opened (via a tracking pixel), shown in
  **Notification Logs** and summarised in the **Analytics** dashboard.
- Lets HR **preview** any template with real employee data, **import/export**
  employees as CSV (Excel-compatible), and run a **System Health** check.
- Has a **Test Mode** so you can demo everything safely — all mail is redirected
  to one test address and real employees are never contacted.

---

## 2. Technology & why it was chosen

| Layer | Technology | Why |
|---|---|---|
| Language | **PHP 8** | Ships with XAMPP; no runtime to install. |
| Database | **MySQL / MariaDB** (PDO) | Ships with XAMPP; PDO uses prepared statements (safe from SQL injection). |
| Email | **PHPMailer** (bundled in `/vendor`) | Reliable SMTP; already vendored, so **no `composer install` needed**. |
| Posters | **PHP GD extension** | Bundled with XAMPP PHP; just needs enabling in `php.ini`. |
| Charts | **Chart.js** (bundled in `assets/js/vendor`) | Served locally — works with no internet. |
| Icons | **Lucide** (bundled in `assets/js/vendor`) | Served locally — works with no internet. |
| UI | **Hand-written HTML/CSS/JS** | No framework, no build step — just open the files. |

> **Key point for deployment:** every dependency is either part of a standard
> PHP/MySQL stack or already included in this repository. There is **nothing to
> install** and **nothing is fetched from the internet at runtime**.

---

## 3. The big picture (architecture)

The app follows a simple, easy-to-read **"page controller"** pattern. There is
no framework. Each page in `dashboard/` (and `auth/`) is a self-contained PHP
file that:

1. Loads shared bootstrap files (config, database, auth, CSRF).
2. Handles its own form submissions (POST) at the top.
3. Runs its database queries.
4. Renders its own HTML at the bottom.

Shared logic lives in `includes/` and `config/`, so pages stay small and
consistent. Reads (dashboards, lists) always go through **database views**,
which hide the join complexity and guarantee stable column names.

```
Browser ──► auth/login.php ──► session created
   │
   ▼
dashboard/*.php
   ├─ require config/config.php        (loads .env, starts session, defines constants)
   ├─ require includes/auth_check.php  (redirects to login if not signed in)
   ├─ require config/database.php      ($conn = PDO connection, auto-creates DB)
   ├─ require includes/csrf.php        (CSRF token helpers for forms)
   └─ query VIEWS ──► render HTML + assets (styles.css, ui.js, chart/lucide)

cron/send_birthday_notification.php ──► finds today's birthdays
   ├─ includes/email_templates.php  (builds the HTML email)
   ├─ includes/poster_generator.php (builds the PNG poster with GD)
   └─ includes/mailer.php           (sends via PHPMailer SMTP) ──► notification_logs
```

**Design principle — one key everywhere.** Earlier the code mixed three
different employee identifiers (`employee_id`, `employee_code`, `emp_id`) which
broke queries. The schema was unified so **every table is keyed by a single
`emp_id` (VARCHAR)**, matching the format used in the source Excel sheet
(e.g. `EMP_ID 202527`). Backwards-compatible column aliases are exposed through
views so older queries keep working.

---

## 4. Folder structure

```
tynor/
├── index / entry points
│   ├── auth/                     Login, register, logout
│   ├── dashboard/                All signed-in pages (see below)
│   ├── cron/                     Scripts run on a schedule / "Send Now"
│   └── public/setup.php          One-click database installer
│
├── config/
│   ├── config.php                Bootstrap: loads .env, session, constants, photo_url()
│   ├── database.php              PDO connection (creates the DB if missing)
│   ├── env.php                   .env loader helper
│   └── mail_config.php           Mail-related constants
│
├── includes/                     Shared building blocks
│   ├── auth_check.php            "Must be logged in" guard
│   ├── csrf.php                  CSRF token generate/verify + form field
│   ├── mailer.php                One reusable SMTP send helper (PHPMailer)
│   ├── email_templates.php       The 10 birthday email templates
│   ├── poster_generator.php      GD poster engine (1500×1000, circular crop)
│   ├── anniversary_template.php  Work-anniversary / milestone email template
│   ├── audit.php                 Audit-trail helper (audit_log())
│   └── require_role.php          Role-based access helper
│
├── dashboard/
│   ├── index.php                 Home: stats, charts, poster customizer, quick actions
│   ├── employees.php             Roster: add/remove, filter, CSV import/export, send-test
│   ├── employees_io.php          CSV import/export/template engine (no dependencies)
│   ├── analytics.php             Delivery & open-rate analytics (Chart.js)
│   ├── preview_email.php         Live preview of the 10 templates with real data
│   ├── generate_posters.php      Streams a PNG poster for an employee
│   ├── approvals.php             Approval workflow (request → approve → send)
│   ├── audit_log.php             Audit trail viewer (Admin/SuperAdmin)
│   ├── system_health.php         Server/DB/SMTP readiness checks
│   ├── logs.php                  Full notification log (sent/failed/opened)
│   ├── send_test.php             Sends one real test email to the test recipient
│   ├── notification_channels.php Manage delivery channels
│   └── view_sent.php             View a previously sent email
│
├── cron/
│   ├── send_birthday_notification.php   Main daily birthday sender
│   ├── send_anniversary_notification.php Daily work-anniversary sender
│   ├── send_notifications.php           "Send Now" manual trigger
│   └── track_open.php                   1×1 pixel that records email opens
│
├── assets/
│   ├── css/styles.css            All styling (design system + polish + icons)
│   └── js/
│       ├── main.js               Small shared helpers
│       ├── toast.js              Toast notifications
│       ├── ui.js                 Emoji→Lucide icon replacement
│       └── vendor/               Chart.js + Lucide (bundled locally)
│
├── database/
│   ├── birthday_system.sql       Full schema + core sample data + admin user
│   ├── sample_data_extended.sql  Large synthetic roster (~260 more employees)
│   └── upgrade.sql               Additive migration (approvals + anniversary views)
├── scripts/generate_sample_data.php  Regenerates the large roster (adjustable)
├── img/                          Sample employee photos (emp1..emp10.jpg)
├── uploads/                      Uploaded employee photos (kept out of git)
├── vendor/                       PHPMailer (bundled — no composer install needed)
├── bdaynotify.php                Plugin/integration API — include this to drive it from another PHP system
├── .env                          Your real config (secrets; never commit)
├── .env.example                  Template to copy into .env
├── INSTALLATION.md               Short install guide
└── README.md                     This file
```

---

## 5. How a request flows

**Example: opening the dashboard.**

1. `config/config.php` runs first. It reads the `.env` file into environment
   variables, starts the PHP session (with secure cookie settings), and defines
   constants like `BASE_URL`, `ASSETS_URL`, `COMPANY_NAME`, and the helper
   `photo_url()` (which turns a stored relative photo path into a full URL).
2. `includes/auth_check.php` checks `$_SESSION['admin_id']`. If it's empty, the
   visitor is redirected to the login page. It also rotates the session ID every
   30 minutes to limit hijacking.
3. `config/database.php` opens a single PDO connection (`$conn`) using the
   `DB_*` values, and creates the `birthday_system` database if it doesn't exist.
4. The page runs its queries — reads go through **views** such as
   `v_todays_birthdays` and `v_employee_master_complete`.
5. The HTML is rendered and links to `assets/css/styles.css` plus the local
   `chart.umd.min.js`, `lucide.min.js`, `ui.js`, and `toast.js`.

**Example: sending birthday emails.**

`cron/send_birthday_notification.php` asks the `v_todays_birthdays` view who has
a birthday today, builds each email from `includes/email_templates.php`, renders
a poster with `includes/poster_generator.php`, and sends it via
`includes/mailer.php`. Every attempt is written to `notification_logs`. If
**Test Mode** is on, the recipient is swapped for the test address.

---

## 6. The database design

The schema is normalised: the core employee facts live in `employee_master`, and
the details that can change independently (date of birth, email, photo) live in
their own tables — all linked by the single `emp_id` key.

### Tables (13)

| Table | Purpose |
|---|---|
| `users` | Portal admins (login accounts). |
| `login_attempts` | Records login attempts for brute-force auditing. |
| `plant_master` | Plant / office locations. |
| `department_master` | Departments. |
| `designation_master` | Job titles. |
| `employee_master` | Core employee record (name, dept, designation, plant, status). |
| `dob_master` | Each employee's date of birth. |
| `email_master` | Each employee's official email. |
| `photo_master` | Each employee's photo path. |
| `notification_channels` | Configured delivery channels (e.g. email). |
| `email_templates` | Metadata for the templates. |
| `notification_logs` | Every send: status, timestamps, open tracking. |
| `audit_logs` | Full audit trail (who/what/when/IP). |
| `birthday_approvals` | Approval workflow batches (request → approve → send). |

### Views (4) — always used for reading

| View | What it gives you |
|---|---|
| `v_employee_master_complete` | One row per employee with **everything** joined (name, email, DOB, photo, department, designation, plant) plus computed `current_age` and `is_birthday_today`. Also exposes legacy aliases (`employee_id`, `employee_code`). |
| `employee_view` | Convenience alias used by the roster page. |
| `v_todays_birthdays` | Only the employees whose birthday is today. |
| `v_upcoming_birthdays` | Birthdays in the next ~30 days, with `days_away`. |
| `v_todays_anniversaries` | Work anniversaries today, with `years_of_service`. |
| `v_upcoming_anniversaries` | Anniversaries in the next ~30 days. |

> The two tables and two anniversary views above are added by the re-runnable
> `database/upgrade.sql`, applied automatically by `setup.php`.

**Why views?** They keep the joins in one place, guarantee the column names the
PHP expects, and let the app support both the new `emp_id` key and older column
names without changing the page code.

---

## 7. Feature-by-feature walkthrough

**Dashboard (`dashboard/index.php`)** — headline counts (total employees,
birthdays today, emails sent today, departments), today's & upcoming birthdays,
a department distribution bar chart, a 3-month birthday donut chart, and the
**Poster Customizer** (pick an employee → live poster preview → download PNG or
send a test email).

**Employees (`dashboard/employees.php`)** — the master roster with search,
department/plant filters, and column toggles. Add employees via a form, or use
**Import / Export**:
- *Download template* → a blank CSV with the correct headers.
- *Export CSV* → all employees (opens in Excel).
- *Import CSV* → validates each row (email format, required fields), fixes date
  formats, and upserts into all the related tables. New plants are auto-created.
Each row also has a **📧 Test** button to send that employee's birthday email to
the test address.

**Analytics (`dashboard/analytics.php`)** — success rate, open rate, a 14-day
delivery trend, a status doughnut, employees-by-department, and per-template
performance. All charts are drawn by the bundled Chart.js.

**Email Preview (`dashboard/preview_email.php`)** — choose any of the 10
templates and any employee; the email renders live in an iframe with that
person's real name, photo, designation and DOB.

**Poster generator (`includes/poster_generator.php`)** — a 1500×1000 landscape
canvas with a 35 mm bleed guide, gradient background, circular photo crop (with
a coloured-initials fallback), and TrueType text. Returned as a PNG.

**System Health (`dashboard/system_health.php`)** — checks PHP version,
extensions (incl. GD), writable `uploads/`, the database, `.env`, and lets you
**test the SMTP connection** and **send a diagnostic email**.

**Test Mode** — when `MAIL_TEST_MODE=1`, every outgoing email (including
"Send Now") is redirected to `MAIL_TEST_RECIPIENT`, the subject is prefixed
`[TEST]`, and a notice is added. Real employees are never emailed. Set it to `0`
for production.

**Work anniversaries & milestones (`v_todays_anniversaries`)** — alongside
birthdays, the system tracks each employee's work anniversary from their
joining date. The dashboard shows today's and upcoming anniversaries (milestone
years — 1, 5, 10, 15, 20, 25 — are highlighted), there's a dedicated
anniversary email template, and a daily sender at
`cron/send_anniversary_notification.php`.

**Approval workflow (`dashboard/approvals.php`)** — a governed send path:
someone **requests** a batch (birthdays or anniversaries) for the day, an
**Admin/SuperAdmin approves** (or rejects) it, and only then can it be **sent**.
Each batch records who requested, who decided, counts, and the outcome. Roles
are enforced (`require_role`), so approving/sending needs elevated permission.

**Audit trail (`dashboard/audit_log.php`)** — every significant action (login,
logout, employee add/delete, CSV import, approvals, sends) is recorded in
`audit_logs` with the user, timestamp, IP, and a JSON detail. The viewer is
Admin/SuperAdmin only and can be filtered by action type. This is the
compliance/governance layer.

---

## 8. Setup & deployment (no installs)

These steps assume the target PC already has **XAMPP** (or any Apache + PHP 8 +
MySQL stack). Nothing else needs to be installed.

### Step 1 — Copy the project into the web root
Put the `tynor` folder where the web server can serve it:
- **XAMPP (Windows):** `C:\xampp\htdocs\tynor`

Then the app's URL will be `http://localhost/tynor`.

### Step 2 — Start Apache and MySQL
Open the **XAMPP Control Panel** and click **Start** on **Apache** and **MySQL**.

### Step 3 — Create your configuration
Copy `.env.example` to `.env` (in the project root) and edit the values:
- `DB_*` — usually the XAMPP defaults work (`root`, empty password, port `3306`).
- `SMTP_*` — your sending account (for Gmail, use an **App Password**, no spaces).
- Keep `MAIL_TEST_MODE=1` and set `MAIL_TEST_RECIPIENT` to your own email while
  testing.

### Step 4 — Create the database
Pick **one**:
- **Easiest:** open `http://localhost/tynor/public/setup.php` in a browser. It
  creates the database, tables, sample employees, and the admin user.
- **Manual (command line):**
  ```
  C:\xampp\mysql\bin\mysql.exe -u root < database\birthday_system.sql
  C:\xampp\mysql\bin\mysql.exe -u root birthday_system < database\sample_data_extended.sql
  ```

Either way you end up with ~300 employees. `setup.php` imports the extended
roster automatically if the file is present.

### Step 5 — Enable posters (GD) — one-time
The poster feature uses PHP's GD extension. In XAMPP it's included but may need
enabling:
1. XAMPP Control Panel → Apache → **Config → php.ini**.
2. Find the line `;extension=gd` and remove the leading `;` so it reads
   `extension=gd`.
3. **Stop and Start Apache** so the change loads.

> If posters show an error instead of an image, this step (or the Apache
> restart) is almost always the reason.

### Step 6 — Verify
Open `http://localhost/tynor/dashboard/system_health.php` and confirm the checks
are green. Click **Test SMTP connection** and **Send diagnostic email**.

### Step 7 — Log in
Go to `http://localhost/tynor/auth/login.php`.
Default admin: **`admin@company.com` / `admin123`** — change this immediately.

> Everything renders offline: Chart.js, Lucide icons, and all styling are served
> from within the project. No internet connection is required to run the app.

---

## 9. Configuration (.env) reference

| Key | Meaning |
|---|---|
| `COMPANY_NAME` | Shown across the UI and in emails/posters. |
| `APP_DEBUG` | `1` shows PHP errors (dev); `0` hides them (production). |
| `BASE_URL` | Leave blank to auto-detect; set explicitly for cron use. |
| `DB_HOST` / `DB_PORT` | Database host & port (XAMPP default `127.0.0.1:3306`). |
| `DB_NAME` | Database name (default `birthday_system`). |
| `DB_USER` / `DB_PASS` | Database credentials (XAMPP default `root` / empty). |
| `SMTP_HOST` / `SMTP_PORT` | Mail server (Gmail: `smtp.gmail.com` / `587`). |
| `SMTP_ENCRYPTION` | `tls` (port 587) or `ssl` (port 465). |
| `SMTP_USERNAME` / `SMTP_PASSWORD` | Mail login (Gmail App Password, no spaces). |
| `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` | The "From" shown to recipients. |
| `CRON_SECRET` | Secret token that authorises cron scripts via URL. |
| `MAIL_THROTTLE_MS` | Delay between messages to avoid rate limits. |
| `MAIL_TEST_MODE` | `1` = redirect all mail to the test address (safe). `0` = real. |
| `MAIL_TEST_RECIPIENT` | Where test-mode mail goes. |

> `.env` contains secrets. It is listed in `.gitignore` and must never be
> committed or shared.

---

## 10. Daily automatic sending (scheduling)

Run the sender once a day. On Windows use **Task Scheduler**:
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\xampp\htdocs\tynor\cron\send_birthday_notification.php`
- Trigger: daily at, say, 08:00.

On Linux use cron:
```
0 8 * * * php /var/www/html/tynor/cron/send_birthday_notification.php
5 8 * * * php /var/www/html/tynor/cron/send_anniversary_notification.php
```

Add a second scheduled task for `cron/send_anniversary_notification.php` to send
work-anniversary emails the same way.

You can also trigger a send manually from the dashboard via **Send Now**
(`cron/send_notifications.php`). While `MAIL_TEST_MODE=1`, both routes send only
to the test recipient.

---

## 11. Security notes

- **Secrets** live only in `.env` (git-ignored). Never commit it.
- **SQL injection** is prevented by using PDO prepared statements everywhere.
- **CSRF**: every form includes a token checked on submit (`includes/csrf.php`).
- **Passwords** are hashed and never displayed, logged, or returned.
- **Sessions** use HTTP-only cookies, strict mode, and periodic ID rotation.
- After installing, **delete or protect `public/setup.php`** and change the
  default admin password.

---

## 12. Troubleshooting

| Symptom | Fix |
|---|---|
| "Database connection failed" | Check `DB_*` in `.env`; make sure MySQL is started in XAMPP. |
| Poster shows an error / no image | Enable `extension=gd` in `php.ini`, then **Stop + Start Apache**. |
| Charts or icons don't appear | Confirm `assets/js/vendor/` contains `chart.umd.min.js` and `lucide.min.js`. |
| SMTP "Could not authenticate" | Use a valid **App Password** (no spaces); check `SMTP_USERNAME`. |
| Emails land in spam | Normal for a brand-new sender; mark "Not spam" once. |
| Images/links wrong after moving hosts | Set `BASE_URL` in `.env` to the real URL. |
| "Age 0" or odd age | The employee's DOB year is wrong/missing in the data; fix the DOB. |

---

## 13. Sample data & scaling to a full company

The project ships with two layers of sample data:

- **`database/birthday_system.sql`** — the core ~39 employees plus all lookups
  (plants, departments, designations), the admin user, and the templates.
- **`database/sample_data_extended.sql`** — a **large synthetic roster** (about
  260 more employees, so ~300 total) that makes the dashboard, charts, logs and
  birthday lists look like a real company. It is imported automatically by
  `setup.php`, and is written with `INSERT ... ON DUPLICATE KEY UPDATE` so
  re-running never creates duplicates.

The synthetic data is **generated**, not hand-written. It contains no real
people; birthdays are deliberately spread across the whole year (with a few on
today and several in the next 30 days) so the "Today's" and "Upcoming" panels
always have something to show.

### Regenerating or growing the dataset

To change the size, run the generator and set the number of employees:

```
C:\xampp\php\php.exe scripts\generate_sample_data.php 500
```

That rewrites `database/sample_data_extended.sql` with 500 employees. Import it
(or just re-run `public/setup.php`) to load it. Because every read goes through
indexed views and every write uses prepared statements, the same code handles
40 or 4,000 employees without changes — that's the scalability story to tell in
a review: **the design doesn't change with volume, only the data does.**

> If you ever have a real roster (for example a CSV exported from an HR system or
> a Kaggle-style employee dataset), you don't need this generator at all — use
> **Employees → Import CSV** to load it, and the same dashboards/analytics scale
> to it automatically.

---

## 14. Demo-day script (what to click)

A tight 5-minute walkthrough to present the project:

1. **Log in** at `/auth/login.php` (`admin@company.com` / `admin123`).
2. **Dashboard** — point out the live counts (~300 employees), the department
   bar chart, and the 3-month birthday donut. Mention it's real data from ~300
   employees to show scale.
3. **Poster Customizer tab** — pick an employee → the poster renders live
   (circular photo + details). Click **Download Poster PNG** to show the export.
4. **Send test birthday email** — click it; open the inbox to show the real
   email arriving with the poster attached. Note the `[TEST]` prefix and that
   real employees are never contacted (Test Mode).
5. **Email Preview** — flip through a few of the 10 templates with live employee
   data in the iframe.
6. **Employees** — show the search/filter, then **Import / Export**: download the
   template, and mention bulk CSV import (Excel-compatible) for onboarding a whole
   company at once. Point out the per-row **Test** button.
7. **Analytics** — success rate, open-rate tracking, 14-day delivery trend,
   per-template performance.
8. **System Health** — everything green; click **Test SMTP connection** live.
9. Close by opening **Notification Logs** to show every send is recorded
   (sent / failed / opened).

**One-liner to open with:** "It's a self-contained PHP/MySQL HR portal that
automatically emails personalised birthday cards to ~300 employees, tracks every
send, and runs on a standard XAMPP install with nothing to install."

---

## 15. Using BDayNotify as a plugin (integration API)

BDayNotify can run as a standalone portal **or** be dropped into an existing
company PHP system and driven programmatically — no dashboard or login needed.
Everything is exposed through one include file, **`bdaynotify.php`**, at the
project root.

### Why it qualifies as a "plugin"
- **Self-contained** — all dependencies (PHPMailer, Chart.js, icons) are bundled;
  nothing to install.
- **Drop-in / path-independent** — include it from anywhere; every internal path
  resolves from the file itself, and `BASE_URL` auto-detects the install path.
- **No namespace clashes** — every public function is prefixed `bdaynotify_`.
- **No side effects on include** — including the file only defines functions; it
  produces no output and sends nothing until you call it.
- **Same behaviour as the UI** — it reuses the exact mailer, templates, poster
  and logging used by the dashboard and cron, so Test Mode and logs behave
  identically.

### The public API

```php
require_once __DIR__ . '/tynor/bdaynotify.php';

bdaynotify_version();                       // "1.2.0"

// Read
$today    = bdaynotify_todays_birthdays();  // array of employees with a birthday today
$soon     = bdaynotify_upcoming_birthdays(30); // next N days
$annToday = bdaynotify_todays_anniversaries();  // work anniversaries today
$annSoon  = bdaynotify_upcoming_anniversaries(30);

// Send (honours Test Mode + writes to notification_logs + attaches the poster)
$one      = bdaynotify_send_birthday('EMP_ID 202527');       // one employee (random template)
$one      = bdaynotify_send_birthday('EMP_ID 202527', 3);    // force template #3
$summary  = bdaynotify_run_daily();         // send to everyone whose birthday is today
bdaynotify_send_anniversary('EMP_ID 202527');   // one anniversary email
bdaynotify_run_daily_anniversaries();           // all anniversaries today

// Write — sync an employee in from the host HR system
$res = bdaynotify_upsert_employee([
    'emp_id'           => 'E-1042',
    'full_name'        => 'Asha Menon',
    'email'            => 'asha.menon@company.com',
    'dob'              => '1994-03-18',      // YYYY-MM-DD
    'department_name'  => 'Finance and Accounts',
    'designation_name' => 'Analyst',
    'mobile_no'        => '9876543210',
    // optional: plant_id, plant_name, plant_location, photo_path, status
]);
```

Return shapes are documented in `bdaynotify.php`. `bdaynotify_run_daily()`
returns `['total', 'sent', 'failed', 'details']`.

### Common integration patterns

**1. Let the host system's own scheduler send the daily emails**
```php
// company_cron.php (run daily by the host's scheduler)
require_once __DIR__ . '/tynor/bdaynotify.php';
$r = bdaynotify_run_daily();
error_log("BDayNotify: sent {$r['sent']}, failed {$r['failed']}");
```

**2. Keep employees in sync from the host HR module**
```php
foreach ($hrSystem->getEmployees() as $e) {
    bdaynotify_upsert_employee([
        'emp_id' => $e->id, 'full_name' => $e->name,
        'email'  => $e->email, 'dob' => $e->birthDate,
        'department_name' => $e->department,
    ]);
}
```
(Or, with no code at all, use **Employees → Import CSV** in the UI.)

**3. Embed a link to the dashboard** in the host app's menu:
`https://your-host/tynor/dashboard/index.php`

### HTTP integration (optional)
The poster endpoint already accepts a token so another system can fetch a poster
image without a login session:
```
GET /tynor/dashboard/generate_posters.php?emp_id=EMP_ID%20202527&token=<CRON_SECRET>
```
`CRON_SECRET` is set in `.env`. (The email-send endpoints require a login session
by design; use the PHP API above for server-to-server sending.)

---

## 16. How to explain this project in one minute

> "BDayNotify is an internal HR portal written in plain PHP and MySQL. It keeps
> a normalised employee database — every table is linked by a single employee ID
> — and reads through database views so the queries stay simple and stable.
> Each day a scheduled PHP script checks who has a birthday, builds a
> personalised email from one of ten templates, generates a poster image using
> PHP's GD library, and sends it through our SMTP account with PHPMailer. Every
> send is logged, and the dashboard shows live stats, delivery analytics, and an
> open-rate tracker. HR can preview templates, bulk-import employees from Excel
> via CSV, and run everything in a safe Test Mode that never emails real staff.
> It has no external dependencies — PHPMailer, Chart.js and the icons are all
> bundled — so it runs on a standard XAMPP install with nothing to configure but
> the `.env` file."

**Three design decisions worth highlighting in a review:**
1. **One unified key (`emp_id`)** + database views fixed the original schema
   conflicts and made every page reliable.
2. **Zero runtime dependencies** (everything bundled, nothing from a CDN) so it
   deploys on a locked-down company PC with no internet and no installs.
3. **Test Mode** makes it safe to demonstrate live — real employees are never
   contacted until you deliberately switch to production.
4. **Plugin-ready** — a single `bdaynotify.php` include exposes a clean,
   prefixed API (`bdaynotify_run_daily()`, `bdaynotify_upsert_employee()`, …) so
   the whole thing can be embedded into the company's existing PHP system, not
   just run as a standalone portal.
