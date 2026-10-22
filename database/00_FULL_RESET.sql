/*
  =============================================================================
  Venue Booking System — FULL RESET (schema + seed)
  =============================================================================
  Replaces scripts 01–12 in one paste.

  - Drops existing objects (if any), then creates a clean schema
  - No GO batch separators (works in Navicat / DBeaver / HeidiSQL / ODBC)
  - Database must already exist: CREATE DATABASE VenueBooking;  (once)

  Demo logins (password = same as login name):
    admin    / admin
    hr       / hr
    manager  / manager
    employee / employee
  =============================================================================
*/

USE VenueBooking;

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* -------------------------------------------------------------------------- */
/* 1) DROP (child → parent)                                                   */
/* -------------------------------------------------------------------------- */

IF OBJECT_ID(N'dbo.vw_BookingUtilization', N'V') IS NOT NULL
  DROP VIEW dbo.vw_BookingUtilization;

IF OBJECT_ID(N'dbo.BookingRequirements', N'U') IS NOT NULL
  DROP TABLE dbo.BookingRequirements;

IF OBJECT_ID(N'dbo.BookingStatusHistory', N'U') IS NOT NULL
  DROP TABLE dbo.BookingStatusHistory;

IF OBJECT_ID(N'dbo.Notifications', N'U') IS NOT NULL
  DROP TABLE dbo.Notifications;

IF OBJECT_ID(N'dbo.ActivityLogs', N'U') IS NOT NULL
  DROP TABLE dbo.ActivityLogs;

IF OBJECT_ID(N'dbo.UserSessions', N'U') IS NOT NULL
  DROP TABLE dbo.UserSessions;

IF OBJECT_ID(N'dbo.VenueBlackouts', N'U') IS NOT NULL
  DROP TABLE dbo.VenueBlackouts;

IF OBJECT_ID(N'dbo.VenueOperatingHours', N'U') IS NOT NULL
  DROP TABLE dbo.VenueOperatingHours;

IF OBJECT_ID(N'dbo.ApprovalRules', N'U') IS NOT NULL
  DROP TABLE dbo.ApprovalRules;

IF OBJECT_ID(N'dbo.Bookings', N'U') IS NOT NULL
  DROP TABLE dbo.Bookings;

IF OBJECT_ID(N'dbo.RequirementOptions', N'U') IS NOT NULL
  DROP TABLE dbo.RequirementOptions;

IF OBJECT_ID(N'dbo.Venues', N'U') IS NOT NULL
  DROP TABLE dbo.Venues;

/* Break circular FK Departments.ManagerUserId ↔ Users */
IF OBJECT_ID(N'dbo.Departments', N'U') IS NOT NULL
  AND EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_Departments_Manager')
  ALTER TABLE dbo.Departments DROP CONSTRAINT FK_Departments_Manager;

IF OBJECT_ID(N'dbo.Users', N'U') IS NOT NULL
  DROP TABLE dbo.Users;

IF OBJECT_ID(N'dbo.Departments', N'U') IS NOT NULL
  DROP TABLE dbo.Departments;

IF OBJECT_ID(N'dbo.Roles', N'U') IS NOT NULL
  DROP TABLE dbo.Roles;

IF OBJECT_ID(N'dbo.Settings', N'U') IS NOT NULL
  DROP TABLE dbo.Settings;

PRINT N'Drop complete.';

/* -------------------------------------------------------------------------- */
/* 2) CREATE SCHEMA                                                           */
/* -------------------------------------------------------------------------- */

CREATE TABLE dbo.Roles (
  RoleId      INT            NOT NULL PRIMARY KEY,
  RoleCode    NVARCHAR(32)   NOT NULL UNIQUE,
  RoleName    NVARCHAR(64)   NOT NULL
);

CREATE TABLE dbo.Departments (
  DepartmentId   INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  Name           NVARCHAR(120)  NOT NULL,
  ManagerUserId  INT            NULL,
  IsActive       BIT            NOT NULL CONSTRAINT DF_Departments_IsActive DEFAULT (1),
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Departments_CreatedAt DEFAULT (SYSUTCDATETIME()),
  UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Departments_UpdatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_Departments_Name UNIQUE (Name)
);

