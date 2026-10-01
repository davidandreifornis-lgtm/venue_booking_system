# Venue Booking — PHP + SQL Server backend

## Requirements

- PHP 8.1+ with `pdo_sqlsrv` (Windows) or `pdo_dblib` (Linux)
- Microsoft SQL Server 2016+ (or Azure SQL)
- Navicat / SSMS to run the SQL scripts

## Database setup

1. Connect to SQL Server (server: `VMAPPS2`).
2. Create database **VenueBooking** if it does not exist.
3. Run in order:
   - `database/01_schema.sql`
   - `database/02_seed.sql`
   - (optional) `database/03_seed_demo_data.sql`
4. Confirm tables under `dbo`: Users, Roles, Departments, Venues, Bookings, etc.

## Connection config

Credentials live in **one place only**:

```
backend/config/database.php
```

Current values:

| Key        | Value         |
|------------|---------------|
| driver     | sqlsrv        |
| server     | VMAPPS2       |
| database   | VenueBooking  |
| username   | sa_dev        |
| password   | D3fault       |
| port       | (empty)       |

App settings (session, lockout, CORS, debug) are in `backend/config/config.php`.

## Demo logins (after seed)

| Login    | Password | Role          |
|----------|----------|---------------|
| admin    | admin    | Administrator |
| hr       | hr       | HR            |
| manager  | manager  | Manager       |
| employee | employee | Employee      |

Emails like `admin@company.local` also work with the same passwords.

## Run the API

### IIS (Windows)

- Point the site physical path at the **project root**.
- Install URL Rewrite module (`web.config` at root rewrites `/api/*` → `backend/api/index.php`).
- Confirm:
  - `http://venuebooking/backend/api/ping.php` → PHP alive
  - `http://venuebooking/backend/api/health.php` → DB check
  - `http://venuebooking/api/health` → same via rewrite

See root `SETUP_IIS.md` for details.

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
