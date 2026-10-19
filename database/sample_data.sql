-- ================================================================
-- database/sample_data.sql   (OPTIONAL — for testing only!)
-- ----------------------------------------------------------------
-- WHAT IS THIS?
--   Your gotake_schema.sql creates the tables and 6 items, but no users
--   and no loans, so you cannot log in or see anything on the dashboards.
--   This file adds the people from the PBL.pdf design plus a few loans in
--   every status, so every page has something to show.
--
-- LOGIN (password for EVERY account:  Password123)
--     Admin    : username  admin        (staff id ADM-001)
--     Students : NIM 200000001 ... 200000005
--                200000003 = Abu Dzar Gifari (the student shown in the design)
--                200000005 = Dicky Andhika   (suspended, like in the design)
--
-- HOW TO RUN (after gotake_schema.sql AND update_schema.sql):
--     psql -U postgres -d gotake_db -f database/sample_data.sql
--   Run it ONCE. DELETE these accounts before using the site for real.
--
-- The loan dates are relative to TODAY (CURRENT_DATE), so the dashboards
-- always look "fresh" (one loan is deliberately overdue).
-- ================================================================

BEGIN;

-- ---------- USERS (password hash = "Password123", bcrypt) ----------
INSERT INTO users (role, username, staff_id, full_name, email, password_hash, department, phone)
VALUES ('admin', 'admin', 'ADM-001', 'Admin Facilities', 'admin.sarpras@campus.ac.id',
        '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'General Affairs & Facilities', '+62 812-9876-5432');

INSERT INTO users (role, nim, full_name, email, password_hash, program, class_name, is_active) VALUES
 ('student', '200000001', 'Firman Habibie',  'firman.h@campus.ac.id',   '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'Computer Science', 'CS - 1H', TRUE),
 ('student', '200000002', 'Akhmad Farrij',   'akhmad.f@campus.ac.id',   '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'Computer Science', 'CS - 1H', TRUE),
 ('student', '200000003', 'Abu Dzar Gifari', 'Gifari666@gmail.com',    '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'Computer Science', 'CS - 1H', TRUE),
 ('student', '200000004', 'Ivander Arya',    'IvanLaf@campus.ac.id',    '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'Computer Science', 'CS - 1H', TRUE),
 ('student', '200000005', 'Dicky Andhika',   'Andhika.D@campus.ac.id',  '$2b$10$ar4GKd5aA4Og5QWaiudTtuxfAkLC6mFJYDRUmOLO2cLbpUe5nPPY.', 'Computer Science', 'CS - 1H', FALSE);

-- Columns added by update_schema.sql
UPDATE users SET semester = 5, ktm_valid_until = DATE '2027-12-31' WHERE role = 'student';

-- ---------- LOANS (one of every kind) ----------

-- 1) PENDING: Abu Dzar asks for the Epson Projector
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status)
    VALUES ((SELECT id FROM users WHERE nim = '200000003'), CURRENT_DATE + 2, CURRENT_DATE + 4,
            'Final project group presentation', 'KTM', TRUE, 'requested')
    RETURNING id)
INSERT INTO loan_items (loan_id, item_id, qty)
SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-001'), 1 FROM l;

-- 2) PENDING: Dicky asks for an HDMI/VGA cable
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status)
    VALUES ((SELECT id FROM users WHERE nim = '200000005'), CURRENT_DATE + 3, CURRENT_DATE + 5,
            'Class presentation', 'KTP', TRUE, 'requested')
    RETURNING id)
INSERT INTO loan_items (loan_id, item_id, qty)
SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-002'), 1 FROM l;

-- 3) APPROVED (waiting for hand-over): Abu Dzar, Carpet
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at)
    VALUES ((SELECT id FROM users WHERE nim = '200000003'), CURRENT_DATE + 1, CURRENT_DATE + 3,
            'Student club event', 'KTM', TRUE, 'approved',
            (SELECT id FROM users WHERE username = 'admin'), now())
    RETURNING id)