CREATE TABLE dbo.Users (
  UserId         INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  Email          NVARCHAR(255)  NOT NULL,
  PasswordHash   NVARCHAR(255)  NOT NULL,
  FullName       NVARCHAR(150)  NOT NULL,
  RoleId         INT            NOT NULL,
  DepartmentId   INT            NULL,
  IsActive       BIT            NOT NULL CONSTRAINT DF_Users_IsActive DEFAULT (1),
  LastLoginAt    DATETIME2(0)   NULL,
  FailedLogins   INT            NOT NULL CONSTRAINT DF_Users_FailedLogins DEFAULT (0),
  LockedUntil    DATETIME2(0)   NULL,
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Users_CreatedAt DEFAULT (SYSUTCDATETIME()),
  UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Users_UpdatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_Users_Email UNIQUE (Email),
  CONSTRAINT FK_Users_Role FOREIGN KEY (RoleId) REFERENCES dbo.Roles(RoleId),
  CONSTRAINT FK_Users_Department FOREIGN KEY (DepartmentId) REFERENCES dbo.Departments(DepartmentId)
);

ALTER TABLE dbo.Departments
  ADD CONSTRAINT FK_Departments_Manager
  FOREIGN KEY (ManagerUserId) REFERENCES dbo.Users(UserId);

CREATE TABLE dbo.Venues (
  VenueId        INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  Name           NVARCHAR(150)  NOT NULL,
  Location       NVARCHAR(255)  NULL,
  Capacity       INT            NOT NULL,
  Equipment      NVARCHAR(500)  NULL,
  Status         NVARCHAR(32)   NOT NULL CONSTRAINT DF_Venues_Status DEFAULT (N'Available'),
  IsActive       BIT            NOT NULL CONSTRAINT DF_Venues_IsActive DEFAULT (1),
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Venues_CreatedAt DEFAULT (SYSUTCDATETIME()),
  UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Venues_UpdatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_Venues_Name UNIQUE (Name),
  CONSTRAINT CK_Venues_Capacity CHECK (Capacity > 0),
  CONSTRAINT CK_Venues_Status CHECK (Status IN (N'Available', N'Unavailable', N'Maintenance'))
);

