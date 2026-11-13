/*
  SLA, view-tracking, and expiry hardening — safe to re-run.
  NO GO separators (Navicat / DBeaver / ODBC friendly).
  Run after 12_process_flow.sql on database VenueBooking.
*/

USE VenueBooking;

SET NOCOUNT ON;

/* ---------- New columns on Bookings ---------- */
IF COL_LENGTH('dbo.Bookings', 'StageEnteredAt') IS NULL
  ALTER TABLE dbo.Bookings ADD StageEnteredAt DATETIME2(0) NULL;

IF COL_LENGTH('dbo.Bookings', 'ManagerViewedAt') IS NULL
  ALTER TABLE dbo.Bookings ADD ManagerViewedAt DATETIME2(0) NULL;

IF COL_LENGTH('dbo.Bookings', 'HrViewedAt') IS NULL
  ALTER TABLE dbo.Bookings ADD HrViewedAt DATETIME2(0) NULL;

IF COL_LENGTH('dbo.Bookings', 'LastReminderAt') IS NULL
  ALTER TABLE dbo.Bookings ADD LastReminderAt DATETIME2(0) NULL;

IF COL_LENGTH('dbo.Bookings', 'ReminderCount') IS NULL
  ALTER TABLE dbo.Bookings ADD ReminderCount INT NOT NULL CONSTRAINT DF_Bookings_ReminderCount DEFAULT (0);

IF COL_LENGTH('dbo.Bookings', 'IsOverdue') IS NULL
  ALTER TABLE dbo.Bookings ADD IsOverdue BIT NOT NULL CONSTRAINT DF_Bookings_IsOverdue DEFAULT (0);

/* Backfill StageEnteredAt so existing rows have a usable SLA clock */
IF COL_LENGTH('dbo.Bookings', 'StageEnteredAt') IS NOT NULL
BEGIN
  EXEC(N'
    UPDATE dbo.Bookings
    SET StageEnteredAt = COALESCE(ReachedHrAt, ValidatedAt, CreatedAt)
    WHERE StageEnteredAt IS NULL
      AND Status IN (
        N''Pending Manager Validation'',
        N''Pending HR Decision'',
        N''Manager Validated'',
        N''Revision Requested'',
        N''Reschedule Proposed''
      )
  ');
END;

/* ---------- Settings ---------- */
MERGE dbo.Settings AS t
USING (VALUES
  (N'manager_sla_days',           N'3',  N'Days a request may stay Pending Manager before auto-escalating to HR (0 = disabled)'),
  (N'hr_sla_days',                N'3',  N'Days a request may stay Pending HR before escalating to Administrator (0 = disabled)'),
  (N'reminder_interval_days',     N'1',  N'Days between reminder notifications while waiting on an approver'),
  (N'revision_deadline_days',     N'3',  N'Days the requester has to resubmit after Revision Requested before auto-cancel'),
  (N'proposal_deadline_days',     N'3',  N'Days the requester has to answer a Reschedule Proposed before auto-cancel and free the slot'),
  (N'urgent_event_days',          N'3',  N'If the event is within this many days, shorten manager SLA to 1 day'),
  (N'expire_undecided_enabled',   N'1',  N'1 = mark undecided bookings whose event date has passed as Expired'),
  (N'sla_notify_manager_on_escalation', N'1', N'1 = notify the original manager when their request is auto-escalated')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME())
WHEN MATCHED AND t.SettingValue IS NULL THEN
  UPDATE SET SettingValue = s.SettingValue, Description = s.Description, UpdatedAt = SYSUTCDATETIME();

PRINT N'13_sla_view_tracking.sql completed.';
