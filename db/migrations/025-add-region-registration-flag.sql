-- Add allow_registration flag to regions for controlling registration visibility
ALTER TABLE regions
  ADD COLUMN allow_registration TINYINT(1) NOT NULL DEFAULT 1 AFTER name;
