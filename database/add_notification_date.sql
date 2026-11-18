-- Run ONCE if the notifications page shows no times / wrong days.
-- The notifications page groups by day using notifications.created_at.
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW();
