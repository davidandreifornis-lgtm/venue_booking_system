/*
  Process-flow hardening - safe to re-run.
  NO GO separators (Navicat / DBeaver / ODBC friendly).
  Run after 11_nice_to_have.sql on database VenueBooking.
*/

USE VenueBooking;

SET NOCOUNT ON;

IF COL_LENGTH('dbo.Bookings', 'ReachedHrAt') IS NULL
  ALTER TABLE dbo.Bookings ADD ReachedHrAt DATETIME2(0) NULL;

IF COL_LENGTH('dbo.Bookings', 'RevisionCount') IS NULL
  ALTER TABLE dbo.Bookings ADD RevisionCount INT NOT NULL CONSTRAINT DF_Bookings_RevisionCount DEFAULT (0);

IF COL_LENGTH('dbo.Bookings', 'BypassReason') IS NULL
  ALTER TABLE dbo.Bookings ADD BypassReason NVARCHAR(500) NULL;

IF COL_LENGTH('dbo.Bookings', 'RowVersion') IS NULL
  ALTER TABLE dbo.Bookings ADD RowVersion INT NOT NULL CONSTRAINT DF_Bookings_RowVersion DEFAULT (1);

/* Backfill via dynamic SQL so the new column is visible in the same batch */
IF COL_LENGTH('dbo.Bookings', 'ReachedHrAt') IS NOT NULL
BEGIN
  EXEC(N'
    UPDATE dbo.Bookings
    SET ReachedHrAt = COALESCE(ValidatedAt, CreatedAt)
    WHERE Status IN (N''Pending HR Decision'', N''Manager Validated'')
      AND ReachedHrAt IS NULL
  ');
END;

MERGE dbo.Settings AS t
USING (VALUES
  (N'manager_sla_days',      N'3',     N'Days a request may stay Pending Manager before auto-escalating to HR (0 = disabled)'),
  (N'max_revisions',         N'2',     N'Maximum revision cycles before a final decision is required'),
  (N'slot_policy',           N'queue', N'Slot policy: queue = first to reach HR wins (no pending hold); hold_pending = pending also blocks'),
  (N'auto_complete_enabled', N'1',     N'1 = Approved/Rescheduled past end time become Completed on next complete-past call')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME());

UPDATE dbo.Settings
SET SettingValue = N'0', UpdatedAt = SYSUTCDATETIME()
WHERE SettingKey = N'overlap_include_pending' AND SettingValue <> N'0';

PRINT N'12_process_flow.sql completed.';
