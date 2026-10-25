# Bug-fix pass (review of the whole system)

Every item below was found by reading the code and, where possible, checking it mechanically (imports, SQL names,
brackets). **Nothing could be executed** (no PHP / SQL Server available), so run the test suite before deploying:
`vendor/bin/phpunit` (new: `tests/BugFixRegressionTest.php`).

## Things that were plainly broken
| # | Where | Problem | Fix |
|---|-------|---------|-----|
| 1 | `pages/reports.html` | Imported `api` and `showToast`, which do not exist, so the whole page failed to load; it also used a different page skeleton than every other page. | Rebuilt on the standard skeleton; added `getReport()` to `js/api.js`. |
| 2 | `index.php` settings | The settings added for e-mail, SLA, revisions, auto-complete, venue hours, etc. were **silently ignored** on save (unknown keys are skipped) and had no fields on the Settings page, so they could only be changed in SQL. | Validation rules + fields added; SMTP password is masked and never written to the activity log. |
| 3 | `/bookings/{id}/queue`, `/calendar/ical` | Required `booking.view_own`, which HR and Administrator do not have, so the people who need them got 403. | Accept `view_own` **or** `view_all`. |
| 4 | `/bookings/{id}/queue` | Any logged-in user could read the queue of any booking, including other requesters' names. | Owner / same-department manager / HR / Admin only; requesters see their position, not other people. |
| 5 | `RecurrenceService` | `Response::error()` ends the request in production, so one conflicting week stopped the whole series (and left the rest uncreated) while a `try/catch` pretended otherwise. Anyone could also repeat anyone else's booking. | Each week is validated first and skipped with a reason; response now lists `skipped`; only the requester may repeat a booking. |
| 6 | `MailService` | SMTP had no STARTTLS (port 587 always failed), no timeouts (a dead server froze every booking action for 15 s per notification), did not check replies, and e-mail links were relative (`booking-details.html?id=1`). | STARTTLS / implicit TLS, 5 s connect + 8 s I/O timeout, reply-code checks, one failure per request then skip, absolute links (`app_base_url` setting or auto-detected), non-mailbox logins (e.g. `admin`) recorded as *skipped* instead of *failed*. |
| 7 | `create()` | `ReachedHrAt` for Manager/HR/Admin requests used the PHP clock (Asia/Manila) while manager validation used the database clock (UTC): the "first to reach HR" order was wrong by 8 hours between the two kinds. | Taken from `SYSUTCDATETIME()` like every other timestamp. |
| 8 | `markNoShow` | Could be applied to a booking that had not started, which freed its slot. | Only after the start time has passed. |
| 9 | Auto-reschedule + "keep original" | A request moved automatically because someone else took its slot, when rejected by the requester, went back to HR's queue where it could never be approved (stuck forever). | Declined with an explanation when the original time is held by a confirmed booking. |
| 10 | `completePastBookings()` | Compared local booking times to the UTC clock (8 h late) and duplicated `completePast()`. | One implementation on the app clock; `auto_complete_enabled` is honoured. |
| 11 | Manager SLA escalation | Nothing ever called it (no cron in XAMPP/IIS), so requests never escalated. | Runs opportunistically, at most every 5 minutes. |
| 12 | `VenueScheduleService` | Weekday came from `DATEPART(WEEKDAY)`, which depends on the SQL Server language/DATEFIRST (wrong closed days on some servers); `17:00:00` compared as later than `17:00`. | Weekday computed in PHP; times normalised to `HH:MM`. |
| 13 | `requestRevision` | Parameters inside `CASE` expressions (type-inference errors on SQL Server). | Column chosen in PHP. |
| 14 | `listForUser` | A Manager could pass `scope=all` and see every department. | Managers stay in their department. |
| 15 | `BookingWorkflow` | No `Reschedule Proposed -> Rescheduled` transition (emergency override). | Added. |
| 16 | `booking-details.html` | Administrators could not approve/decline in the UI although the API allows it (so an HR booking with no other HR user could never be approved). | HR and Administrator, never on their own request. |
| 17 | `00_FULL_RESET.sql` | Re-created `admin/admin`-style demo passwords, contradicting SECURITY_FIXES.md. | Same strong demo hashes as `02_seed.sql`. |
| 18 | iCal export | Dates not validated; backslashes / line breaks not escaped. | Validated and escaped. |

## HR / Admin bookings need no approver (policy restored)
HR and Administrator bookings are confirmed at creation again. To make that work with the newer features:
* Booking over a **confirmed** booking is refused (409) - use the **Emergency override** (move or cancel it) first.
* Booking over requests that are still **waiting for HR** confirms yours and automatically moves those requests to the
  nearest free slot (or declines them, per Settings), exactly as when HR approves one request over another.
* The emergency override keeps an optional "Also book this time for me now" step so nobody can grab the slot in between.
* A request created by HR/Admin while the stricter rule was active, and still pending, can be confirmed by its owner.
* Employees and Managers still cannot approve their own requests.

## Tests
* `ChecklistTest::test03b` now asserts HR/Admin bookings are confirmed with no approver; `test01a` is back to racing HR vs Admin.
* `ConflictResolutionTest`: 3 new tests for HR/Admin direct bookings; emergency-cancel test uses the take-over step.
* New `BugFixRegressionTest` (8 tests) covering items 3, 4, 5, 7, 8, 9, 14.

## Still not done (features that exist in the API but have no screen)
Per-venue hours and blackout dates, weekly recurrence, no-show marking, and the "queue position" display have **no
user interface yet**; the configurable approval rules table (`ApprovalRules`, `approval_rules_enabled`) and
`slot_policy = hold_pending` are not used by any code. They do no harm, but they do nothing until a screen / code is
added. Also unchanged: a database-level guard against two overlapping confirmed bookings.
