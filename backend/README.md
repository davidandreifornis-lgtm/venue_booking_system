# Venue Booking — PHP + SQL Server backend

## Requirements

- PHP 8.1+ with extensions: `pdo_sqlsrv` (Windows) or `pdo_dblib` (Linux)
- Microsoft SQL Server 2016+ (or Azure SQL)
- Navicat for SQL Server (or SSMS) to run scripts

## Database setup (Navicat)

1. Connect to your SQL Server instance in Navicat.
2. Create a database named **`VenueBooking`** (or change `config.php`).
3. Open and run in order:
   - `database/01_schema.sql`
   - `database/02_seed.sql`
4. Confirm tables under `dbo`: Users, Roles, Departments, Venues, Bookings, etc.

## Configure connection

Edit `backend/config/config.php` or set environment variables:

| Variable | Example |
|----------|---------|
| `VBS_DB_HOST` | `127.0.0.1` |
| `VBS_DB_PORT` | `1433` |
| `VBS_DB_NAME` | `VenueBooking` |
| `VBS_DB_USER` | `sa` |
| `VBS_DB_PASS` | your password |

On Linux, set `'driver' => 'dblib'` if `sqlsrv` is not installed.

## Demo logins (after seed)

| Login | Password | Role |
|-------|----------|------|
| `admin` | `admin` | Administrator |
| `hr` | `hr` | HR |
| `manager` | `manager` | Manager |
| `employee` | `employee` | Employee |

Also seeded: `admin@company.local`, etc. (same passwords).

## Run API

### IIS (Windows)

- Point a site / application to the project root.
- Ensure URL Rewrite is installed (`web.config` under `backend/api`).
- Map `/api` to `backend/api` or use the root `.htaccess` equivalent rewrite.

### Apache

Document root = project root. Root `.htaccess` routes `/api/*` → `backend/api/index.php`.

### PHP built-in server (dev)

```bash
cd venue-booking-system
php -S 127.0.0.1:8080 router.php
```

Use `backend/router-dev.php` if provided.

## Frontend

`js/api.js` already calls `/api/...` with `credentials: 'same-origin'`.

Serve the frontend from the **same origin** as the API so the PHP session cookie is sent.

After the real backend is up, remove or disable `tryDemoLogin` in `js/auth.js` so only server sessions are used.