CREATE TABLE dbo.Bookings (
  BookingId              INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  ReferenceCode          NVARCHAR(32)   NOT NULL,
  VenueId                INT            NOT NULL,
  RequesterUserId        INT            NOT NULL,
  DepartmentId           INT            NULL,
  DepartmentManagerId    INT            NULL,
  BookingDate            DATE           NOT NULL,
  StartTime              TIME(0)        NOT NULL,
  EndTime                TIME(0)        NOT NULL,
  Purpose                NVARCHAR(500)  NOT NULL,
  EventType              NVARCHAR(100)  NULL,
  Attendees              INT            NOT NULL CONSTRAINT DF_Bookings_Attendees DEFAULT (1),
  AdditionalRequirements NVARCHAR(1000) NULL,
  Status                 NVARCHAR(64)   NOT NULL,
  ManagerNotes           NVARCHAR(1000) NULL,
  HrNotes                NVARCHAR(1000) NULL,
  CancelReason           NVARCHAR(1000) NULL,
  DeclineReason          NVARCHAR(1000) NULL,
  ValidatedAt            DATETIME2(0)   NULL,
  ValidatedByUserId      INT            NULL,
  DecidedAt              DATETIME2(0)   NULL,
  DecidedByUserId        INT            NULL,
  CancelledAt            DATETIME2(0)   NULL,
  CancelledByUserId      INT            NULL,
  /* Reschedule proposal (from 05_workflow) */
  ProposedVenueId        INT            NULL,
  ProposedDate           DATE           NULL,
  ProposedStartTime      TIME(0)        NULL,
  ProposedEndTime        TIME(0)        NULL,
  ProposalNotes          NVARCHAR(1000) NULL,
  StatusBeforeProposal   NVARCHAR(64)   NULL,
  /* Recurrence (from 11) */
  RecurrenceParentId     INT            NULL,
  RecurrenceRule         NVARCHAR(255)  NULL,
  IsRecurrenceInstance   BIT            NOT NULL CONSTRAINT DF_Bookings_IsRecurrenceInstance DEFAULT (0),
  /* Process flow (from 12) */
  ReachedHrAt            DATETIME2(0)   NULL,
  RevisionCount          INT            NOT NULL CONSTRAINT DF_Bookings_RevisionCount DEFAULT (0),
  BypassReason           NVARCHAR(500)  NULL,
  RowVersion             INT            NOT NULL CONSTRAINT DF_Bookings_RowVersion DEFAULT (1),
  CreatedAt              DATETIME2(0)   NOT NULL CONSTRAINT DF_Bookings_CreatedAt DEFAULT (SYSUTCDATETIME()),
  UpdatedAt              DATETIME2(0)   NOT NULL CONSTRAINT DF_Bookings_UpdatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_Bookings_Reference UNIQUE (ReferenceCode),
  CONSTRAINT FK_Bookings_Venue FOREIGN KEY (VenueId) REFERENCES dbo.Venues(VenueId),
  CONSTRAINT FK_Bookings_Requester FOREIGN KEY (RequesterUserId) REFERENCES dbo.Users(UserId),
  CONSTRAINT FK_Bookings_Department FOREIGN KEY (DepartmentId) REFERENCES dbo.Departments(DepartmentId),
  CONSTRAINT FK_Bookings_DeptManager FOREIGN KEY (DepartmentManagerId) REFERENCES dbo.Users(UserId),
  CONSTRAINT FK_Bookings_RecurrenceParent FOREIGN KEY (RecurrenceParentId) REFERENCES dbo.Bookings(BookingId),
  CONSTRAINT CK_Bookings_Time CHECK (EndTime > StartTime),
  CONSTRAINT CK_Bookings_Attendees CHECK (Attendees >= 1)
);

CREATE NONCLUSTERED INDEX IX_Bookings_Venue_Date
  ON dbo.Bookings (VenueId, BookingDate)
  INCLUDE (StartTime, EndTime, Status);

CREATE NONCLUSTERED INDEX IX_Bookings_Requester
  ON dbo.Bookings (RequesterUserId, Status);

CREATE NONCLUSTERED INDEX IX_Bookings_Status
  ON dbo.Bookings (Status);

CREATE NONCLUSTERED INDEX IX_Bookings_ReachedHr
  ON dbo.Bookings (VenueId, BookingDate, ReachedHrAt)
  INCLUDE (Status, StartTime, EndTime);

CREATE TABLE dbo.BookingStatusHistory (
  HistoryId        INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  BookingId        INT            NOT NULL,
  FromStatus       NVARCHAR(64)   NULL,
  ToStatus         NVARCHAR(64)   NOT NULL,
  ChangedByUserId  INT            NULL,
  Reason           NVARCHAR(1000) NULL,
  ChangedAt        DATETIME2(0)   NOT NULL CONSTRAINT DF_BookingHistory_ChangedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT FK_BookingHistory_Booking FOREIGN KEY (BookingId) REFERENCES dbo.Bookings(BookingId),
  CONSTRAINT FK_BookingHistory_User FOREIGN KEY (ChangedByUserId) REFERENCES dbo.Users(UserId)
);

CREATE TABLE dbo.Notifications (
  NotificationId INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  UserId         INT            NOT NULL,
  Title          NVARCHAR(200)  NOT NULL,
  Body           NVARCHAR(1000) NULL,
  LinkUrl        NVARCHAR(255)  NULL,
  IsRead         BIT            NOT NULL CONSTRAINT DF_Notifications_IsRead DEFAULT (0),
  EmailStatus    NVARCHAR(32)   NULL,
  EmailError     NVARCHAR(500)  NULL,
  EmailSentAt    DATETIME2(0)   NULL,
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Notifications_CreatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT FK_Notifications_User FOREIGN KEY (UserId) REFERENCES dbo.Users(UserId)
);

