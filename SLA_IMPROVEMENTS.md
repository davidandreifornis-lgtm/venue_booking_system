# SLA, View Tracking & Expiry Improvements

## What was added

### Database (`database/13_sla_view_tracking.sql`)
Run this after `12_process_flow.sql` (or include it in a full reset).

New columns on `Bookings`:
- `StageEnteredAt` — SLA clock starts when the request enters the current stage (not original CreatedAt)
- `ManagerViewedAt` / `HrViewedAt` — first time the stage owner opens the details page
- `LastReminderAt` / `ReminderCount` — reminder bookkeeping
- `IsOverdue` — set when a stage exceeds its SLA

New / updated settings:
| Key | Default | Purpose |
|-----|---------|---------|
| manager_sla_days | 3 | Days before auto-escalate manager → HR |
| hr_sla_days | 3 | Days before flag + notify Administrator |
| reminder_interval_days | 1 | Days between reminders while waiting |
| revision_deadline_days | 3 | Auto-cancel if requester never resubmits |
| proposal_deadline_days | 3 | Auto-cancel unanswered reschedule proposals |
| urgent_event_days | 3 | Events within this window get a 1-day manager SLA |
| expire_undecided_enabled | 1 | Mark past-dated undecided bookings as Expired |
| sla_notify_manager_on_escalation | 1 | Tell the manager when their queue item is escalated |

### Behaviour
- **Silence never approves.** Past-dated undecided requests become `Expired` (slot freed).
- **Silence never blocks forever.** Revision / Reschedule Proposed get deadlines then auto-cancel.
- **SLA uses StageEnteredAt.** Resubmitting after a revision resets the clock.
- **Viewed is tracked, but does not pause the clock.** Reminders can say “not yet opened” vs “opened, awaiting decision”.
- **Manager is notified** when a request is auto-escalated.
- **Admin fallback** if there are no active HR users.
- **Reminders** at the configured interval to the current stage owner and the requester.
- **HR overdue** flags the booking and alerts Administrators (status stays Pending HR).

### API
- `POST /api/bookings/run-sla-jobs` (or `/bookings/run-sla`) — full job (reminders, escalations, expiries). Prefer a real scheduler every 15 minutes. Traffic still triggers a lightweight run at most once every 5 minutes as a fallback.
- Opening a booking via `GET /api/bookings/{id}` stamps ManagerViewedAt / HrViewedAt for the current stage owner.
- Booking payloads now include: `stageEnteredAt`, `managerViewedAt`, `hrViewedAt`, `waitingDays`, `isOverdue`, `reminderCount`.

### Recommended production setup
1. Apply `13_sla_view_tracking.sql`.
2. Schedule `POST /api/bookings/run-sla-jobs` every 15 minutes (Windows Task Scheduler or cron) using a service account that has `booking.decide` permission, or call it from an admin session.
3. Review the new settings under System Settings and adjust SLAs to your policy.

### Status flow additions
`Expired` is a terminal status reachable from any waiting state when the event date has passed or a deadline elapses.
