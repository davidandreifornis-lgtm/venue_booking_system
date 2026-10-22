/*
  Nice-to-have features migration — safe to re-run.
  NO GO separators (works in Navicat, DBeaver, HeidiSQL, ODBC, sqlcmd -i).
  Run after 01–10 scripts on database VenueBooking.
*/

USE VenueBooking;

SET NOCOUNT ON;

/* ---------- Notifications: email delivery ---------- */
IF COL_LENGTH('dbo.Notifications', 'EmailStatus') IS NULL
  ALTER TABLE dbo.Notifications ADD EmailStatus NVARCHAR(32) NULL;
  -- NULL | pending | sent | failed | skipped

IF COL_LENGTH('dbo.Notifications', 'EmailError') IS NULL
  ALTER TABLE dbo.Notifications ADD EmailError NVARCHAR(500) NULL;

IF COL_LENGTH('dbo.Notifications', 'EmailSentAt') IS NULL
  ALTER TABLE dbo.Notifications ADD EmailSentAt DATETIME2(0) NULL;

/* ---------- Bookings: recurrence ---------- */
IF COL_LENGTH('dbo.Bookings', 'RecurrenceParentId') IS NULL
  ALTER TABLE dbo.Bookings ADD RecurrenceParentId INT NULL;

IF COL_LENGTH('dbo.Bookings', 'RecurrenceRule') IS NULL
  ALTER TABLE dbo.Bookings ADD RecurrenceRule NVARCHAR(255) NULL;

IF COL_LENGTH('dbo.Bookings', 'IsRecurrenceInstance') IS NULL
  ALTER TABLE dbo.Bookings ADD IsRecurrenceInstance BIT NOT NULL CONSTRAINT DF_Bookings_IsRecurrenceInstance DEFAULT (0);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_Bookings_RecurrenceParent')
  AND COL_LENGTH('dbo.Bookings', 'RecurrenceParentId') IS NOT NULL
