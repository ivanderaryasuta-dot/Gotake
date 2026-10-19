# GoTake — Campus Loan System (version 2)

An update of your previous GoTake website, redesigned to look like **PBL.pdf**
and extended with the pages that the design shows but the old version did not have.

**Same framework as before:** plain PHP + PostgreSQL (PDO), every page wrapped by
`includes/header.php` + `includes/footer.php`, small JSON files in `api/`, and the
same database views/functions (`v_loan_overview`, `v_admin_dashboard`, `approve_loan()`,
`reject_loan()`, `hand_over_loan()`, `return_loan()`, `item_available_on()`).
Every file has comments written to explain what it does and why.

---

## 1. How to run it

1. Copy the folder into your web server folder (XAMPP `htdocs`, Laragon `www`, or run
   `php -S localhost:8000` inside the folder).
2. Create the database with **your** `gotake_schema.sql` (tables, functions, views, seed items).
3. Run **`database/update_schema.sql`** once. Your schema already had almost everything the new
   design needs; this adds just two columns: `users.semester` and `users.ktm_valid_until`.
4. *(Optional, for testing)* run **`database/sample_data.sql`**. Your schema has no users, so without this
   you cannot log in. It adds the people from the design and a loan in every status.
   Password for every sample account: `Password123` — admin username `admin`, students NIM `200000001`–`200000005`
   (`200000003` = Abu Dzar). **Delete these accounts before real use.**
   (Terminal: `psql -U postgres -d gotake_db -f database/sample_data.sql`)
5. Check `config/db.php` (database name / user / password).
6. Open `splash.php` (loading screen) or `login.php`.
7. Put your images in `assets/img/` — see `assets/img/README.txt`.

---

## 2. Where is everything?

```
index.php                 Admin dashboard                       (same file name as before)
manage_requests.php       Admin: approve / reject / hand over / return   (same as before)
manage_items.php          Admin: add / edit / delete items                (NEW)
user_data.php             Admin: users, suspend / activate               (NEW)
reports.php               Admin: statistics + CSV export                  (NEW)
settings.php              Admin: site name, logo, collateral, max days    (NEW)

student_dashboard.php     Student home                          (same file name as before)
student_catalog.php       Item catalog (cards)                            (NEW)
student_new_request.php   Borrow request form                   (same file name as before)
student_borrowings.php    My Borrowings                                   (NEW)
student_activity.php      My Activity + chart                             (NEW)
account_settings.php      Student: password + notification switches      (NEW)

profile.php / profile_edit.php / change_password.php    (admins AND students)  (NEW)

splash.php                Loading screen                                  (NEW)
login.php                 Login (NIM for students, username for admins)   (redesigned)
register.php              Student sign-up                                 (NEW)
forgot_password.php       Reset password with an e-mailed code            (NEW)
logout.php                Log out                                         (NEW, was linked but missing)

includes/header.php       Top bar + sidebar (written once, used by every page)
includes/footer.php       Closes the layout + shared scripts
includes/auth_header.php  + auth_footer.php   Layout of login/register/forgot pages
includes/functions.php    Shared helpers (e(), img_slot(), passwords, settings, flash messages...)

api/                      Small files JavaScript calls in the background (return JSON)
   approve_loan.php  reject_loan.php  hand_over.php  process_return.php   (yours, now with comments)
   save_item.php  delete_item.php  save_user.php  toggle_user.php  export_report.php   (NEW)

assets/css/style.css      The whole design (colour variables at the top)
assets/js/main.js         Shared JavaScript (pop-ups, AJAX helpers, live search)
database/update_schema.sql  Adds the 2 missing columns (semester, ktm_valid_until)
database/sample_data.sql    OPTIONAL test accounts + loans
```

---

## 3. Images are blank (as you asked)

Each image spot shows an empty dashed box until you add the file. Drop the file in
`assets/img/` with the name listed in `assets/img/README.txt` and it appears — no code edit.
(Logo, login picture, and one photo per item named after the item code.)
The sidebar icons are empty squares, exactly like the placeholders in the design.

