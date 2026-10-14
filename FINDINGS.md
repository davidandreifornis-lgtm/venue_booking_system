# Venue Booking System - pre-deployment review

**How this was produced:** I read the backend (BookingService, Auth, Permissions, API router, schema) and wrote tests.
I could NOT run anything: the sandbox had no PHP, no SQL Server and no network. Every "expected" result below is a
prediction from reading the code, not a test result. Run the suite and treat its output as the truth.

## Checklist: what I expect when you run the tests
| # | Item | Expected | Why (from the code) |
|---|------|----------|---------------------|
| 1 | Same venue, same time | **Likely FAIL** | `create()` locks and re-checks inside a transaction (good), but `approve()` checks for conflicts and then updates with no transaction. Pending requests don't hold the slot by default, so two overlapping pending requests can both be approved. The database has no constraint that stops overlapping confirmed rows. |
| 2 | Employee does Admin-only action | Likely pass | Every admin route calls `Permissions::require` on the server. |
| 3 | Self-approval | **FAIL for HR and Admin** | Self-approve is blocked in the service, but HR and Admin bookings are created already `Approved` (`BookingService::create`), so there is nothing to approve and no alternative approver. A Manager with no department can validate any department's requests. |
| 4 | Approver deactivated | **FAIL** | Deactivating a user does not reassign or alert anyone. Requests keep pointing at the inactive manager. |
| 5 | Reschedule conflict | Likely pass | Original schedule stays in place and keeps blocking its slot while a proposal is open; accepting rechecks for conflicts. |
| 6 | Email outage | **Cannot test** | There is no email at all: notifications are in-app rows only, with no delivery status or retry. |
| 7 | Restore backup | Not run | Manual drill script provided. No recovery targets or backup procedure are defined in the repo that I found. |
| 8 | Reports | **Cannot test** | There is no reporting feature. Only status filters on the booking list exist (those I test). |
| 9 | Remove only approver | **FAIL** | Only "last active Administrator" is protected. Demoting or deactivating the only Manager or HR is allowed with no warning or reassignment. |
| 10 | Mobile | Partly checked | All pages have a viewport tag (checked). The CSS has only 2 `@media` rules, so a real phone test will probably find layout problems. |

## Security issues to fix before any deployment (found while reading)
1. **Database login is committed to the repo**: `sa_dev` / `D3fault` in `backend/config/database.php`, `config.local.example.php` and `backend/README.md`. Treat it as exposed: change that password, remove it from the files and Git history, and use environment variables (the app now reads `VBS_DB_*`). Don't use an `sa`-style login for the app.
2. **`/api/health` needs no login** and returns the server name, database name and DB username (and raw error text).
3. **`debug` is `true`** in `config.php`, so 500 errors return raw exception messages (which include the DB server and name).
4. **Demo accounts** `admin/admin`, `hr/hr`, `manager/manager`, `employee/employee` are documented, and the seed script creates them. Passwords only need 6 characters.
5. Session cookie has no `Secure` flag; there is no CSRF token (only SameSite=Lax); no password reset.

## Other gaps against your spec (not in the 10-item checklist)
- Approval rules are hard-coded by role; there is no admin screen to configure approvers or stages, and no "Pending Alternative Approval" status.
- Approving does not re-check venue status, capacity or operating hours, so a venue put into Maintenance after a request was submitted can still be approved. Deactivating a venue does not notify or touch existing bookings. There is no maintenance/closure schedule; operating hours are global, not per venue.
- No recurring bookings; no audit of settings changes beyond the activity log.

## What I changed in your code (2 small files)
- `backend/lib/Response.php`: added a `Response::$throw` flag so tests can catch responses instead of the script exiting. Off by default; production behaviour is unchanged.
- `backend/lib/Database.php`: reads optional `VBS_DB_*` environment variables over the file config. If they're unset, nothing changes.
I did not touch the credentials or fix any of the problems above; say the word and I'll start with the Critical ones (#1, #3, #4, #9 and the security list).
