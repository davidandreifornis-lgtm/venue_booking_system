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

## Nice-to-have features (2026-10-20)

All additions are **additive**. Existing create / approve / validate / settings flows are unchanged unless the new tables exist and the related setting is enabled.

### 1. E-mail notifications
- `MailService` + extended `NotificationService`
- Settings: `email_enabled`, `email_from`, `email_smtp_*`
- Columns on `Notifications`: EmailStatus, EmailError, EmailSentAt
- Failures are written to the activity log (`email_failed`)

### 2. Reports & analytics
- New page `pages/reports.html` (HR + Administrator)
- API: `/reports/utilization`, `/reports/peak-hours`, `/reports/turnaround`, `/reports/status-summary`
- Permission: `reports.view`

### 3. Per-venue operating hours & blackouts
- Tables `VenueOperatingHours`, `VenueBlackouts`
- API: `/venues/{id}/hours`, `/venues/{id}/blackouts`, `DELETE /blackouts/{id}`
- `VenueScheduleService::validateSlot` is called from `BookingService::validatePayload` (skipped if tables missing)

### 4. Recurring bookings (weekly)
- Columns on `Bookings`: RecurrenceParentId, RecurrenceRule, IsRecurrenceInstance
- `RecurrenceService::expandSeries` + `POST /bookings/{id}/expand-recurrence`
- Rule example: `FREQ=WEEKLY;BYDAY=TU,TH;COUNT=8`

### 5. Calendar polish
- iCal export: `GET /calendar/ical?from=&to=`
- Find free rooms: `GET /venues/find-free?date=&start=&end=&capacity=`

### 6. Settings audit
- `Settings::set` now writes `settings.change` to the activity log with before/after values

### 7. Mobile UX
- Expanded `@media` rules in `shell.css` and `components.css` for sidebar, tables, modals, forms

### 8. Configurable approval rules (schema ready)
- Table `ApprovalRules` + setting `approval_rules_enabled` (default 0 = keep current hard-coded map)
- Seeded with Manager / HR / Admin rules matching existing behaviour

### Database
Run `database/11_nice_to_have.sql` after the existing 01–10 scripts.

## Process-flow hardening (2026-10-20)

Run `database/12_process_flow.sql` after 11.

### Slot policy = Option B (queue)
- Pending requests do **not** hold the venue.
- First request to **reach HR** (manager validation time, or create time for Manager-made requests) wins when HR approves.
- `GET /bookings/{id}/queue` returns position and competitors for transparency.
- Ordering uses `ReachedHrAt` (set when entering Pending HR).

### State machine
- `BookingWorkflow` defines allowed transitions; mutating actions call `assertTransition`.

### Manager stage
- Managers may only validate **their own department**.
- No active department manager → create goes straight to **Pending HR**.
- `manager_sla_days` (default 3): `POST /bookings/escalate-manager-sla` moves overdue Pending Manager → Pending HR.

### HR/Admin direct bookings
- Require `bypassReason` (or `reason`) when creating an already-Approved booking.

### Revision
- HR/Admin can request revision from Pending HR (not only Manager from Pending Manager).
- `max_revisions` (default 2) blocks further revision requests.

### Reschedule reject
- Default is **keep** original schedule and return to previous status (not auto-decline).
- Pass `rejectAction: "withdraw"` to cancel instead.

### Lifecycle
- `POST /bookings/complete-past` → Approved/Rescheduled past end time become **Completed**.
- `POST /bookings/{id}/no-show` → **No-Show** (HR/Admin).
- User deactivate reassigns open Pending Manager items to HR and clears department manager link.

### Approve race
- Clearer error when another HR already decided (includes status / actor when available).