BEGIN
  EXEC(N'ALTER TABLE dbo.Bookings ADD CONSTRAINT FK_Bookings_RecurrenceParent
        FOREIGN KEY (RecurrenceParentId) REFERENCES dbo.Bookings(BookingId)');
END;

/* ---------- Venue operating hours (weekly) ---------- */
IF OBJECT_ID(N'dbo.VenueOperatingHours', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.VenueOperatingHours (
    VenueHoursId   INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    VenueId        INT            NOT NULL,
    DayOfWeek      TINYINT        NOT NULL,
    OpenTime       TIME(0)        NOT NULL,
    CloseTime      TIME(0)        NOT NULL,
    IsClosed       BIT            NOT NULL CONSTRAINT DF_VenueHours_IsClosed DEFAULT (0),
    CONSTRAINT FK_VenueHours_Venue FOREIGN KEY (VenueId) REFERENCES dbo.Venues(VenueId),
    CONSTRAINT UQ_VenueHours_Venue_Day UNIQUE (VenueId, DayOfWeek),
    CONSTRAINT CK_VenueHours_Time CHECK (CloseTime > OpenTime OR IsClosed = 1)
  );
END;

/* ---------- Venue blackout / maintenance windows ---------- */
IF OBJECT_ID(N'dbo.VenueBlackouts', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.VenueBlackouts (
    BlackoutId     INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    VenueId        INT            NOT NULL,
    StartAt        DATETIME2(0)   NOT NULL,
    EndAt          DATETIME2(0)   NOT NULL,
    Reason         NVARCHAR(255)  NULL,
    CreatedByUserId INT           NULL,
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_VenueBlackouts_CreatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT FK_VenueBlackouts_Venue FOREIGN KEY (VenueId) REFERENCES dbo.Venues(VenueId),
    CONSTRAINT FK_VenueBlackouts_User FOREIGN KEY (CreatedByUserId) REFERENCES dbo.Users(UserId),
    CONSTRAINT CK_VenueBlackouts_Range CHECK (EndAt > StartAt)
  );
  CREATE NONCLUSTERED INDEX IX_VenueBlackouts_Venue_Range
    ON dbo.VenueBlackouts (VenueId, StartAt, EndAt);
END;

/* ---------- Configurable approval rules ---------- */
IF OBJECT_ID(N'dbo.ApprovalRules', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.ApprovalRules (
    RuleId         INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    Name           NVARCHAR(120)  NOT NULL,
    RoleCode       NVARCHAR(32)   NOT NULL,
    Stage          NVARCHAR(64)   NOT NULL,
    DepartmentId   INT            NULL,
    MinAttendees   INT            NULL,
    MaxAttendees   INT            NULL,
    IsActive       BIT            NOT NULL CONSTRAINT DF_ApprovalRules_IsActive DEFAULT (1),
    SortOrder      INT            NOT NULL CONSTRAINT DF_ApprovalRules_SortOrder DEFAULT (0),
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_ApprovalRules_CreatedAt DEFAULT (SYSUTCDATETIME()),
    UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_ApprovalRules_UpdatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT FK_ApprovalRules_Department FOREIGN KEY (DepartmentId) REFERENCES dbo.Departments(DepartmentId)
  );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.ApprovalRules)
BEGIN
  INSERT INTO dbo.ApprovalRules (Name, RoleCode, Stage, DepartmentId, SortOrder)
  VALUES
    (N'Manager validates department requests', N'Manager', N'Pending Manager Validation', NULL, 10),
    (N'HR decides after manager', N'HR', N'Pending HR Decision', NULL, 20),
    (N'Admin can decide any stage', N'Administrator', N'Pending Manager Validation', NULL, 5),
    (N'Admin can decide any stage', N'Administrator', N'Pending HR Decision', NULL, 5);
END;

/* ---------- Settings for new features ---------- */
MERGE dbo.Settings AS t
USING (VALUES
  (N'email_enabled',           N'0',     N'1 = attempt to send e-mail for notifications'),
  (N'email_from',              N'noreply@company.local', N'From address for notification e-mails'),
  (N'email_smtp_host',         N'',      N'SMTP host (empty = use PHP mail())'),
  (N'email_smtp_port',         N'587',   N'SMTP port'),
  (N'email_smtp_user',         N'',      N'SMTP username'),
  (N'email_smtp_pass',         N'',      N'SMTP password (store securely)'),
  (N'recurring_max_count',     N'52',    N'Maximum instances generated for a recurring series'),
  (N'approval_rules_enabled',  N'0',     N'1 = use ApprovalRules table instead of hard-coded role map'),
  (N'venue_hours_enabled',     N'1',     N'1 = enforce per-venue operating hours when set')
) AS s (SettingKey, SettingValue, Description)
ON t.SettingKey = s.SettingKey
WHEN NOT MATCHED THEN
  INSERT (SettingKey, SettingValue, Description, UpdatedAt)
  VALUES (s.SettingKey, s.SettingValue, s.Description, SYSUTCDATETIME());

/* Utilization view via dynamic SQL so CREATE VIEW is not blocked by prior statements */
IF OBJECT_ID(N'dbo.vw_BookingUtilization', N'V') IS NOT NULL
  EXEC(N'DROP VIEW dbo.vw_BookingUtilization');

EXEC(N'
CREATE VIEW dbo.vw_BookingUtilization AS
SELECT
  b.BookingId,
  b.VenueId,
  v.Name AS VenueName,
  b.DepartmentId,
  d.Name AS DepartmentName,
  b.BookingDate,
  b.StartTime,
  b.EndTime,
  DATEDIFF(MINUTE, b.StartTime, b.EndTime) AS DurationMinutes,
  b.Status,
  b.Attendees,
  b.RequesterUserId,
  b.CreatedAt,
  b.UpdatedAt
FROM dbo.Bookings b
INNER JOIN dbo.Venues v ON v.VenueId = b.VenueId
LEFT JOIN dbo.Departments d ON d.DepartmentId = b.DepartmentId
WHERE b.Status IN (N''Approved'', N''Rescheduled'', N''Completed'')
');

PRINT N'11_nice_to_have.sql completed.';
