/*
  Seed data — Venue Booking System (SQL Server / Navicat)
  Demo logins (LOCAL / DEV ONLY — change or disable before production):
    admin    / Admin@2026!  -> Administrator
    hr       / Hr@2026!xx   -> HR
    manager  / Mgr@2026!x   -> Manager (Engineering)
    employee / Emp@2026!x   -> Employee (Engineering)
  Password policy in app: min 10 chars, letter + number.
*/

USE VenueBooking;
GO

/* RoleId values are fixed: 1=Employee 2=Manager 3=HR 4=Administrator */


SET NOCOUNT ON;

/* Roles */
MERGE dbo.Roles AS t
USING (VALUES
  (1, N'Employee',      N'Employee'),
  (2, N'Manager',       N'Manager'),
  (3, N'HR',            N'HR'),
  (4, N'Administrator', N'Administrator')
) AS s (RoleId, RoleCode, RoleName)
ON t.RoleId = s.RoleId
WHEN NOT MATCHED THEN
  INSERT (RoleId, RoleCode, RoleName) VALUES (s.RoleId, s.RoleCode, s.RoleName);
GO

/* Settings */
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
  INSERT (SettingKey, SettingValue, Description)
  VALUES (s.SettingKey, s.SettingValue, s.Description);
GO

/* Departments */
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Engineering')
  INSERT INTO dbo.Departments (Name) VALUES (N'Engineering');
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Human Resources')
  INSERT INTO dbo.Departments (Name) VALUES (N'Human Resources');
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Operations')
  INSERT INTO dbo.Departments (Name) VALUES (N'Operations');
GO

DECLARE @DeptEng INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Engineering');
DECLARE @DeptHR  INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Human Resources');
DECLARE @DeptOps INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Operations');

/* bcrypt hashes (PHP password_hash) for Admin@2026! / Hr@2026!xx / Mgr@2026!x / Emp@2026!x */
DECLARE @HAdmin    NVARCHAR(255) = N'$2y$10$F7gRImhVbTsCNEhEe8bFfOM4ZH4h0WGEZLoyAGxv5NfSXBDMjokFe';
DECLARE @HHr       NVARCHAR(255) = N'$2y$10$lVcfgbt4GW6nmCt9ZYFbpO7Y.lUJDba5zAsuR4QXXmMICkwx/dV.O';
DECLARE @HManager  NVARCHAR(255) = N'$2y$10$CwYOcPlMVBniVC9hgTOm1umWfEx2aPtKOyanriEYLz5ybtk5dR26S';
DECLARE @HEmployee NVARCHAR(255) = N'$2y$10$YjJ1/s9l9niYpE1cOCpMjOe4vVnPiJNJUX6ot8se9ip2sdy8bfNCy';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'admin@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'admin@company.local', @HAdmin, N'System Administrator', 4, NULL);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HAdmin WHERE Email = N'admin@company.local';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'hr@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'hr@company.local', @HHr, N'HR Officer', 3, @DeptHR);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HHr WHERE Email = N'hr@company.local';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'manager@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'manager@company.local', @HManager, N'Engineering Manager', 2, @DeptEng);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HManager WHERE Email = N'manager@company.local';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'employee@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'employee@company.local', @HEmployee, N'Engineering Employee', 1, @DeptEng);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HEmployee WHERE Email = N'employee@company.local';

/* Also allow short emails used by demo UI */
IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'admin')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'admin', @HAdmin, N'System Administrator', 4, NULL);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HAdmin WHERE Email = N'admin';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'hr')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'hr', @HHr, N'HR Officer', 3, @DeptHR);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HHr WHERE Email = N'hr';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'manager')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'manager', @HManager, N'Engineering Manager', 2, @DeptEng);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HManager WHERE Email = N'manager';

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'employee')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'employee', @HEmployee, N'Engineering Employee', 1, @DeptEng);
ELSE
  UPDATE dbo.Users SET PasswordHash = @HEmployee WHERE Email = N'employee';
GO

/* Assign Engineering manager */
UPDATE d
SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email IN (N'manager', N'manager@company.local') AND u.RoleId = 2
WHERE d.Name = N'Engineering' AND d.ManagerUserId IS NULL;
GO

/* Venues */
IF NOT EXISTS (SELECT 1 FROM dbo.Venues WHERE Name = N'Conference Room A')
  INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status)
  VALUES (N'Conference Room A', N'Building 1, Floor 2', 12, N'Projector,Whiteboard,Video Conference', N'Available');

IF NOT EXISTS (SELECT 1 FROM dbo.Venues WHERE Name = N'Conference Room B')
  INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status)
  VALUES (N'Conference Room B', N'Building 1, Floor 3', 20, N'Projector,Whiteboard', N'Available');

IF NOT EXISTS (SELECT 1 FROM dbo.Venues WHERE Name = N'Auditorium')
  INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status)
  VALUES (N'Auditorium', N'Building 2, Ground Floor', 100, N'Stage,PA System,Projector', N'Available');

IF NOT EXISTS (SELECT 1 FROM dbo.Venues WHERE Name = N'Meeting Pod 1')
  INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status)
  VALUES (N'Meeting Pod 1', N'Building 1, Floor 1', 4, N'TV Screen', N'Available');
GO

PRINT N'Seed complete.';
GO
