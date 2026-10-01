/*
  Demo operational data — Venue Booking System (SQL Server / Navicat)
  Prerequisites: 01_schema.sql + 02_seed.sql (or this file will ensure roles/depts exist)

  Fix: RoleId must never be NULL — roles are MERGEd first, IDs are fixed 1–4.
*/

USE VenueBooking;
GO

SET NOCOUNT ON;

/* ---------- Ensure roles exist (RoleId is NOT NULL on Users) ---------- */
MERGE dbo.Roles AS t
USING (VALUES
  (1, N'Employee',      N'Employee'),
  (2, N'Manager',       N'Manager'),
  (3, N'HR',            N'HR'),
  (4, N'Administrator', N'Administrator')
) AS s (RoleId, RoleCode, RoleName)
ON t.RoleId = s.RoleId
WHEN NOT MATCHED THEN
  INSERT (RoleId, RoleCode, RoleName) VALUES (s.RoleId, s.RoleCode, s.RoleName)
WHEN MATCHED THEN
  UPDATE SET RoleCode = s.RoleCode, RoleName = s.RoleName;
GO

/* Fixed RoleIds — never rely on a failed SELECT */
DECLARE @RoleEmp INT = 1;
DECLARE @RoleMgr INT = 2;
DECLARE @RoleHR  INT = 3;
DECLARE @RoleAdm INT = 4;

/* Verify roles are present */
IF NOT EXISTS (SELECT 1 FROM dbo.Roles WHERE RoleId = 1)
BEGIN
  RAISERROR(N'Roles table is empty. Run 01_schema.sql and 02_seed.sql first.', 16, 1);
  RETURN;
END

/* ---------- Departments ---------- */
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Engineering')
  INSERT INTO dbo.Departments (Name) VALUES (N'Engineering');
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Human Resources')
  INSERT INTO dbo.Departments (Name) VALUES (N'Human Resources');
IF NOT EXISTS (SELECT 1 FROM dbo.Departments WHERE Name = N'Operations')
  INSERT INTO dbo.Departments (Name) VALUES (N'Operations');

DECLARE @DeptEng INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Engineering');
DECLARE @DeptHR  INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Human Resources');
DECLARE @DeptOps INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Operations');

IF @DeptEng IS NULL OR @DeptHR IS NULL OR @DeptOps IS NULL
BEGIN
  RAISERROR(N'Departments missing. Check dbo.Departments.', 16, 1);
  RETURN;
END

/* bcrypt: employee / manager / hr / admin (same as 02_seed.sql) */
DECLARE @HEmp NVARCHAR(255) = N'$2y$10$dAI5c3W1dT85pZZ5EUvS.uG..bbTsz2D/hKPTEHMLI4aLChynVgui';
DECLARE @HMgr NVARCHAR(255) = N'$2y$10$6DseVhqIJwwzaFWIuBhy6OA.evHJ9oleO5WaDflW/5yH5yknzLNEK';
DECLARE @HHr  NVARCHAR(255) = N'$2y$10$aBGkU/tsl4tIqIyVCIZokOlWANWOgns1g/xo.bcGRYxo6G.JH9gBa';
DECLARE @HAdm NVARCHAR(255) = N'$2y$10$VsbXStPxSQ8c/ILCv7ORbuKKcA0.6KEozEzNaQSeGWda39vG3YTdK';

/* ---------- Core demo users (idempotent) ---------- */
IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'admin')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'admin', @HAdm, N'System Administrator', @RoleAdm, NULL);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'admin@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'admin@company.local', @HAdm, N'System Administrator', @RoleAdm, NULL);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'hr')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'hr', @HHr, N'HR Officer', @RoleHR, @DeptHR);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'hr@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'hr@company.local', @HHr, N'HR Officer', @RoleHR, @DeptHR);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'manager')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'manager', @HMgr, N'Engineering Manager', @RoleMgr, @DeptEng);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'manager@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'manager@company.local', @HMgr, N'Engineering Manager', @RoleMgr, @DeptEng);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'employee')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'employee', @HEmp, N'Engineering Employee', @RoleEmp, @DeptEng);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'employee@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'employee@company.local', @HEmp, N'Engineering Employee', @RoleEmp, @DeptEng);

