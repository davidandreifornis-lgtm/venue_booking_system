# Security & policy fixes (2026-10-22)

## Self-approval / HR–Admin bookings (policy restored)
- HR and Administrator bookings are **official: confirmed immediately, no approver** (as originally designed).
- Employees and Managers can never approve their own request.
- A confirmed HR/Admin booking cannot be placed over another confirmed booking (409); use the emergency override.
- Requests still waiting for HR at an overlapping time are moved to the nearest free slot or declined automatically.
- The audit trail records `Official booking by HR/Administrator`; an optional written note is kept as `BypassReason`.

## Demo credentials & password policy
- Minimum password length raised from **6 → 10**, requiring at least one letter and one digit
  (`Auth::validatePassword`, enforced on user create/update API and Users UI).
- Demo accounts (dev only):
  | Login    | Password     | Role            |
  |----------|--------------|-----------------|
  | admin    | Admin@2026!  | Administrator   |
  | hr       | Hr@2026!xx   | HR              |
  | manager  | Mgr@2026!x   | Manager         |
  | employee | Emp@2026!x   | Employee        |
- Re-run `database/02_seed.sql` (or `03_seed_demo_data.sql`) to refresh hashes, or set passwords in Users.

## Other hardening
- DB credentials removed from committed `database.php` (use `VBS_DB_*` env or `config.local.php`).
- `debug` defaults to **false** (enable with `VBS_DEBUG=1`).
- Session cookie sets `Secure` when HTTPS is detected (or `session.force_secure_cookie`).
- `/health` no longer exposes server/DB username publicly; detailed diagnostics need
  `X-VBS-Health-Token` matching `VBS_HEALTH_TOKEN` / `security.health_token`.
- XSS: escaped user-controlled fields on employee dashboard, admin activity list, reports table.

## Deploy notes
1. Copy `backend/config/config.local.example.php` → `config.local.php` with real DB credentials **or** set env vars.
2. Do **not** use `sa` for the app login.
3. Re-seed or update demo user passwords.
4. Hard-refresh the browser after deploy.
