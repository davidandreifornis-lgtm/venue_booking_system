# Changes in this update

## New behaviour

### 1. Competing requests are handled automatically
Several requests may wait for HR at the same time. **HR still gives the final verdict.** When HR approves one:

* every other request that overlaps it (buffer included) and is waiting for HR is handled automatically, oldest
  "reached HR" first;
* **Moved** (default): the request gets the nearest free slot (same venue, same length, closest to the original
  time on the same day, then the following 7 days). It goes to the requester as a *Reschedule Proposed*; they
  accept (booking confirmed at the new time) or reject (booking declined). If no slot is free it is **declined**
  with an explanation.
* **Declined**: the request is declined straight away.

Admin chooses between the two in **Settings → Booking rules → "When HR approves a booking, competing requests…"**
(setting `conflict_auto_action`).

A request whose manager validates it *after* the slot is already confirmed is handled the same way immediately,
instead of waiting in HR's queue with a guaranteed conflict error.

"First manager-approved goes first": HR's pending list is now sorted by the time each request reached HR
(manager validation time, or creation time for Manager-made requests), shows a **Reached HR** column and a
**Same-time requests** column ("Competes with VB-…, reached HR first"). The Approve dialog lists exactly which
requests will be handled and warns when another request reached HR before this one. HR can still approve either.

### 2. Emergency override of an approved booking (HR and Administrator)
On the booking page, an approved / rescheduled booking now has an **Emergency override** button:

* **Move it to another time** – you pick date/start/end, or leave them empty for the nearest free slot. The move
  is immediate (the requester does not have to accept) and the old slot is free right away.
* **Cancel it** – immediate.

A written reason is mandatory. The requester and the department manager are notified, the reason is stored in
the booking history and activity log (`booking.emergency_override`), and you cannot override your own booking.
After overriding, HR can book the freed slot directly as usual.

## Fixes made along the way
* `BookingService::approve()` is now one atomic step (conflict check + stock check + status change in a
  transaction, guarded by the current status). Two HR users can no longer confirm the same slot, or decide the
  same request, at the same instant.
* Booking page: the requester's **Accept / Reject new schedule** and **Edit & resubmit** buttons were never
  shown (the Actions panel only appeared for HR/Manager). They now appear, which the automatic reschedule needs.
* Booking page now shows the cancellation reason.

## Files changed / added
Backend: `backend/lib/BookingService.php`, `backend/lib/Permissions.php` (new `booking.override`),
`backend/lib/Settings.php`, `backend/api/index.php` (routes `POST /bookings/{id}/emergency-override`,
`GET /bookings/{id}/competing`, new setting validation).
Frontend: `js/api.js`, `pages/booking-details.html`, `pages/pending-requests.html`, `pages/settings.html`.
Database: `database/10_conflict_rules.sql` (optional – only adds the setting row; the app falls back to
`reschedule` without it). No table changes.
Tests: `tests/ConflictResolutionTest.php` (11 tests), `tests/README.md` (script list).

## How to install the update
1. Copy these files over your project (or unzip over it). Nothing else in your project was touched.
2. Optional: run `database/10_conflict_rules.sql` in SSMS.
3. Hard-refresh the browser (Ctrl+F5) so the updated scripts load.

## Not changed (still open from FINDINGS.md)
The database password in `backend/config/*.php` and git history, debug mode, public health endpoints, demo
accounts, the Manager `scope=all` listing, unescaped `purpose` on the employee dashboard, email delivery,
reports and backup procedure were **not** part of this request and are unchanged.

## Verification status
This update was written without PHP or SQL Server available, so **none of it has been executed**. The JavaScript
was syntax-checked with Node and the PHP was checked for balanced structure only. Run
`vendor/bin/phpunit tests/ConflictResolutionTest.php` against your `_Test` database before deploying.