/* Extra users */
IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'alice@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'alice@company.local', @HEmp, N'Alice Chen', @RoleEmp, @DeptEng);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'bob@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'bob@company.local', @HEmp, N'Bob Santos', @RoleEmp, @DeptEng);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'cara@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'cara@company.local', @HEmp, N'Cara Reyes', @RoleEmp, @DeptOps);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'david@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'david@company.local', @HMgr, N'David Park', @RoleMgr, @DeptOps);

IF NOT EXISTS (SELECT 1 FROM dbo.Users WHERE Email = N'elena@company.local')
  INSERT INTO dbo.Users (Email, PasswordHash, FullName, RoleId, DepartmentId)
  VALUES (N'elena@company.local', @HHr, N'Elena Cruz', @RoleHR, @DeptHR);

/* Assign department managers */
UPDATE d SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email IN (N'manager', N'manager@company.local') AND u.RoleId = 2
WHERE d.Name = N'Engineering';

UPDATE d SET d.ManagerUserId = u.UserId
FROM dbo.Departments d
INNER JOIN dbo.Users u ON u.Email = N'david@company.local' AND u.RoleId = 2
WHERE d.Name = N'Operations';

PRINT N'Users and departments OK.';
GO

/* ---------- Venues ---------- */
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

/* ---------- Bookings + related (batch 2 — needs resolved IDs) ---------- */
DECLARE @DeptEng INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Engineering');
DECLARE @DeptOps INT = (SELECT TOP 1 DepartmentId FROM dbo.Departments WHERE Name = N'Operations');

DECLARE @MgrId   INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'manager', N'manager@company.local') ORDER BY CASE Email WHEN N'manager' THEN 0 ELSE 1 END);
DECLARE @HrId    INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'hr', N'hr@company.local') ORDER BY CASE Email WHEN N'hr' THEN 0 ELSE 1 END);
DECLARE @EmpId   INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'employee', N'employee@company.local') ORDER BY CASE Email WHEN N'employee' THEN 0 ELSE 1 END);
DECLARE @AliceId INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'alice@company.local');
DECLARE @BobId   INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'bob@company.local');
DECLARE @CaraId  INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'cara@company.local');
DECLARE @DavidId INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'david@company.local');
DECLARE @ElenaId INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'elena@company.local');

DECLARE @VenueA INT = (SELECT TOP 1 VenueId FROM dbo.Venues WHERE Name = N'Conference Room A');
DECLARE @VenueB INT = (SELECT TOP 1 VenueId FROM dbo.Venues WHERE Name = N'Conference Room B');
DECLARE @VenueC INT = (SELECT TOP 1 VenueId FROM dbo.Venues WHERE Name = N'Auditorium');
DECLARE @VenueD INT = (SELECT TOP 1 VenueId FROM dbo.Venues WHERE Name = N'Meeting Pod 1');

IF @MgrId IS NULL OR @EmpId IS NULL OR @AliceId IS NULL OR @VenueA IS NULL
BEGIN
  RAISERROR(N'Required users or venues missing after user seed. Aborting bookings.', 16, 1);
  RETURN;
END

DECLARE @Today     DATE = CAST(GETDATE() AS DATE);
DECLARE @Tomorrow  DATE = DATEADD(DAY, 1, @Today);
DECLARE @In3Days   DATE = DATEADD(DAY, 3, @Today);
DECLARE @In5Days   DATE = DATEADD(DAY, 5, @Today);
DECLARE @In7Days   DATE = DATEADD(DAY, 7, @Today);
DECLARE @In10Days  DATE = DATEADD(DAY, 10, @Today);
DECLARE @LastWeek  DATE = DATEADD(DAY, -7, @Today);