---

## 4. Things I changed or fixed in your old code

* **Bug:** "Hand Over" called `api/hand_over_loan.php` but the file is `api/hand_over.php`. Fixed.
* **Bug:** "Hand Over" refused loans where the student asked for more than half the stock (e.g. 5 of 8),
  because the stock check counted the approved loan against itself. It now checks the physical stock only
  (the dates were already checked at approval).
* **Bug:** "Process return" printed raw JSON on a blank page. It now sends in the background and shows errors in the pop-up.
* **Bug:** the old header printed HTML before pages tried to redirect, which can fail with
  "headers already sent". `header.php` now uses output buffering so redirects always work.
* **Security:** `approve_loan.php` and `process_return.php` had no admin check (and used admin id 1). Every admin API now requires a logged-in admin and uses the real admin id.
* **Passwords:** new accounts store a secure hash. Your old plain-text passwords still work and are upgraded to a hash automatically at their next login.
* Filter tabs and search in Manage Requests now really work (before they were decoration).
* Admin dashboard text is dark on the yellow banner (white-on-yellow is hard to read).

---

## 5. Checked against your real database (gotake_schema.sql)

I first wrote the code without seeing your schema, then compared it line by line. Things that were wrong and are now fixed:

* Loan status is an ENUM (`requested, approved, rejected, borrowed, returned`). There is **no** `returned_late`;
  late returns are stored in `returns.was_late`. "On-time returns" and the overdue rate now use that.
* Column names: `notify_on_approved` / `notify_before_due`, and admins have a real `staff_id`.
* Yes/No settings are stored as the text `'true'`/`'false'`, and the keys are `notify_admin_new_req` / `notify_admin_overdue`.
* `users.email` and `items.location` are NOT NULL, so those can no longer be left empty in the forms.
* Reports now read your own views (`v_report_summary`, `v_report_monthly`, `v_report_top_items`).
* "Borrowed" in charts/reports = items were handed over (`handed_over_at` is filled), same rule as your views.
* `loans.collateral` is an ENUM (KTM/KTP) and `chk_collateral` forces `collateral_agreed = TRUE`. So when the admin
  switches the collateral policy off, the form hides the collateral section but still saves KTM + agreed.

## 6. Known limits (please read)

* **I could not run PHP or PostgreSQL here**, so I checked the code by careful reading, checked the design in a
  browser, and compared every query against your schema. Please click through each page once; if something errors,
  the message points to the line. `sample_data.sql` was also not run on a real database.
* **E-mails are not actually sent.** The notification switches are saved (`users.notify_*`, `settings`), but sending
  needs a mail server + scheduled job (not built). Forgot-password tries PHP `mail()`; while `$SHOW_CODE_ON_SCREEN = true`
  (top of `forgot_password.php`) the code is shown on screen so you can test. **Set it to `false` before going online.**
* **Images:** your tables have `items.image_url` and `users.photo_url`, but as you asked I left all images blank and
  use files in `assets/img/` instead. "Change photo" is a disabled placeholder (avatars show initials).
* Not used: the "Wadir 2 approval letter" fields (`loans.letter_received`, `letter_number`) and the `notifications`
  table — they are in your schema but not in the PBL.pdf design.
* Not added (kept simple): CSRF tokens on forms. Worth adding before real public use.

---

## 7. Learning map: how a page works

```php
<?php
$page_title = 'Item Catalog';            // text in the browser tab
require_once 'includes/header.php';       // 1. session, login check, database ($pdo), top bar + sidebar
require_role('student');                  // 2. only students may see this page
$items = $pdo->query("SELECT ...")->fetchAll();   // 3. ask the database for data
?>
<h1>Item Catalog</h1>                     <!-- 4. write HTML; <?= e($x) ?> prints data safely -->
<?php include 'includes/footer.php'; ?>   <!-- 5. closes the layout, loads the JavaScript -->
```
