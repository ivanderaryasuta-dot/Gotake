<?php
/**
 * manage_items.php  —  MANAGE ITEMS  (NEW PAGE, "Manage Items" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE DOES
 *   The admin's inventory list. The admin can:
 *     - filter by category (tabs) and by availability (dropdown)
 *     - ADD a new item     (button "+ ADD ITEM")
 *     - EDIT an item       ("Edit" in the row)
 *     - DELETE an item     ("Delete" in the row)
 *
 * HOW ADD / EDIT / DELETE WORK
 *   A pop-up form (modal) collects the data, JavaScript sends it to
 *   api/save_item.php or api/delete_item.php, and the page reloads.
 *   One modal is used for both Add and Edit: for Edit, its fields are
 *   pre-filled from the row's  data-item  attribute (JSON).
 *
 * "STOCK" EXPLAINED
 *   available_qty = how many are in storage right now
 *   total_qty     = how many the campus owns
 *   The table shows  available/total  (e.g. 5/8).
 * ------------------------------------------------------------
 */
$page_title = 'Manage Items';
require_once 'includes/header.php';
require_role('admin');

// ---- 1. Filters from the URL ----
$cat_filter    = (int)($_GET['category'] ?? 0);          // 0 = all categories
$status_filter = $_GET['status'] ?? 'all';               // all | available | unavailable
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 8;

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// ---- 2. WHERE clause (only active items; deleted ones are hidden) ----
$conditions = ['i.is_active = TRUE'];
$params     = [];
if ($cat_filter > 0) { $conditions[] = 'i.category_id = ?'; $params[] = $cat_filter; }
if ($status_filter === 'available')   $conditions[] = 'i.available_qty > 0';
if ($status_filter === 'unavailable') $conditions[] = 'i.available_qty = 0';
$where = 'WHERE ' . implode(' AND ', $conditions);

