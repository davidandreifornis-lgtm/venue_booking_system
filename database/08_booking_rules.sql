/*
  Booking rules for the calendar booking UI. Safe to re-run: it only INSERTS
  missing keys and never overwrites values the admin already changed.
  Run after 01-07.
*/
USE VenueBooking;
GO

MERGE dbo.Settings AS t
USING (VALUES
  (N'booking_lead_days',       N'2',     N'Minimum days in advance a Employee/Manager must book'),
  (N'booking_buffer_minutes',  N'30',    N'Gap kept free before and after every booking (minutes)'),
  (N'slot_step_minutes',       N'30',    N'Booking time step: 30 = hours and half-hours, 60 = whole hours only'),
  (N'min_duration_minutes',    N'30',    N'Minimum booking duration (minutes)'),
  (N'max_duration_minutes',    N'480',   N'Maximum booking duration (minutes)'),
  (N'booking_window_days',     N'90',    N'Max days ahead for a booking'),
  (N'operating_hours_start',   N'08:00', N'Earliest start time'),
  (N'operating_hours_end',     N'17:00', N'Latest end time'),
  (N'overlap_include_pending', N'0',     N'1 = pending requests also block the venue'),
  (N'reminder_hours_before',   N'24',    N'Reminder hours before event')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME());
GO

/* Company hours are 8:00 AM - 5:00 PM: move any old 18:00 closing time. */
UPDATE dbo.Settings SET SettingValue = N'17:00', UpdatedAt = SYSUTCDATETIME()
WHERE SettingKey = N'operating_hours_end' AND SettingValue = N'18:00';
GO

PRINT N'Booking rules settings ready.';
GO