IF NOT EXISTS (SELECT 1 FROM dbo.Bookings WHERE ReferenceCode LIKE N'VB-DEMO-%')
BEGIN
  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    AdditionalRequirements, Status, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0001', @VenueA, @AliceId, @DeptEng, @MgrId,
    @In3Days, '09:00', '10:30', N'Sprint planning for Q2 release', N'Meeting', 8,
    N'Whiteboard markers', N'Pending Manager Validation',
    DATEADD(HOUR, -2, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    AdditionalRequirements, Status, ManagerNotes,
    ValidatedAt, ValidatedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0002', @VenueB, @BobId, @DeptEng, @MgrId,
    @In5Days, '13:00', '15:00', N'Client demo dry-run', N'Presentation', 12,
    N'HDMI adapter', N'Pending HR Decision', N'Looks good for the team.',
    DATEADD(HOUR, -5, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -1, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, ManagerNotes, HrNotes,
    ValidatedAt, ValidatedByUserId, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0003', @VenueA, @EmpId, @DeptEng, @MgrId,
    @Tomorrow, '10:00', '11:00', N'One-on-one coaching session', N'Meeting', 2,
    N'Approved', N'OK', N'Approved by HR',
    DATEADD(DAY, -2, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -1, SYSUTCDATETIME()), @HrId,
    DATEADD(DAY, -3, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, ValidatedAt, ValidatedByUserId, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0004', @VenueA, @AliceId, @DeptEng, @MgrId,
    @Tomorrow, '14:00', '16:00', N'Architecture review', N'Workshop', 10,
    N'Approved',
    DATEADD(DAY, -2, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -1, SYSUTCDATETIME()), @HrId,
    DATEADD(DAY, -4, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, HrNotes, ValidatedAt, ValidatedByUserId, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0005', @VenueC, @CaraId, @DeptOps, @DavidId,
    @In7Days, '09:00', '12:00', N'All-hands town hall', N'Event', 80,
    N'Rescheduled', N'Moved to larger slot; original time conflicted with maintenance.',
    DATEADD(DAY, -3, SYSUTCDATETIME()), @DavidId,
    DATEADD(DAY, -2, SYSUTCDATETIME()), @HrId,
    DATEADD(DAY, -5, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, DeclineReason, ManagerNotes, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0006', @VenueD, @BobId, @DeptEng, @MgrId,
    @In10Days, '11:00', '12:00', N'Informal team lunch booking', N'Social', 4,
    N'Declined', N'Pods are for business meetings only.', N'Not aligned with policy.',
    DATEADD(DAY, -1, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -2, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, CancelReason, CancelledAt, CancelledByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0007', @VenueB, @EmpId, @DeptEng, @MgrId,
    @In5Days, '09:00', '10:00', N'Cancelled vendor call', N'Meeting', 5,
    N'Cancelled', N'Vendor postponed indefinitely.',
    DATEADD(HOUR, -12, SYSUTCDATETIME()), @EmpId,
    DATEADD(DAY, -2, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, ValidatedAt, ValidatedByUserId, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0008', @VenueA, @AliceId, @DeptEng, @MgrId,
    @LastWeek, '10:00', '11:30', N'Post-mortem retrospective', N'Meeting', 9,
    N'Completed',
    DATEADD(DAY, -14, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -12, SYSUTCDATETIME()), @HrId,
    DATEADD(DAY, -16, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0009', @VenueB, @CaraId, @DeptOps, @DavidId,
    @In10Days, '15:00', '16:30', N'Ops process training', N'Training', 15,
    N'Pending Manager Validation',
    DATEADD(HOUR, -1, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  INSERT INTO dbo.Bookings (
    ReferenceCode, VenueId, RequesterUserId, DepartmentId, DepartmentManagerId,
    BookingDate, StartTime, EndTime, Purpose, EventType, Attendees,
    Status, ValidatedAt, ValidatedByUserId, DecidedAt, DecidedByUserId, CreatedAt, UpdatedAt
  ) VALUES (
    N'VB-DEMO-0010', @VenueC, @EmpId, @DeptEng, @MgrId,
    @In10Days, '13:00', '17:00', N'Product launch internal screening', N'Event', 60,
    N'Approved',
    DATEADD(DAY, -4, SYSUTCDATETIME()), @MgrId,
    DATEADD(DAY, -3, SYSUTCDATETIME()), COALESCE(@ElenaId, @HrId),
    DATEADD(DAY, -6, SYSUTCDATETIME()), SYSUTCDATETIME()
  );

  PRINT N'Inserted 10 demo bookings.';
END
ELSE
  PRINT N'Demo bookings already exist — skipped.';
GO

/* Status history */
IF NOT EXISTS (SELECT 1 FROM dbo.BookingStatusHistory)
BEGIN
  INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason, ChangedAt)
  SELECT b.BookingId, NULL, N'Pending Manager Validation', b.RequesterUserId, N'Submitted', b.CreatedAt
  FROM dbo.Bookings b WHERE b.ReferenceCode LIKE N'VB-DEMO-%';

  INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason, ChangedAt)
  SELECT b.BookingId, N'Pending Manager Validation', N'Pending HR Decision', b.ValidatedByUserId, b.ManagerNotes, b.ValidatedAt
  FROM dbo.Bookings b WHERE b.ValidatedAt IS NOT NULL AND b.ReferenceCode LIKE N'VB-DEMO-%';

  INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason, ChangedAt)
  SELECT b.BookingId, N'Pending HR Decision', b.Status, b.DecidedByUserId,
         COALESCE(b.HrNotes, b.DeclineReason, N'Decision recorded'), b.DecidedAt
  FROM dbo.Bookings b
  WHERE b.DecidedAt IS NOT NULL AND b.ReferenceCode LIKE N'VB-DEMO-%'
    AND b.Status IN (N'Approved', N'Declined', N'Rescheduled');

  INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason, ChangedAt)
  SELECT b.BookingId, N'Pending Manager Validation', N'Cancelled', b.CancelledByUserId, b.CancelReason, b.CancelledAt
  FROM dbo.Bookings b WHERE b.Status = N'Cancelled' AND b.ReferenceCode LIKE N'VB-DEMO-%';

  INSERT INTO dbo.BookingStatusHistory (BookingId, FromStatus, ToStatus, ChangedByUserId, Reason, ChangedAt)
  SELECT b.BookingId, N'Approved', N'Completed', NULL, N'Event ended',
         DATEADD(HOUR, 2, CAST(b.BookingDate AS DATETIME2))
  FROM dbo.Bookings b WHERE b.Status = N'Completed' AND b.ReferenceCode LIKE N'VB-DEMO-%';

  PRINT N'Status history seeded.';
END
GO

/* Notifications */
IF NOT EXISTS (SELECT 1 FROM dbo.Notifications)
BEGIN
  DECLARE @MgrN   INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'manager', N'manager@company.local'));
  DECLARE @EmpN   INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'employee', N'employee@company.local'));
  DECLARE @HrN    INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'hr', N'hr@company.local'));
  DECLARE @AliceN INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'alice@company.local');
  DECLARE @DavidN INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email = N'david@company.local');

  DECLARE @B1 INT = (SELECT BookingId FROM dbo.Bookings WHERE ReferenceCode = N'VB-DEMO-0001');
  DECLARE @B2 INT = (SELECT BookingId FROM dbo.Bookings WHERE ReferenceCode = N'VB-DEMO-0002');
  DECLARE @B3 INT = (SELECT BookingId FROM dbo.Bookings WHERE ReferenceCode = N'VB-DEMO-0003');

  INSERT INTO dbo.Notifications (UserId, Title, Body, LinkUrl, IsRead, CreatedAt) VALUES
  (@MgrN, N'New booking request', N'Alice Chen submitted request VB-DEMO-0001',
     CASE WHEN @B1 IS NOT NULL THEN N'booking-details.html?id=' + CAST(@B1 AS NVARCHAR(20)) ELSE NULL END, 0, DATEADD(HOUR, -2, SYSUTCDATETIME())),
  (@HrN, N'Booking ready for HR', N'Request VB-DEMO-0002 awaits decision',
     CASE WHEN @B2 IS NOT NULL THEN N'booking-details.html?id=' + CAST(@B2 AS NVARCHAR(20)) ELSE NULL END, 0, DATEADD(HOUR, -5, SYSUTCDATETIME())),
  (@EmpN, N'Booking approved', N'Your booking VB-DEMO-0003 was approved.',
     CASE WHEN @B3 IS NOT NULL THEN N'booking-details.html?id=' + CAST(@B3 AS NVARCHAR(20)) ELSE NULL END, 0, DATEADD(DAY, -1, SYSUTCDATETIME())),
  (@AliceN, N'Request validated', N'Your booking was validated and sent to HR.', NULL, 1, DATEADD(DAY, -2, SYSUTCDATETIME())),
  (@DavidN, N'New booking request', N'Cara Reyes submitted request VB-DEMO-0009', NULL, 0, DATEADD(HOUR, -1, SYSUTCDATETIME())),
  (@EmpN, N'Booking cancelled', N'You cancelled VB-DEMO-0007.', NULL, 1, DATEADD(HOUR, -12, SYSUTCDATETIME()));

  PRINT N'Notifications seeded.';
