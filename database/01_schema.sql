/*
  Venue Booking System — Microsoft SQL Server schema
  Compatible with Navicat for SQL Server

  Run order:
    1. 01_schema.sql  (this file)
    2. 02_seed.sql

  Create database first in Navicat if needed:
    CREATE DATABASE VenueBooking;
    GO
    USE VenueBooking;
    GO
*/

USE VenueBooking;
GO

/* ---------- Roles (lookup) ---------- */
IF OBJECT_ID(N'dbo.Roles', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Roles (
    RoleId      INT            NOT NULL PRIMARY KEY,
    RoleCode    NVARCHAR(32)   NOT NULL UNIQUE,
    RoleName    NVARCHAR(64)   NOT NULL
  );
END
GO

/* ---------- Departments ---------- */
IF OBJECT_ID(N'dbo.Departments', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Departments (
    DepartmentId   INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    Name           NVARCHAR(120)  NOT NULL,
    ManagerUserId  INT            NULL,           -- FK added after Users
    IsActive       BIT            NOT NULL CONSTRAINT DF_Departments_IsActive DEFAULT (1),
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Departments_CreatedAt DEFAULT (SYSUTCDATETIME()),
    UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Departments_UpdatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT UQ_Departments_Name UNIQUE (Name)
  );
END
GO

/* ---------- Users ---------- */
IF OBJECT_ID(N'dbo.Users', N'U') IS NULL
BEGIN
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
END
GO

/* Departments.ManagerUserId -> Users */
IF NOT EXISTS (
  SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_Departments_Manager'
)
BEGIN
  ALTER TABLE dbo.Departments
    ADD CONSTRAINT FK_Departments_Manager
    FOREIGN KEY (ManagerUserId) REFERENCES dbo.Users(UserId);
END
GO

/* ---------- Venues ---------- */
IF OBJECT_ID(N'dbo.Venues', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Venues (
    VenueId        INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    Name           NVARCHAR(150)  NOT NULL,
    Location       NVARCHAR(255)  NULL,
    Capacity       INT            NOT NULL,
    Equipment      NVARCHAR(500)  NULL,   -- comma-separated or JSON text
    Status         NVARCHAR(32)   NOT NULL CONSTRAINT DF_Venues_Status DEFAULT (N'Available'),
      -- Available | Unavailable | Maintenance
    IsActive       BIT            NOT NULL CONSTRAINT DF_Venues_IsActive DEFAULT (1),
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Venues_CreatedAt DEFAULT (SYSUTCDATETIME()),
    UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Venues_UpdatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT UQ_Venues_Name UNIQUE (Name),
    CONSTRAINT CK_Venues_Capacity CHECK (Capacity > 0),
    CONSTRAINT CK_Venues_Status CHECK (Status IN (N'Available', N'Unavailable', N'Maintenance'))
  );
END
GO

/* ---------- Bookings ---------- */
IF OBJECT_ID(N'dbo.Bookings', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Bookings (
    BookingId              INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    ReferenceCode          NVARCHAR(32)   NOT NULL,
    VenueId                INT            NOT NULL,
    RequesterUserId        INT            NOT NULL,
    DepartmentId           INT            NULL,
    DepartmentManagerId    INT            NULL,
    BookingDate            DATE           NOT NULL,          -- calendar date of event
    StartTime              TIME(0)        NOT NULL,
    EndTime                TIME(0)        NOT NULL,
    Purpose                NVARCHAR(500)  NOT NULL,
    EventType              NVARCHAR(100)  NULL,
    Attendees              INT            NOT NULL CONSTRAINT DF_Bookings_Attendees DEFAULT (1),
    AdditionalRequirements NVARCHAR(1000) NULL,
    Status                 NVARCHAR(64)   NOT NULL,
      /*
        Pending Manager Validation
        Manager Validated
        Pending HR Decision
        Approved
        Declined
        Rescheduled
        Cancelled
        Completed
      */
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
    CreatedAt              DATETIME2(0)   NOT NULL CONSTRAINT DF_Bookings_CreatedAt DEFAULT (SYSUTCDATETIME()),
    UpdatedAt              DATETIME2(0)   NOT NULL CONSTRAINT DF_Bookings_UpdatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT UQ_Bookings_Reference UNIQUE (ReferenceCode),
    CONSTRAINT FK_Bookings_Venue FOREIGN KEY (VenueId) REFERENCES dbo.Venues(VenueId),
    CONSTRAINT FK_Bookings_Requester FOREIGN KEY (RequesterUserId) REFERENCES dbo.Users(UserId),
    CONSTRAINT FK_Bookings_Department FOREIGN KEY (DepartmentId) REFERENCES dbo.Departments(DepartmentId),
    CONSTRAINT FK_Bookings_DeptManager FOREIGN KEY (DepartmentManagerId) REFERENCES dbo.Users(UserId),
    CONSTRAINT CK_Bookings_Time CHECK (EndTime > StartTime),
    CONSTRAINT CK_Bookings_Attendees CHECK (Attendees >= 1)
  );
END
GO

CREATE NONCLUSTERED INDEX IX_Bookings_Venue_Date
  ON dbo.Bookings (VenueId, BookingDate)
  INCLUDE (StartTime, EndTime, Status);
GO

CREATE NONCLUSTERED INDEX IX_Bookings_Requester
  ON dbo.Bookings (RequesterUserId, Status);
GO

CREATE NONCLUSTERED INDEX IX_Bookings_Status
  ON dbo.Bookings (Status);
GO

/* ---------- Booking history (status transitions) ---------- */
IF OBJECT_ID(N'dbo.BookingStatusHistory', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.BookingStatusHistory (
    HistoryId      INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    BookingId      INT            NOT NULL,
    FromStatus     NVARCHAR(64)   NULL,
    ToStatus       NVARCHAR(64)   NOT NULL,
    ChangedByUserId INT           NULL,
    Reason         NVARCHAR(1000) NULL,
    ChangedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_BookingHistory_ChangedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT FK_BookingHistory_Booking FOREIGN KEY (BookingId) REFERENCES dbo.Bookings(BookingId),
    CONSTRAINT FK_BookingHistory_User FOREIGN KEY (ChangedByUserId) REFERENCES dbo.Users(UserId)
  );
END
GO

/* ---------- Notifications ---------- */
IF OBJECT_ID(N'dbo.Notifications', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Notifications (
    NotificationId INT            IDENTITY(1,1) NOT NULL PRIMARY KEY,
    UserId         INT            NOT NULL,
    Title          NVARCHAR(200)  NOT NULL,
    Body           NVARCHAR(1000) NULL,
    LinkUrl        NVARCHAR(255)  NULL,
    IsRead         BIT            NOT NULL CONSTRAINT DF_Notifications_IsRead DEFAULT (0),
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Notifications_CreatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT FK_Notifications_User FOREIGN KEY (UserId) REFERENCES dbo.Users(UserId)
  );
END
GO

CREATE NONCLUSTERED INDEX IX_Notifications_User_Read
  ON dbo.Notifications (UserId, IsRead, CreatedAt DESC);
GO

/* ---------- Activity logs (append-only) ---------- */
IF OBJECT_ID(N'dbo.ActivityLogs', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.ActivityLogs (
    LogId          BIGINT         IDENTITY(1,1) NOT NULL PRIMARY KEY,
    ActorUserId    INT            NULL,
    ActorEmail     NVARCHAR(255)  NULL,
    Action         NVARCHAR(100)  NOT NULL,
    EntityType     NVARCHAR(50)   NULL,
    EntityId       NVARCHAR(50)   NULL,
    Detail         NVARCHAR(MAX)  NULL,   -- JSON text of before/after (no secrets)
    IpAddress      NVARCHAR(45)   NULL,
    CreatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_ActivityLogs_CreatedAt DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT FK_ActivityLogs_User FOREIGN KEY (ActorUserId) REFERENCES dbo.Users(UserId)
  );
END
GO

CREATE NONCLUSTERED INDEX IX_ActivityLogs_Created
  ON dbo.ActivityLogs (CreatedAt DESC);
GO

/* ---------- Settings (key/value) ---------- */
IF OBJECT_ID(N'dbo.Settings', N'U') IS NULL
BEGIN
  CREATE TABLE dbo.Settings (
    SettingKey     NVARCHAR(100)  NOT NULL PRIMARY KEY,
    SettingValue   NVARCHAR(500)  NOT NULL,
    Description    NVARCHAR(255)  NULL,
    UpdatedAt      DATETIME2(0)   NOT NULL CONSTRAINT DF_Settings_UpdatedAt DEFAULT (SYSUTCDATETIME())
  );
END
GO

/* ---------- Sessions (server-side session store optional; PHP can use its own)
   Kept for multi-server / audit if needed ---------- */
IF OBJECT_ID(N'dbo.UserSessions', N'U') IS NULL
BEGIN
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
END
GO

PRINT N'Schema created successfully.';
GO
