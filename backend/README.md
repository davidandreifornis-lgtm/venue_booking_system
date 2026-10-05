# Venue Booking — PHP + SQL Server backend

## Requirements

- PHP 8.1+ with `pdo_sqlsrv` (Windows) or `pdo_dblib` (Linux)
- Microsoft SQL Server 2016+ (or Azure SQL)
- Navicat / SSMS to run the SQL scripts

## Database connection (your environment)

Credentials live in **one place only**:

```
backend/config/database.php
```

Current values (same server/login as toner_inventory):

| Key        | Value         |
|------------|---------------|
| driver     | sqlsrv        |
| server     | VMAPPS2       |
| database   | VenueBooking  |
| username   | sa_dev        |
| password   | D3fault       |
| port       | (empty)       |

Optional override: copy `config.local.example.php` → `config.local.php` and edit there (Database.php merges it automatically).

## Database setup

1. On SQL Server `VMAPPS2`, create database **VenueBooking** if it does not exist.
2. Run in order (Navicat / SSMS):
   - `database/01_schema.sql`
   - `database/02_seed.sql`
   - (optional) `database/03_seed_demo_data.sql`
   - **`database/04_fix_settings.sql`** ← run this if Settings table is empty
3. Confirm tables under `dbo`: Users, Roles, Departments, Venues, Bookings, Settings, etc.

## Demo logins (after seed)

| Login    | Password | Role          |
|----------|----------|---------------|
| admin    | admin    | Administrator |
| hr       | hr       | HR            |
| manager  | manager  | Manager       |
| employee | employee | Employee      |

Emails like `admin@company.local` also work with the same passwords.

## Verify connection

1. `http://your-site/backend/api/ping.php` → PHP alive
2. `http://your-site/backend/api/health.php` → must show `"ok": true` and `"db_name": "VenueBooking"`
3. `http://your-site/api/health` → same via URL rewrite

## Run the API

### IIS (Windows)

- Point the site physical path at the **project root**.
- Install URL Rewrite module (`web.config` at root rewrites `/api/*` → `backend/api/index.php`).
- See root `SETUP_IIS.md`.

### Apache

Document root = project root. Root `.htaccess` routes `/api/*` → `backend/api/index.php`.

### PHP built-in server (dev)

```bash
cd venue_booking_system
php -S 127.0.0.1:8080 router.php
```

Then open:
- http://127.0.0.1:8080/api/health
- http://127.0.0.1:8080/pages/login.html

## Frontend

`js/api.js` calls `/api/...` with `credentials: 'same-origin'`.

Serve the frontend from the **same origin** as the API so the PHP session cookie is sent.
