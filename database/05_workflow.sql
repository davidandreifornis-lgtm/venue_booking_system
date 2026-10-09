/* Workflow upgrade (additive, safe to re-run). Run after 01-04. */
USE VenueBooking;
GO
IF COL_LENGTH('dbo.Bookings','ProposedVenueId') IS NULL
  ALTER TABLE dbo.Bookings ADD
    ProposedVenueId INT NULL,
    ProposedDate DATE NULL,
    ProposedStartTime TIME(0) NULL,
    ProposedEndTime TIME(0) NULL,
    ProposalNotes NVARCHAR(1000) NULL,
    StatusBeforeProposal NVARCHAR(64) NULL;
GO
IF COL_LENGTH('dbo.BookingStatusHistory','Reason') IS NOT NULL
  ALTER TABLE dbo.BookingStatusHistory ALTER COLUMN Reason NVARCHAR(1000) NULL;
GO
