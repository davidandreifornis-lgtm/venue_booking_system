/*
  Equipment (Additional requirements) rules. Safe to re-run. Run after 01-08.
  No GO separators, so it works in SSMS, Azure Data Studio, DBeaver, HeidiSQL or any ODBC tool.

  StockScope:
    Shared   = one pool for the whole company (portable gear: projector, microphones).
               A projector used in Room A is unavailable for Room B at the same time.
    PerVenue = TotalStock is the count IN EACH venue (built-in gear: chairs, TV screen).
*/
USE VenueBooking;

-- Dynamic SQL (EXEC) lets everything run as ONE batch even though the column is created in this script.
IF COL_LENGTH('dbo.RequirementOptions', 'StockScope') IS NULL
  EXEC(N'ALTER TABLE dbo.RequirementOptions ADD StockScope NVARCHAR(16) NOT NULL CONSTRAINT DF_ReqOpt_Scope DEFAULT (N''Shared'')');

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_ReqOpt_Scope')
  EXEC(N'ALTER TABLE dbo.RequirementOptions ADD CONSTRAINT CK_ReqOpt_Scope CHECK (StockScope IN (N''Shared'', N''PerVenue''))');

MERGE dbo.Settings AS t
USING (VALUES
  (N'equipment_buffer_minutes',  N'30', N'Turnaround time (minutes) needed before shared equipment can be used again'),
  (N'equipment_reserve_pending', N'0',  N'1 = pending requests also reserve equipment stock (0 = only Approved bookings hold equipment)')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME());

-- Only approved bookings hold equipment: switch off any earlier default of 1.
UPDATE dbo.Settings SET SettingValue = N'0', UpdatedAt = SYSUTCDATETIME()
WHERE SettingKey = N'equipment_reserve_pending' AND SettingValue = N'1';

PRINT N'Equipment rules ready.';