CREATE NONCLUSTERED INDEX IX_Notifications_User_Read
  ON dbo.Notifications (UserId, IsRead, CreatedAt DESC);

CREATE TABLE dbo.ActivityLogs (
  LogId          BIGINT         IDENTITY(1,1) NOT NULL PRIMARY KEY,
  ActorUserId    INT            NULL,
  ActorEmail     NVARCHAR(255)  NULL,
  Action         NVARCHAR(100)  NOT NULL,
  EntityType     NVARCHAR(50)   NULL,
  EntityId       NVARCHAR(50)   NULL,
  Detail         NVARCHAR(MAX)  NULL,
  IpAddress      NVARCHAR(45)   NULL,
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_ActivityLogs_CreatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT FK_ActivityLogs_User FOREIGN KEY (ActorUserId) REFERENCES dbo.Users(UserId)
);

CREATE NONCLUSTERED INDEX IX_ActivityLogs_Created
  ON dbo.ActivityLogs (CreatedAt DESC);

CREATE TABLE dbo.Settings (
  SettingKey     NVARCHAR(100)  NOT NULL PRIMARY KEY,
  SettingValue   NVARCHAR(500)  NOT NULL,
  Description    NVARCHAR(255)  NULL,
  UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Settings_UpdatedAt DEFAULT (SYSUTCDATETIME())
);

CREATE TABLE dbo.UserSessions (
  SessionId      NVARCHAR(128)  NOT NULL PRIMARY KEY,
  UserId         INT            NOT NULL,
  CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_UserSessions_CreatedAt DEFAULT (SYSUTCDATETIME()),
  ExpiresAt      DATETIME2(0)   NOT NULL,
  LastSeenAt     DATETIME2(0)   NOT NULL CONSTRAINT DF_UserSessions_LastSeen DEFAULT (SYSUTCDATETIME()),
  IpAddress      NVARCHAR(45)   NULL,
  UserAgent      NVARCHAR(255)  NULL,
  CONSTRAINT FK_UserSessions_User FOREIGN KEY (UserId) REFERENCES dbo.Users(UserId)
);

CREATE TABLE dbo.RequirementOptions (
  OptionId     INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
  Name         NVARCHAR(150) NOT NULL,
  Description  NVARCHAR(300) NULL,
  MaxQuantity  INT NOT NULL CONSTRAINT DF_ReqOpt_Max DEFAULT (1),
  SortOrder    INT NOT NULL CONSTRAINT DF_ReqOpt_Sort DEFAULT (0),
  IsActive     BIT NOT NULL CONSTRAINT DF_ReqOpt_Active DEFAULT (1),
  TotalStock   INT NULL,
  StockScope   NVARCHAR(16) NOT NULL CONSTRAINT DF_ReqOpt_Scope DEFAULT (N'Shared'),
  CreatedAt    DATETIME2(0) NOT NULL CONSTRAINT DF_ReqOpt_Created DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_ReqOpt_Name UNIQUE (Name),
  CONSTRAINT CK_ReqOpt_Max CHECK (MaxQuantity >= 1),
  CONSTRAINT CK_ReqOpt_Scope CHECK (StockScope IN (N'Shared', N'PerVenue'))
);

CREATE TABLE dbo.BookingRequirements (
  BookingRequirementId INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
  BookingId  INT NOT NULL,
  OptionId   INT NOT NULL,
  OptionName NVARCHAR(150) NOT NULL,
  Quantity   INT NOT NULL CONSTRAINT DF_BookReq_Qty DEFAULT (1),
  CONSTRAINT FK_BookReq_Booking FOREIGN KEY (BookingId) REFERENCES dbo.Bookings(BookingId),
  CONSTRAINT FK_BookReq_Option FOREIGN KEY (OptionId) REFERENCES dbo.RequirementOptions(OptionId),
  CONSTRAINT UQ_BookReq UNIQUE (BookingId, OptionId),
  CONSTRAINT CK_BookReq_Qty CHECK (Quantity >= 1)
);

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

