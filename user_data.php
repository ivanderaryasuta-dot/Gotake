<?php
/**
 * user_data.php  —  USER DATA  (NEW PAGE, "User Data" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   The admin manages all accounts (students + admins):
 *     - filter All / Students / Admins, and search by name or ID
 *     - ADD a user  ("+ ADD USER")
 *     - EDIT a user ("Edit")
 *     - SUSPEND / ACTIVATE a user  (a suspended user cannot log in)
 *
 * HOW IT WORKS
 *   Same pattern as manage_items.php: a pop-up form, JavaScript sends the
 *   data to  api/save_user.php  or  api/toggle_user.php, then the page reloads.
 *
 * LOGIN NAMES
 *   Students log in with their NIM (stored in users.nim).
 *   Admins log in with a username (stored in users.username).
 *   The "ID" column shows whichever one the user has.
 * ------------------------------------------------------------
 */
$page_title = 'User Data';
require_once 'includes/header.php';
require_role('admin');

// ---- 1. Filters from the URL ----
$role_tab = $_GET['role'] ?? 'all';                 // all | student | admin
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 8;

$conditions = [];
$params     = [];
if (in_array($role_tab, ['student', 'admin'])) { $conditions[] = 'role = ?'; $params[] = $role_tab; }
if ($q !== '') {
    $conditions[] = "(full_name ILIKE ? OR CAST(nim AS TEXT) ILIKE ? OR username ILIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ---- 2. Pagination + rows.  NOTE: we list the columns by name and do NOT select password_hash,
//         so the password never travels to the page.
$count = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$count->execute($params);
$total_pages = max(1, (int)ceil(((int)$count->fetchColumn()) / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT id, full_name, nim, username, staff_id, role, email, is_active, created_at
    FROM users $where
    ORDER BY id
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

function role_link(string $role, string $q): string {
    return '?' . http_build_query(['role' => $role, 'q' => $q]);
}
?>

<div class="hero-top" style="margin-bottom:6px;">
    <div>
        <h1>User Data</h1>
        <p class="subtitle">Manage registered students and admin accounts</p>
    </div>
    <button class="btn btn-outline" style="background:var(--card-grey); border:none; padding:12px 22px;" onclick="openUserModal(null)">+ ADD USER</button>
</div>

<div class="tabs">
    <a class="tab <?= $role_tab === 'all' ? 'active' : '' ?>"     href="<?= e(role_link('all', $q)) ?>">All</a>
    <a class="tab <?= $role_tab === 'student' ? 'active' : '' ?>" href="<?= e(role_link('student', $q)) ?>">Students</a>
    <a class="tab <?= $role_tab === 'admin' ? 'active' : '' ?>"   href="<?= e(role_link('admin', $q)) ?>">Admins</a>

    <form method="GET" class="push-right">
        <input type="hidden" name="role" value="<?= e($role_tab) ?>">
        <input type="text" name="q" class="pill-input" placeholder="Search name or ID..." value="<?= e($q) ?>">
    </form>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr><th>Name</th><th>ID</th><th>Role</th><th>Email</th><th>Status</th><th>Joined</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="7" style="text-align:center;" class="muted">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u):
                    // Student: show the NIM.  Admin: show the staff id (ADM-001) or, if empty, the username.
                    $login_id = ($u['role'] === 'admin') ? ($u['staff_id'] ?: $u['username']) : $u['nim'];
                    $is_me    = ((int)$u['id'] === (int)$_SESSION['user_id']);
                    // For the Edit modal we pass the row + the "ID" as JSON
                    $js_user  = json_encode([
                        'id' => $u['id'], 'full_name' => $u['full_name'], 'role' => $u['role'],
                        'identifier' => ($u['role'] === 'admin') ? $u['username'] : $u['nim'],   // the LOGIN name
                        'staff_id' => $u['staff_id'], 'email' => $u['email'],
                    ]);
                ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($login_id ?? '-') ?></td>
                    <td><?= $u['role'] === 'admin' ? 'Admin' : 'Student' ?></td>
                    <td><?= e($u['email'] ?: '—') ?></td>
                    <td><?= status_badge($u['is_active'] ? 'active' : 'suspended') ?></td>
                    <td><?= e(format_date($u['created_at'] ?? null)) ?></td>
                    <td>
                        <button class="link-action" data-user="<?= e($js_user) ?>" onclick="openUserModal(this)">Edit</button>
                        <?php if (!$is_me): // you cannot suspend yourself ?>
                            <button class="link-action <?= $u['is_active'] ? 'danger' : '' ?>"
                                    onclick="toggleUser(<?= (int)$u['id'] ?>, <?= $u['is_active'] ? 'false' : 'true' ?>)">
                                <?= $u['is_active'] ? 'Suspend' : 'Activate' ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= render_pagination($page, $total_pages, ['role' => $role_tab, 'q' => $q]) ?>

<!-- ============================================================
     ADD / EDIT USER MODAL
     ============================================================ -->
<div class="modal" id="userModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="userModalTitle">Add user</h2>
            <button class="close-btn" onclick="hideModal('userModal')">×</button>
        </div>
        <div class="modal-error" id="userError"></div>

        <form id="userForm" autocomplete="off">
            <input type="hidden" id="user_id" value="">

            <div class="form-group">
                <label for="user_role">Role</label>
                <select id="user_role" class="form-control" onchange="updateIdLabel()">
                    <option value="student">Student</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label for="user_name">Full name</label>
                <input type="text" id="user_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="user_ident" id="identLabel">NIM</label>
                <input type="text" id="user_ident" class="form-control" required>
            </div>

            <!-- Staff ID: only shown when the role is Admin (see updateIdLabel() below) -->
            <div class="form-group" id="staffGroup" style="display:none;">
                <label for="user_staff">Staff ID <small>(e.g. ADM-001)</small></label>
                <input type="text" id="user_staff" class="form-control">
            </div>

            <div class="form-group">
                <label for="user_email">Email *</label>
                <input type="email" id="user_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="user_password">Password <small id="pwHint">(min. 8 characters)</small></label>
                <input type="password" id="user_password" class="form-control" autocomplete="new-password">
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="hideModal('userModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save user</button>
            </div>
        </form>
    </div>
</div>

<script>
// The "ID" field is a NIM for students and a Username for admins
function updateIdLabel() {
    var isAdmin = document.getElementById('user_role').value === 'admin';
    document.getElementById('identLabel').innerText = isAdmin ? 'Username' : 'NIM';
    document.getElementById('staffGroup').style.display = isAdmin ? '' : 'none';   // staff id only for admins
}

// btn === null -> ADD mode.  btn === a button -> EDIT mode (fields filled from its data-user JSON)
function openUserModal(btn) {
    var u = btn ? JSON.parse(btn.getAttribute('data-user')) : null;

    document.getElementById('userModalTitle').innerText = u ? 'Edit user' : 'Add user';
    document.getElementById('user_id').value       = u ? u.id : '';
    document.getElementById('user_role').value     = u ? u.role : 'student';
    document.getElementById('user_name').value     = u ? u.full_name : '';
    document.getElementById('user_ident').value    = u && u.identifier ? u.identifier : '';
    document.getElementById('user_email').value    = u && u.email ? u.email : '';
    document.getElementById('user_staff').value    = u && u.staff_id ? u.staff_id : '';
    document.getElementById('user_password').value = '';
    // When editing, an empty password means "keep the current one"
    document.getElementById('pwHint').innerText    = u ? '(leave empty to keep the current one)' : '(min. 8 characters)';

    updateIdLabel();
    document.getElementById('userError').style.display = 'none';
    showModal('userModal');
}

document.getElementById('userForm').addEventListener('submit', function (event) {
    event.preventDefault();

    postJSON('api/save_user.php', {
        id:         document.getElementById('user_id').value,
        role:       document.getElementById('user_role').value,
        full_name:  document.getElementById('user_name').value.trim(),
        identifier: document.getElementById('user_ident').value.trim(),
        email:      document.getElementById('user_email').value.trim(),
        staff_id:   document.getElementById('user_staff').value.trim(),
        password:   document.getElementById('user_password').value
    }).then(function (data) {
        if (data.success) { location.reload(); return; }
        var box = document.getElementById('userError');
        box.innerText = data.error;
        box.style.display = 'block';
    }).catch(function (err) { alert('Network error: ' + err); });
});

// Suspend (active = false) or re-activate (active = true)
function toggleUser(id, active) {
    var text = active ? 'Activate this user?' : 'Suspend this user? They will not be able to log in.';
    if (!confirm(text)) return;

    postJSON('api/toggle_user.php', { id: id, active: active }).then(function (data) {
        if (data.success) location.reload();
        else alert('Error: ' + data.error);
    }).catch(function (err) { alert('Network error: ' + err); });
}
</script>

<?php include 'includes/footer.php'; ?>
