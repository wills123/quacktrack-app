-- Add snapshot_mode to form_campaigns so campaigns can be live (auto-resnapshot while active)
-- or frozen (stable snapshot). Existing active campaigns should remain live until closed.

ALTER TABLE form_campaigns
  ADD COLUMN snapshot_mode ENUM('frozen','live') NOT NULL DEFAULT 'live' AFTER status,
  ADD COLUMN frozen_at DATETIME DEFAULT NULL AFTER closed_at,
  ADD INDEX idx_form_campaigns_snapshot_mode (snapshot_mode);

-- Backfill existing campaigns:
-- - active => live
-- - closed/archived => frozen (and set frozen_at when closed_at exists)
UPDATE form_campaigns
SET snapshot_mode = CASE
  WHEN status = 'active' THEN 'live'
  ELSE 'frozen'
END,
    frozen_at = CASE
  WHEN status <> 'active' AND closed_at IS NOT NULL THEN closed_at
  ELSE frozen_at
END;
