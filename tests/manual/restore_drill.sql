/*
  BACKUP / RESTORE DRILL  (checklist item 7)  -- NOT YET RUN by the author; run it on a non-production copy first.

  Run with sqlcmd from a machine that can reach the server, e.g.:
    sqlcmd -S VMAPPS2 -U <admin_login> -P <password> -i tests/manual/restore_drill.sql ^
           -v SourceDb=VenueBooking BackupFile="D:\Backups\VenueBooking_drill.bak" RestoreDir="D:\SQLData"

  The BackupFile path and RestoreDir are on the SQL SERVER machine, and the SQL Server service account needs write access.
  It restores to a NEW database (VenueBooking_Restore), so it never touches the live one.
  Record the elapsed seconds it prints and compare them with your recovery targets (RTO = restore time, RPO = age of newest backup).
*/
SET NOCOUNT ON;
DECLARE @t0 DATETIME2 = SYSUTCDATETIME();

-- 1. Take a verified full backup.
BACKUP DATABASE [$(SourceDb)] TO DISK = N'$(BackupFile)' WITH INIT, CHECKSUM, STATS = 10;
RESTORE VERIFYONLY FROM DISK = N'$(BackupFile)' WITH CHECKSUM;
PRINT CONCAT('Backup+verify seconds: ', DATEDIFF(SECOND, @t0, SYSUTCDATETIME()));

-- 2. Restore under a new name, moving each file.
DECLARE @t1 DATETIME2 = SYSUTCDATETIME();
CREATE TABLE #files (LogicalName NVARCHAR(128), PhysicalName NVARCHAR(260), Type CHAR(1), FileGroupName NVARCHAR(128),
  Size NUMERIC(20,0), MaxSize NUMERIC(20,0), FileId BIGINT, CreateLSN NUMERIC(25,0), DropLSN NUMERIC(25,0), UniqueId UNIQUEIDENTIFIER,
  ReadOnlyLSN NUMERIC(25,0), ReadWriteLSN NUMERIC(25,0), BackupSizeInBytes BIGINT, SourceBlockSize INT, FileGroupId INT,
  LogGuid UNIQUEIDENTIFIER, DifferentialBaseLSN NUMERIC(25,0), DifferentialBaseGUID UNIQUEIDENTIFIER, IsReadOnly BIT, IsPresent BIT,
  TDEThumbprint VARBINARY(32), SnapshotUrl NVARCHAR(360));
INSERT #files EXEC (N'RESTORE FILELISTONLY FROM DISK = N''$(BackupFile)''');

DECLARE @move NVARCHAR(MAX) = N'';
SELECT @move = @move + N', MOVE N''' + LogicalName + N''' TO N''$(RestoreDir)\VenueBooking_Restore_' + CAST(FileId AS NVARCHAR(10))
             + CASE WHEN Type = 'L' THEN N'.ldf' ELSE N'.mdf' END + N'''' FROM #files;
DECLARE @sql NVARCHAR(MAX) = N'RESTORE DATABASE [VenueBooking_Restore] FROM DISK = N''$(BackupFile)'' WITH REPLACE, RECOVERY, CHECKSUM' + @move;
EXEC (@sql);
PRINT CONCAT('Restore seconds (your measured RTO for this data size): ', DATEDIFF(SECOND, @t1, SYSUTCDATETIME()));

-- 3. Compare row counts and key history between source and restored copy. Every row must say MATCH.
DECLARE @cmp TABLE (Item NVARCHAR(40), Source BIGINT, Restored BIGINT);
DECLARE @a BIGINT, @b BIGINT;
DECLARE @items TABLE (Tbl SYSNAME);
INSERT @items VALUES (N'Users'), (N'Departments'), (N'Venues'), (N'Bookings'), (N'BookingStatusHistory'), (N'Notifications'), (N'ActivityLogs'), (N'Settings');
DECLARE @tbl SYSNAME;
DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT Tbl FROM @items;
OPEN c; FETCH NEXT FROM c INTO @tbl;
WHILE @@FETCH_STATUS = 0
BEGIN
  DECLARE @q NVARCHAR(MAX) = N'SELECT @a = COUNT_BIG(*) FROM [$(SourceDb)].dbo.' + QUOTENAME(@tbl)
                           + N'; SELECT @b = COUNT_BIG(*) FROM [VenueBooking_Restore].dbo.' + QUOTENAME(@tbl);
  EXEC sp_executesql @q, N'@a BIGINT OUTPUT, @b BIGINT OUTPUT', @a OUTPUT, @b OUTPUT;
  INSERT @cmp VALUES (@tbl, @a, @b);
  FETCH NEXT FROM c INTO @tbl;
END
CLOSE c; DEALLOCATE c;
SELECT Item, Source, Restored, CASE WHEN Source = Restored THEN 'MATCH' ELSE 'MISMATCH' END AS Result FROM @cmp;

-- 4. Spot-check approval history survived: bookings whose status has no matching history rows should be none (older seed data may show a few).
SELECT COUNT(*) AS BookingsWithoutHistory
FROM [VenueBooking_Restore].dbo.Bookings b
WHERE NOT EXISTS (SELECT 1 FROM [VenueBooking_Restore].dbo.BookingStatusHistory h WHERE h.BookingId = b.BookingId);

-- 5. Clean up when satisfied (left commented on purpose):
-- DROP DATABASE [VenueBooking_Restore];
