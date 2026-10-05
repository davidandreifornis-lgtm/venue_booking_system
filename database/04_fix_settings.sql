/*
  Fix empty Settings table — required for booking validation rules.
  Run this once on VenueBooking if Settings is empty.
*/

USE VenueBooking;
GO

SET NOCOUNT ON;

MERGE dbo.Settings AS t
USING (VALUES
  (N'min_duration_minutes',    N'30',    N'Minimum booking duration (minutes)'),
  (N'max_duration_minutes',    N'480',   N'Maximum booking duration (minutes)'),
  (N'booking_window_days',     N'90',    N'Max days ahead for a booking'),
  (N'operating_hours_start',   N'08:00', N'Earliest start time'),
  (N'operating_hours_end',     N'18:00', N'Latest end time'),
  (N'overlap_include_pending', N'0',     N'1 = pending also blocks venue'),
  (N'reminder_hours_before',   N'24',    N'Reminder hours before event')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME())
WHEN MATCHED THEN
  UPDATE SET SettingValue = s.SettingValue, Description = s.Description, UpdatedAt = SYSUTCDATETIME();
GO

-- Ensure department managers are linked (safe if already set)
UPDATE d
SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email IN (N'manager', N'manager@company.local') AND u.RoleId = 2
WHERE d.Name = N'Engineering' AND (d.ManagerUserId IS NULL OR d.ManagerUserId <> u.UserId);

UPDATE d
SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email = N'david@company.local' AND u.RoleId = 2
WHERE d.Name = N'Operations' AND (d.ManagerUserId IS NULL OR d.ManagerUserId <> u.UserId);
GO

PRINT N'Settings and department managers fixed.';
GO