CREATE TABLE dbo.VenueBlackouts (
  BlackoutId      INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
  VenueId         INT            NOT NULL,
  StartAt         DATETIME2(0)   NOT NULL,
  EndAt           DATETIME2(0)   NOT NULL,
  Reason          NVARCHAR(255)  NULL,
  CreatedByUserId INT            NULL,
  CreatedAt       DATETIME2(0)   NOT NULL CONSTRAINT DF_VenueBlackouts_CreatedAt DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT FK_VenueBlackouts_Venue FOREIGN KEY (VenueId) REFERENCES dbo.Venues(VenueId),
  CONSTRAINT FK_VenueBlackouts_User FOREIGN KEY (CreatedByUserId) REFERENCES dbo.Users(UserId),
  CONSTRAINT CK_VenueBlackouts_Range CHECK (EndAt > StartAt)
);

CREATE NONCLUSTERED INDEX IX_VenueBlackouts_Venue_Range
  ON dbo.VenueBlackouts (VenueId, StartAt, EndAt);

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

PRINT N'Schema created.';

/* -------------------------------------------------------------------------- */
/* 3) SEED                                                                    */
/* -------------------------------------------------------------------------- */

INSERT INTO dbo.Roles (RoleId, RoleCode, RoleName) VALUES
  (1, N'Employee',      N'Employee'),
  (2, N'Manager',       N'Manager'),
  (3, N'HR',            N'HR'),
  (4, N'Administrator', N'Administrator');

INSERT INTO dbo.Settings (SettingKey, SettingValue, Description) VALUES
  (N'min_duration_minutes',       N'30',     N'Minimum booking duration (minutes)'),
  (N'max_duration_minutes',       N'480',    N'Maximum booking duration (minutes)'),
  (N'booking_window_days',        N'90',     N'Max days ahead for a booking'),
  (N'booking_lead_days',          N'2',      N'Minimum days in advance Employee/Manager must book'),
  (N'booking_buffer_minutes',     N'30',     N'Gap kept free before and after every booking (minutes)'),
  (N'slot_step_minutes',          N'30',     N'Booking time step: 30 = half-hours, 60 = whole hours'),
  (N'operating_hours_start',      N'08:00',  N'Earliest start time'),
  (N'operating_hours_end',        N'17:00',  N'Latest end time'),
  (N'overlap_include_pending',    N'0',      N'1 = pending also blocks venue (Option B queue uses 0)'),
  (N'reminder_hours_before',      N'24',     N'Reminder hours before event'),
  (N'equipment_buffer_minutes',   N'30',     N'Turnaround before shared equipment can be reused'),
  (N'equipment_reserve_pending',  N'0',      N'1 = pending also reserve equipment stock'),
  (N'conflict_auto_action',       N'reschedule', N'When HR approves: reschedule competing HR-queue requests or decline'),
  (N'email_enabled',              N'0',      N'1 = attempt to send e-mail for notifications'),
  (N'email_from',                 N'noreply@company.local', N'From address for notification e-mails'),
  (N'email_smtp_host',            N'',       N'SMTP host (empty = PHP mail())'),
  (N'email_smtp_port',            N'587',    N'SMTP port'),
  (N'email_smtp_user',            N'',       N'SMTP username'),
  (N'email_smtp_pass',            N'',       N'SMTP password'),
  (N'recurring_max_count',        N'52',     N'Max instances for a recurring series'),
  (N'approval_rules_enabled',     N'0',      N'1 = use ApprovalRules table (0 = hard-coded roles)'),
  (N'venue_hours_enabled',        N'1',      N'1 = enforce per-venue hours when set'),
  (N'manager_sla_days',           N'3',      N'Days before Pending Manager auto-escalates to HR (0 = off)'),
  (N'max_revisions',              N'2',      N'Max revision cycles before final decision required'),
  (N'slot_policy',                N'queue',  N'queue = first to reach HR wins; hold_pending = pending blocks'),
  (N'auto_complete_enabled',      N'1',      N'1 = past Approved/Rescheduled become Completed');

INSERT INTO dbo.Departments (Name) VALUES
  (N'Engineering'),
  (N'Human Resources'),
  (N'Operations');

