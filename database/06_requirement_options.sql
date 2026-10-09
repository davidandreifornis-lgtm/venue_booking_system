/* Admin-defined additional requirements. Run after 05. Safe to re-run. */
USE VenueBooking;
GO
IF OBJECT_ID(N'dbo.RequirementOptions', N'U') IS NULL
CREATE TABLE dbo.RequirementOptions (
  OptionId     INT IDENTITY(1,1) PRIMARY KEY,
  Name         NVARCHAR(150) NOT NULL,
  Description  NVARCHAR(300) NULL,
  MaxQuantity  INT NOT NULL CONSTRAINT DF_ReqOpt_Max DEFAULT (1),  -- 1 = tick box only; >1 = user picks a quantity
  SortOrder    INT NOT NULL CONSTRAINT DF_ReqOpt_Sort DEFAULT (0),
  IsActive     BIT NOT NULL CONSTRAINT DF_ReqOpt_Active DEFAULT (1),
  CreatedAt    DATETIME2(0) NOT NULL CONSTRAINT DF_ReqOpt_Created DEFAULT (SYSUTCDATETIME()),
  CONSTRAINT UQ_ReqOpt_Name UNIQUE (Name),
  CONSTRAINT CK_ReqOpt_Max CHECK (MaxQuantity >= 1)
);
GO
IF OBJECT_ID(N'dbo.BookingRequirements', N'U') IS NULL
CREATE TABLE dbo.BookingRequirements (
  BookingRequirementId INT IDENTITY(1,1) PRIMARY KEY,
  BookingId  INT NOT NULL,
  OptionId   INT NOT NULL,
  OptionName NVARCHAR(150) NOT NULL,  -- snapshot so history survives renames/deactivation
  Quantity   INT NOT NULL CONSTRAINT DF_BookReq_Qty DEFAULT (1),
  CONSTRAINT FK_BookReq_Booking FOREIGN KEY (BookingId) REFERENCES dbo.Bookings(BookingId),
  CONSTRAINT FK_BookReq_Option FOREIGN KEY (OptionId) REFERENCES dbo.RequirementOptions(OptionId),
  CONSTRAINT UQ_BookReq UNIQUE (BookingId, OptionId),
  CONSTRAINT CK_BookReq_Qty CHECK (Quantity >= 1)
);
GO
IF NOT EXISTS (SELECT 1 FROM dbo.RequirementOptions)
INSERT INTO dbo.RequirementOptions (Name, Description, MaxQuantity, SortOrder) VALUES
 (N'Projector', N'Projector and screen', 1, 1),
 (N'Microphone', N'Wireless microphone', 5, 2),
 (N'Extra chairs', N'Additional chairs', 50, 3),
 (N'Catering', N'Snacks / refreshments', 1, 4),
 (N'Video conferencing', N'Camera and speaker setup', 1, 5);
GO
