-- ================================================================
-- database/update_schema.sql
-- ----------------------------------------------------------------
-- WHAT IS THIS?
--   Your database (gotake_schema.sql) already contains almost
--   everything the new design needs: e-mail, phone, program, class,
--   staff_id, department, notification switches, item location,
--   report views, settings ...
--
--   Only TWO things shown in the design were missing. This file adds
--   them. It is SAFE to run more than once ("IF NOT EXISTS") and it
--   does not change or delete any existing data.
--
--     users.semester          -> "Computer Science - Semester 5" on the profile
--     users.ktm_valid_until   -> "Active — valid until 2027" (student card)
--
-- HOW TO RUN IT (pick one)
--   * pgAdmin  : open the Query Tool on gotake_db, paste this file, press F5.
--   * Terminal : psql -U postgres -d gotake_db -f database/update_schema.sql
-- ================================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS semester          INT CHECK (semester IS NULL OR semester BETWEEN 1 AND 14);
ALTER TABLE users ADD COLUMN IF NOT EXISTS ktm_valid_until   DATE;
