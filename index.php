<?php
/**
 * index.php  —  ADMIN DASHBOARD  ("Admin Dashboard" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   - A yellow banner with a search box
 *   - 4 stat cards: total items, currently borrowed, pending approval,
 *     overdue returns   (data from the database VIEW  v_admin_dashboard,
 *     exactly like your previous version)
 *   - A "Recent Borrow Requests" table with pagination (5 per page)
 *
 * SEARCH + PAGINATION (how they work)
 *   The search box is a small <form method="GET">, so the typed text
 *   arrives in the URL (index.php?q=laptop&page=2) and PHP reads it
 *   with $_GET. The SQL then uses LIMIT (how many rows) and OFFSET
 *   (how many rows to skip) to return only the current page.
 *
 * NOTE: students are redirected to their own dashboard (require_role).
 * ------------------------------------------------------------
 */
$page_title = 'Admin Dashboard';
require_once 'includes/header.php';
require_role('admin');

// ---- 1. Stat cards (one row from the view). "?: []" avoids errors if the view is empty.
$stats = $pdo->query("SELECT * FROM v_admin_dashboard")->fetch() ?: [];

// ---- 2. Search text and current page number from the URL
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 5;

// ---- 3. Build the WHERE part only when the admin typed something
$where  = '';
$params = [];
if ($q !== '') {
    // ILIKE = case-insensitive LIKE (PostgreSQL). % means "anything".
    $where  = "WHERE full_name ILIKE ? OR CAST(nim AS TEXT) ILIKE ? OR items ILIKE ?";
    $like   = '%' . $q . '%';
    $params = [$like, $like, $like];
}

// ---- 4. How many rows in total? -> how many pages?
$count = $pdo->prepare("SELECT COUNT(*) FROM v_loan_overview $where");
$count->execute($params);
$total       = (int)$count->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);            // never beyond the last page
$offset      = ($page - 1) * $per_page;             // rows to skip

// ---- 5. The rows of THIS page ($per_page and $offset are integers we computed, so they are safe)
$list = $pdo->prepare("SELECT * FROM v_loan_overview $where ORDER BY id DESC LIMIT $per_page OFFSET $offset");
$list->execute($params);
$loans = $list->fetchAll();
?>

<!-- ============ YELLOW HERO ============ -->
<section class="hero hero-yellow">
    <div class="hero-top">
        <div>
            <h1>Admin Dashboard</h1>
            <p class="subtitle">Overview of campus item borrowing activity</p>
        </div>

        <!-- GET form: pressing Enter reloads this page as index.php?q=... -->
        <form method="GET">
            <input type="text" name="q" class="pill-input" placeholder="Search items/students..." value="<?= e($q) ?>">
        </form>
    </div>

    <div class="stat-row">
        <div class="stat-card blue">
            <div class="stat-label">Total items</div>
            <div class="stat-value"><?= (int)($stats['total_items'] ?? 0) ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Currently borrowed</div>
            <div class="stat-value"><?= (int)($stats['currently_borrowed'] ?? 0) ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Pending approval</div>
            <div class="stat-value"><?= (int)($stats['pending_approval'] ?? 0) ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Overdue return</div>
            <div class="stat-value"><?= (int)($stats['overdue_return'] ?? 0) ?></div>
        </div>
    </div>
</section>

<!-- ============ RECENT BORROW REQUESTS ============ -->
<h2 class="section-title" style="margin-top:0;">Recent Borrow Requests</h2>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Student name</th><th>Student ID</th><th>Item</th><th>Borrow date</th>
                <th>Status</th><th>Collateral</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($loans)): ?>
                <tr><td colspan="7" style="text-align:center;" class="muted">No requests found.</td></tr>
            <?php else: ?>
                <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($loan['full_name']) ?></td>
                    <td><?= e($loan['nim'] ?? '-') ?></td>
                    <td><?= e($loan['items'] ?? 'No items') ?></td>
                    <td><?= e(format_date($loan['borrow_date'], 'd/m/Y')) ?></td>
                    <td><?= status_badge($loan['display_status']) ?></td>
                    <td><?= e($loan['collateral']) ?></td>
                    <!-- "Detail" opens the full loan page (photos, damage report) -->
                    <td><a class="link-action" href="admin_loan_detail.php?id=<?= (int)$loan['id'] ?>">Detail</a></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= render_pagination($page, $total_pages, ['q' => $q]) ?>

<?php include 'includes/footer.php'; ?>
