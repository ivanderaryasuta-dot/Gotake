<?php
/**
 * manage_requests.php  —  MANAGE REQUESTS  ("Manage Requests" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   The admin checks borrow requests and the student's KTM/KTP
 *   collateral, then moves each loan through its life:
 *
 *      requested --Approve--> approved --Hand Over--> borrowed --Process Return--> returned
 *          \--Reject--> rejected
 *
 *   Each button calls a file in the  api/  folder in the background
 *   (JavaScript fetch), and the page reloads to show the new status.
 *   This is the SAME flow as your previous version.
 *
 * WHAT CHANGED
 *   - New design
 *   - The filter tabs (All / Pending / ...) and the search box now REALLY work
 *     (before they were only decoration). They use the URL: ?status=pending&q=...
 *   - Pagination (10 per page)
 *   - BUG FIX: "Hand Over" used to call api/hand_over_loan.php, but the file
 *     is named api/hand_over.php. It now calls the correct file.
 *   - BUG FIX: "Process return" now sends its form in the background and shows
 *     errors in a message, instead of printing raw JSON on a blank page.
 * ------------------------------------------------------------
 */
$page_title = 'Manage Requests';
require_once 'includes/header.php';
require_role('admin');

// ---- 1. Filters from the URL ----
$status_tab = $_GET['status'] ?? 'all';
$q          = trim($_GET['q'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 10;

// Which database status does each tab mean?
//   'requested' is shown as "Pending" in the design.
$tab_conditions = [
    'all'      => '',
    'pending'  => "status = 'requested'",
    'approved' => "status = 'approved'",
    'returned' => "status = 'returned'",
    'rejected' => "status = 'rejected'",
];
if (!isset($tab_conditions[$status_tab])) $status_tab = 'all';

// ---- 2. Build the WHERE clause from the tab + the search text ----
$conditions = [];
$params     = [];
if ($tab_conditions[$status_tab] !== '') $conditions[] = $tab_conditions[$status_tab];
if ($q !== '') {
    $conditions[] = "(full_name ILIKE ? OR CAST(nim AS TEXT) ILIKE ? OR items ILIKE ?)";
    $like   = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ---- 3. Count + fetch the current page ----
$count = $pdo->prepare("SELECT COUNT(*) FROM v_loan_overview $where");
$count->execute($params);
$total_pages = max(1, (int)ceil(((int)$count->fetchColumn()) / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM v_loan_overview $where ORDER BY id DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Small helper to build a tab link that keeps the search text
function tab_link(string $tab, string $q): string {
    return '?' . http_build_query(['status' => $tab, 'q' => $q]);
}
?>

<form method="GET" style="margin-bottom:14px;">
    <input type="hidden" name="status" value="<?= e($status_tab) ?>">
    <input type="text" name="q" class="pill-input" style="background:#fff; border:1.5px solid var(--line); min-width:260px;"
           placeholder="search items / students..." value="<?= e($q) ?>">
</form>

<h1>Manage Requests</h1>
<p class="subtitle">Verify borrow requests and KTM/KTP collateral before items are picked up</p>

<!-- Filter tabs: real links now; the active one is highlighted -->
<div class="tabs">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'returned' => 'Returned', 'rejected' => 'Rejected'] as $key => $label): ?>
        <a class="tab <?= $status_tab === $key ? 'active' : '' ?>" href="<?= e(tab_link($key, $q)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<!-- .table-wrapper makes the table scroll sideways on phones -->
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Student</th><th>Student ID</th><th>Item</th><th>Borrow date</th>
                <th>Return date</th><th>Collateral</th><th>Status</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($loans)): ?>
                <tr><td colspan="8" style="text-align:center;" class="muted">No requests found.</td></tr>
            <?php else: ?>
                <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($loan['full_name']) ?></td>
                    <td><?= e($loan['nim'] ?? '-') ?></td>
                    <td><?= e($loan['items'] ?? 'No items') ?></td>
                    <td><?= e(format_date($loan['borrow_date'], 'd/m')) ?></td>
                    <td><?= e(format_date($loan['return_date'], 'd/m')) ?></td>
                    <td><?= e($loan['collateral']) ?></td>
                    <td><?= status_badge($loan['display_status']) ?></td>
                    <td>
                        <?php
                        // json_encode makes the text safe to put inside a JavaScript call
                        // (names with apostrophes would break the old addslashes() approach).
                        $js_name  = e(json_encode($loan['full_name']));
                        $js_items = e(json_encode($loan['items'] ?? ''));
                        ?>
                        <?php if ($loan['status'] === 'requested'): ?>
                            <!-- Pending: Approve or Reject -->
                            <button class="link-action" onclick="approveLoan(<?= (int)$loan['id'] ?>)">Approve</button>
                            <button class="link-action danger" onclick="rejectLoan(<?= (int)$loan['id'] ?>, <?= $js_name ?>)">Reject</button>

                        <?php elseif ($loan['status'] === 'approved'): ?>
                            <!-- Approved: ready to hand over to the student -->
                            <button class="btn btn-sm btn-primary" onclick="handOverLoan(<?= (int)$loan['id'] ?>)">Hand Over</button>

                        <?php elseif ($loan['status'] === 'borrowed' || $loan['display_status'] === 'overdue'): ?>
                            <!-- Borrowed / overdue: process the return -->
                            <button class="btn btn-sm btn-primary"
                                    onclick="openReturnModal(<?= (int)$loan['id'] ?>, <?= $js_name ?>)">Process Return</button>

                        <?php else: ?>
                            <span class="link-action" style="cursor:default;">Detail</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= render_pagination($page, $total_pages, ['status' => $status_tab, 'q' => $q]) ?>

<!-- ============================================================
     PROCESS RETURN MODAL (pop-up)
     ============================================================ -->
<div class="modal" id="returnModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Process return</h2>
            <button class="close-btn" onclick="hideModal('returnModal')">×</button>
        </div>
        <p class="subtitle" id="modalLoanInfo" style="margin-bottom:16px;">Loan #...</p>
        <div class="modal-error" id="returnError"></div>

        <form id="returnForm">
            <input type="hidden" name="loan_id" id="modalLoanId">

            <div class="form-group">
                <label>Item condition on return</label>
                <select name="condition_after" class="form-control">
                    <option value="good">Good — no damage</option>
                    <option value="minor_damage">Minor damage</option>
                    <option value="damaged">Damaged</option>
                </select>
            </div>

            <!-- Late or on time is NOT chosen by hand: the database function return_loan()
                 compares today with the planned return date and saves returns.was_late itself. -->
            <p class="field-hint" style="margin:-6px 0 14px;">Late returns are detected automatically from the planned return date.</p>

            <div class="form-group">
                <label>Replacement / fine amount (Rp)</label>
                <input type="number" name="compensation_amount" class="form-control" value="0" min="0">
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label class="check-row" style="text-transform:none;">
                    <input type="checkbox" checked required> Collateral (KTM/KTP) returned to the student
                </label>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px; justify-content:flex-end; flex-wrap:wrap;">
                <button type="button" class="btn btn-outline" onclick="hideModal('returnModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm return</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     REJECT MODAL (pop-up)
     ============================================================ -->
<div class="modal" id="rejectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Reject Request</h2>
            <button class="close-btn" onclick="hideModal('rejectModal')">×</button>
        </div>
        <p class="subtitle" id="rejectLoanInfo" style="margin-bottom:16px;">Loan #...</p>

        <div class="form-group">
            <label for="rejectReason">Reason for rejection *</label>
            <textarea id="rejectReason" class="form-control" rows="4" placeholder="e.g., Item is currently unavailable"></textarea>
            <div class="field-hint">This message will be shown to the student.</div>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px; justify-content:flex-end; flex-wrap:wrap;">
            <button type="button" class="btn btn-outline" onclick="hideModal('rejectModal')">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="confirmReject()">Confirm Rejection</button>
        </div>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT for the buttons above
     (postJSON / postForm / showModal / hideModal come from assets/js/main.js)
     ============================================================ -->
<script>
// ---------- Return modal ----------
function openReturnModal(id, name) {
    document.getElementById('modalLoanId').value = id;                           // hidden field = loan id
    document.getElementById('modalLoanInfo').innerText = 'Loan #' + id + ' — ' + name;
    document.getElementById('returnError').style.display = 'none';
    showModal('returnModal');
}

// When the return form is submitted, send it in the background instead of leaving the page
document.getElementById('returnForm').addEventListener('submit', function (event) {
    event.preventDefault();                                                      // stop the normal page reload
    postForm('api/process_return.php', this)
        .then(function (data) {
            if (data.success) { location.reload(); return; }
            var box = document.getElementById('returnError');
            box.innerText = 'Error: ' + data.error;
            box.style.display = 'block';
        })
        .catch(function (err) { alert('Network error: ' + err); });
});

// ---------- Reject modal ----------
var rejectLoanId = null;   // remembers which loan the modal is about

function rejectLoan(id, name) {
    rejectLoanId = id;
    document.getElementById('rejectLoanInfo').innerText = 'Loan #' + id + ' — ' + name;
    document.getElementById('rejectReason').value = '';
    showModal('rejectModal');
    document.getElementById('rejectReason').focus();
}

function confirmReject() {
    var reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Please enter a rejection reason.'); return; }   // client-side check

    postJSON('api/reject_loan.php', { id: rejectLoanId, reason: reason })
        .then(function (data) {
            if (data.success) location.reload();                          // reload to show the new status
            else alert('Error: ' + data.error);
        })
        .catch(function (err) { alert('Network error: ' + err); });
}

// ---------- Approve ----------
function approveLoan(id) {
    if (!confirm('Approve this loan?')) return;                           // browser confirm dialog

    postJSON('api/approve_loan.php', { id: id })
        .then(function (data) {
            if (data.success) location.reload();
            else alert('Error: ' + data.error);
        })
        .catch(function (err) { alert('Network error: ' + err); });
}

// ---------- Hand over ----------
function handOverLoan(id) {
    if (!confirm('Confirm hand over? This will decrease available stock.')) return;

    // FIXED: was 'api/hand_over_loan.php' (file does not exist). The real file is hand_over.php.
    postJSON('api/hand_over.php', { id: id })
        .then(function (data) {
            if (data.success) location.reload();
            else alert('Error: ' + data.error);
        })
        .catch(function (err) { alert('Network error: ' + err); });
}
</script>

<?php include 'includes/footer.php'; ?>
