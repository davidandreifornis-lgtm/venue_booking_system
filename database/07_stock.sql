/* Optional stock limit per requirement option (NULL = unlimited). Run after 06. */
USE VenueBooking;
GO
IF COL_LENGTH('dbo.RequirementOptions','TotalStock') IS NULL
  ALTER TABLE dbo.RequirementOptions ADD TotalStock INT NULL;
GO