// ---- 3. Pagination + rows ----
$count = $pdo->prepare("SELECT COUNT(*) FROM items i $where");
$count->execute($params);
$total_pages = max(1, (int)ceil(((int)$count->fetchColumn()) / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT i.id, i.code, i.name, i.category_id, i.location, i.available_qty, i.total_qty,
           c.name AS category
    FROM items i
    JOIN categories c ON c.id = i.category_id
    $where
    ORDER BY i.name
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// Helper: URL for a category tab, keeping the status filter
function cat_link(int $cat, string $status): string {
    return '?' . http_build_query(['category' => $cat, 'status' => $status]);
}
?>

<div class="hero-top" style="margin-bottom:6px;">
    <div>
        <h1>Manage Items</h1>
        <p class="subtitle">Inventory data for lab equipment items</p>
    </div>
    <button class="btn btn-outline" style="background:var(--card-grey); border:none; padding:12px 22px;" onclick="openItemModal(null)">+ ADD ITEM</button>
</div>

<!-- Category tabs + availability filter -->
<div class="tabs">
    <a class="tab <?= $cat_filter === 0 ? 'active' : '' ?>" href="<?= e(cat_link(0, $status_filter)) ?>">All</a>
    <?php foreach ($categories as $c): ?>
        <a class="tab <?= $cat_filter === (int)$c['id'] ? 'active' : '' ?>"
           href="<?= e(cat_link((int)$c['id'], $status_filter)) ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>

    <!-- Changing the dropdown submits the little form immediately (onchange) -->
    <form method="GET" class="push-right">
        <input type="hidden" name="category" value="<?= (int)$cat_filter ?>">
        <select name="status" class="pill-input" onchange="this.form.submit()">
            <option value="all"         <?= $status_filter === 'all' ? 'selected' : '' ?>>Filter Status...</option>
            <option value="available"   <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="unavailable" <?= $status_filter === 'unavailable' ? 'selected' : '' ?>>Not Available</option>
        </select>
    </form>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Item name</th><th>Category</th><th>Location</th><th>Available stock</th>
                <th>Status</th><th>Collateral</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="7" style="text-align:center;" class="muted">No items found.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $it): $in_stock = (int)$it['available_qty'] > 0; ?>
                <tr>
                    <td><?= e($it['name']) ?></td>
                    <td><?= e($it['category']) ?></td>
                    <td><?= e($it['location'] ?: '—') ?></td>
                    <td><?= (int)$it['available_qty'] ?>/<?= (int)$it['total_qty'] ?></td>
                    <td><span class="badge <?= $in_stock ? 'available' : 'unavailable' ?>"><?= $in_stock ? 'Available' : 'Not Available' ?></span></td>
                    <td>KTM/KTP</td>
                    <td>
                        <!-- data-item holds this row as JSON so the Edit modal can be pre-filled -->
                        <button class="link-action" data-item="<?= e(json_encode($it)) ?>" onclick="openItemModal(this)">Edit</button>
                        <button class="link-action danger" onclick="deleteItem(<?= (int)$it['id'] ?>, <?= e(json_encode($it['name'])) ?>)">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?= render_pagination($page, $total_pages, ['category' => $cat_filter, 'status' => $status_filter]) ?>

<!-- ============================================================
     ADD / EDIT ITEM MODAL
     ============================================================ -->
<div class="modal" id="itemModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="itemModalTitle">Add item</h2>
            <button class="close-btn" onclick="hideModal('itemModal')">×</button>
        </div>
        <div class="modal-error" id="itemError"></div>

        <form id="itemForm">
            <input type="hidden" id="item_id" value="">   <!-- empty = adding, a number = editing -->

            <div class="grid-two-col" style="gap:14px;">
                <div class="form-group">
                    <label for="item_code">Item code</label>
                    <input type="text" id="item_code" class="form-control" placeholder="e.g. PRJ-01" required>
                </div>
                <div class="form-group">
                    <label for="item_total">Total quantity</label>
                    <input type="number" id="item_total" class="form-control" min="1" value="1" required>
                </div>
            </div>

            <div class="form-group">
                <label for="item_name">Item name</label>
                <input type="text" id="item_name" class="form-control" placeholder="e.g. Epson Projector" required>
            </div>

            <div class="form-group">
                <label for="item_category">Category</label>
                <select id="item_category" class="form-control" required>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="item_location">Storage location *</label>
                <input type="text" id="item_location" class="form-control" placeholder="e.g. Server room" required>
            </div>

            <div class="field-hint" style="margin-bottom:14px;">
                Item photo: put a file named <strong>&lt;item code&gt;.png</strong> in <em>assets/img/items/</em>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="hideModal('itemModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save item</button>
            </div>
        </form>
    </div>
</div>

<script>
// Opens the modal. btn === null  -> ADD mode (empty form).
//                  btn === button -> EDIT mode (fill the form from its data-item JSON).
function openItemModal(btn) {
    var it = btn ? JSON.parse(btn.getAttribute('data-item')) : null;

    document.getElementById('itemModalTitle').innerText = it ? 'Edit item' : 'Add item';
    document.getElementById('item_id').value       = it ? it.id : '';
    document.getElementById('item_code').value     = it ? it.code : '';
    document.getElementById('item_name').value     = it ? it.name : '';
    document.getElementById('item_total').value    = it ? it.total_qty : 1;
    document.getElementById('item_location').value = it && it.location ? it.location : '';
    if (it) document.getElementById('item_category').value = it.category_id;

    document.getElementById('itemError').style.display = 'none';
    showModal('itemModal');
}

// Save (add or edit)
document.getElementById('itemForm').addEventListener('submit', function (event) {
    event.preventDefault();   // don't reload the page; we send it ourselves

    postJSON('api/save_item.php', {
        id:          document.getElementById('item_id').value,
        code:        document.getElementById('item_code').value.trim(),
        name:        document.getElementById('item_name').value.trim(),
        category_id: document.getElementById('item_category').value,
        total_qty:   document.getElementById('item_total').value,
        location:    document.getElementById('item_location').value.trim()
    }).then(function (data) {
        if (data.success) { location.reload(); return; }
        var box = document.getElementById('itemError');
        box.innerText = data.error;
        box.style.display = 'block';
    }).catch(function (err) { alert('Network error: ' + err); });
});

// Delete (the server only hides the item, see api/delete_item.php)
function deleteItem(id, name) {
    if (!confirm('Delete "' + name + '"?')) return;
    postJSON('api/delete_item.php', { id: id }).then(function (data) {
        if (data.success) location.reload();
        else alert('Error: ' + data.error);
    }).catch(function (err) { alert('Network error: ' + err); });
}
</script>

<?php include 'includes/footer.php'; ?>