DECLARE @DeptEng INT = (SELECT DepartmentId FROM dbo.Departments WHERE Name = N'Engineering');
DECLARE @DeptHR  INT = (SELECT DepartmentId FROM dbo.Departments WHERE Name = N'Human Resources');
DECLARE @DeptOps INT = (SELECT DepartmentId FROM dbo.Departments WHERE Name = N'Operations');

/* bcrypt: password_hash for admin / hr / manager / employee */
DECLARE @HAdmin    NVARCHAR(255) = N'$2y$10$VsbXStPxSQ8c/ILCv7ORbuKKcA0.6KEozEzNaQSeGWda39vG3YTdK';
DECLARE @HHr       NVARCHAR(255) = N'$2y$10$aBGkU/tsl4tIqIyVCIZokOlWANWOgns1g/xo.bcGRYxo6G.JH9gBa';
DECLARE @HManager  NVARCHAR(255) = N'$2y$10$6DseVhqIJwwzaFWIuBhy6OA.evHJ9oleO5WaDflW/5yH5yknzLNEK';
DECLARE @HEmployee NVARCHAR(255) = N'$2y$10$dAI5c3W1dT85pZZ5EUvS.uG..bbTsz2D/hKPTEHMLI4aLChynVgui';

INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId) VALUES
  (N'admin@company.local',    @HAdmin,    N'System Administrator', 4, NULL),
  (N'admin',                  @HAdmin,    N'System Administrator', 4, NULL),
  (N'hr@company.local',       @HHr,       N'HR Officer',           3, @DeptHR),
  (N'hr',                     @HHr,       N'HR Officer',           3, @DeptHR),
  (N'manager@company.local',  @HManager,  N'Engineering Manager',  2, @DeptEng),
  (N'manager',                @HManager,  N'Engineering Manager',  2, @DeptEng),
  (N'employee@company.local', @HEmployee, N'Engineering Employee', 1, @DeptEng),
  (N'employee',               @HEmployee, N'Engineering Employee', 1, @DeptEng);

UPDATE d
SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email = N'manager' AND u.RoleId = 2
WHERE d.Name = N'Engineering';

INSERT INTO dbo.Venues (Name, Location, Capacity, Equipment, Status) VALUES
  (N'Conference Room A', N'Building 1, Floor 2', 12,  N'Projector,Whiteboard,Video Conference', N'Available'),
  (N'Conference Room B', N'Building 1, Floor 3', 20,  N'Projector,Whiteboard', N'Available'),
  (N'Auditorium',        N'Building 2, Ground Floor', 100, N'Stage,PA System,Projector', N'Available'),
  (N'Meeting Pod 1',     N'Building 1, Floor 1', 4,   N'TV Screen', N'Available');

INSERT INTO dbo.RequirementOptions (Name, Description, MaxQuantity, SortOrder, TotalStock, StockScope) VALUES
  (N'Projector',           N'Projector and screen',        1,  1, 3,    N'Shared'),
  (N'Microphone',          N'Wireless microphone',         5,  2, 10,   N'Shared'),
  (N'Extra chairs',        N'Additional chairs',           50, 3, NULL, N'PerVenue'),
  (N'Catering',            N'Snacks / refreshments',       1,  4, NULL, N'Shared'),
  (N'Video conferencing',  N'Camera and speaker setup',    1,  5, 2,    N'Shared');

INSERT INTO dbo.ApprovalRules (Name, RoleCode, Stage, DepartmentId, SortOrder) VALUES
  (N'Manager validates department requests', N'Manager',       N'Pending Manager Validation', NULL, 10),
  (N'HR decides after manager',              N'HR',            N'Pending HR Decision',        NULL, 20),
  (N'Admin can decide any stage',            N'Administrator', N'Pending Manager Validation', NULL, 5),
  (N'Admin can decide any stage',            N'Administrator', N'Pending HR Decision',        NULL, 5);

PRINT N'Seed complete.';
PRINT N'';
PRINT N'Demo logins: admin/admin  hr/hr  manager/manager  employee/employee';
PRINT N'00_FULL_RESET.sql finished successfully.';