INSERT INTO loan_items (loan_id, item_id, qty)
SELECT l.id, (SELECT id FROM items WHERE code = 'FRN-002'), 1 FROM l;

-- 4) REJECTED: Abu Dzar, Headset
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at, rejection_reason)
    VALUES ((SELECT id FROM users WHERE nim = '200000003'), CURRENT_DATE - 25, CURRENT_DATE - 23,
            'Recording a podcast', 'KTM', TRUE, 'rejected',
            (SELECT id FROM users WHERE username = 'admin'), now() - interval '26 days',
            'Item was not available on those dates')
    RETURNING id)
INSERT INTO loan_items (loan_id, item_id, qty)
SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-003'), 1 FROM l;

-- 5) BORROWED and OVERDUE: Ivander, Headset (should have been back 3 days ago)
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at, handed_over_at)
    VALUES ((SELECT id FROM users WHERE nim = '200000004'), CURRENT_DATE - 5, CURRENT_DATE - 3,
            'Group video project', 'KTP', TRUE, 'borrowed',
            (SELECT id FROM users WHERE username = 'admin'), now() - interval '6 days', now() - interval '5 days')
    RETURNING id)
INSERT INTO loan_items (loan_id, item_id, qty)
SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-003'), 1 FROM l;

-- 6) RETURNED on time: Firman, Epson Projector (also creates the "returns" row)
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at, handed_over_at)
    VALUES ((SELECT id FROM users WHERE nim = '200000001'), CURRENT_DATE - 20, CURRENT_DATE - 18,
            'Thesis seminar', 'KTM', TRUE, 'returned',
            (SELECT id FROM users WHERE username = 'admin'), now() - interval '21 days', now() - interval '20 days')
    RETURNING id),
li AS (
    INSERT INTO loan_items (loan_id, item_id, qty, condition_after)
    SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-001'), 1, 'good' FROM l)
INSERT INTO returns (loan_id, received_by, returned_at, was_late, compensation_amount)
SELECT l.id, (SELECT id FROM users WHERE username = 'admin'), now() - interval '18 days', FALSE, 0 FROM l;

-- 7) RETURNED LATE: Akhmad, Chair
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at, handed_over_at)
    VALUES ((SELECT id FROM users WHERE nim = '200000002'), CURRENT_DATE - 40, CURRENT_DATE - 38,
            'Welcome event', 'KTM', TRUE, 'returned',
            (SELECT id FROM users WHERE username = 'admin'), now() - interval '41 days', now() - interval '40 days')
    RETURNING id),
li AS (
    INSERT INTO loan_items (loan_id, item_id, qty, condition_after)
    SELECT l.id, (SELECT id FROM items WHERE code = 'FRN-001'), 10, 'good' FROM l)
INSERT INTO returns (loan_id, received_by, returned_at, was_late, compensation_amount)
SELECT l.id, (SELECT id FROM users WHERE username = 'admin'), now() - interval '36 days', TRUE, 0 FROM l;

-- 8) RETURNED on time: Abu Dzar, HDMI cable (so his "My Activity" is not empty)
WITH l AS (
    INSERT INTO loans (user_id, borrow_date, return_date, purpose, collateral, collateral_agreed, status, reviewed_by, reviewed_at, handed_over_at)
    VALUES ((SELECT id FROM users WHERE nim = '200000003'), CURRENT_DATE - 12, CURRENT_DATE - 11,
            'Lab practicum', 'KTM', TRUE, 'returned',
            (SELECT id FROM users WHERE username = 'admin'), now() - interval '13 days', now() - interval '12 days')
    RETURNING id),
li AS (
    INSERT INTO loan_items (loan_id, item_id, qty, condition_after)
    SELECT l.id, (SELECT id FROM items WHERE code = 'ELC-002'), 1, 'good' FROM l)
INSERT INTO returns (loan_id, received_by, returned_at, was_late, compensation_amount)
SELECT l.id, (SELECT id FROM users WHERE username = 'admin'), now() - interval '11 days', FALSE, 0 FROM l;

COMMIT;