END
GO

/* Activity logs */
IF NOT EXISTS (SELECT 1 FROM dbo.ActivityLogs)
BEGIN
  DECLARE @Ad INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'admin', N'admin@company.local'));
  DECLARE @Mg INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'manager', N'manager@company.local'));
  DECLARE @Hr INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'hr', N'hr@company.local'));
  DECLARE @Em INT = (SELECT TOP 1 UserId FROM dbo.Users WHERE Email IN (N'employee', N'employee@company.local'));

  INSERT INTO dbo.ActivityLogs (ActorUserId, ActorEmail, Action, EntityType, EntityId, Detail, IpAddress, CreatedAt) VALUES
  (@Ad, N'admin', N'login', N'auth', CAST(@Ad AS NVARCHAR(20)), NULL, N'127.0.0.1', DATEADD(DAY, -1, SYSUTCDATETIME())),
  (@Em, N'employee', N'booking.create', N'booking', N'VB-DEMO-0003', N'{"purpose":"One-on-one coaching session"}', N'127.0.0.1', DATEADD(DAY, -3, SYSUTCDATETIME())),
  (@Mg, N'manager', N'booking.validate', N'booking', N'VB-DEMO-0003', NULL, N'127.0.0.1', DATEADD(DAY, -2, SYSUTCDATETIME())),
  (@Hr, N'hr', N'booking.approve', N'booking', N'VB-DEMO-0003', NULL, N'127.0.0.1', DATEADD(DAY, -1, SYSUTCDATETIME())),
  (@Mg, N'manager', N'booking.decline', N'booking', N'VB-DEMO-0006', N'{"reason":"Pods are for business meetings only."}', N'127.0.0.1', DATEADD(DAY, -1, SYSUTCDATETIME())),
  (@Em, N'employee', N'booking.cancel', N'booking', N'VB-DEMO-0007', NULL, N'127.0.0.1', DATEADD(HOUR, -12, SYSUTCDATETIME())),
  (@Ad, N'admin', N'venue.create', N'venue', N'1', N'{"name":"Conference Room A"}', N'127.0.0.1', DATEADD(DAY, -30, SYSUTCDATETIME())),
  (@Ad, N'admin', N'user.create', N'user', N'alice@company.local', N'{"role":"Employee"}', N'127.0.0.1', DATEADD(DAY, -20, SYSUTCDATETIME()));

  PRINT N'Activity logs seeded.';
END
GO

SELECT N'Users' AS Entity, COUNT(*) AS Cnt FROM dbo.Users
UNION ALL SELECT N'Departments', COUNT(*) FROM dbo.Departments
UNION ALL SELECT N'Venues', COUNT(*) FROM dbo.Venues
UNION ALL SELECT N'Bookings', COUNT(*) FROM dbo.Bookings
UNION ALL SELECT N'Notifications', COUNT(*) FROM dbo.Notifications
UNION ALL SELECT N'ActivityLogs', COUNT(*) FROM dbo.ActivityLogs;
GO

PRINT N'Demo data seed complete.';
GO
