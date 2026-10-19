/*
  Competing requests + emergency override. Safe to re-run. Run after 01-09.
  No GO separators, so it works in SSMS, Azure Data Studio, DBeaver, HeidiSQL or any ODBC tool.

  conflict_auto_action:
    reschedule = when HR approves a booking, other requests waiting for HR at an overlapping time are moved to the
                 nearest free slot (requester accepts/rejects); declined if no slot is free in the next 7 days.
    decline    = those requests are declined straight away.

  No table changes are needed: the emergency override reuses the existing Cancelled / Rescheduled statuses.
  (The app also falls back to 'reschedule' if this row is missing, so running this script is optional.)
*/
USE VenueBooking;

MERGE dbo.Settings AS t
USING (VALUES
  (N'conflict_auto_action', N'reschedule', N'When HR approves a booking, competing pending requests for the same time are: reschedule (nearest free slot, decline if none) or decline')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME());
