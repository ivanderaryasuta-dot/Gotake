<?php
/**
 * student_catalog.php  —  ITEM CATALOG  (NEW PAGE, "Item Catalog" in PBL.pdf)
 * ------------------------------------------------------------
 * WHAT THIS PAGE SHOWS
 *   All items a student can borrow, as cards: photo, category, name,
 *   storage location, stock (available/total) and a
 *   "Request to borrow" button.
 *
 *   Top of the page has category chips (All / Inventory furniture /
 *   Electronic equipment ...) and a live search box.
 *
 * HOW IT WORKS
 *   - PHP loads every active item + its category from the database.
 *   - Category chips: each card is tagged with data-category="..."
 *     and the small JavaScript at the bottom shows/hides cards.
 *   - The search box uses liveFilter() from assets/js/main.js.
 *   - "Request to borrow" opens student_new_request.php?item_id=NUMBER
 *
 * WHERE / JURUSAN FILTER  (NEW in v3)
 *   At login the student can say where they want to borrow and for which jurusan
 *   ("apa saja" = any). Those choices are kept in the session and used here as
 *   the starting filter; the two dropdowns below the title change them.
 *     - location : the item's storage location (items.location)
 *     - jurusan  : set by the admin in Manage Items. Items WITHOUT a jurusan are
 *                  shared, so they always show.
 *
 * IMAGES
 *   The card shows the FRONT photo uploaded by the admin (Manage Items > Photos).
 *   If there is none, it looks for  assets/img/items/<ITEM CODE>.png  (the old way),
 *   and otherwise shows a blank white box.
 * ------------------------------------------------------------
 */
$page_title = 'Item Catalog';
require_once 'includes/header.php';
require_role('student');

// ---- The filter choices: from the URL (dropdowns) or from what was chosen at login ----
if (isset($_GET['location']) || isset($_GET['jurusan'])) {
    $_SESSION['pref_location'] = trim($_GET['location'] ?? '');
    $_SESSION['pref_jurusan']  = trim($_GET['jurusan']  ?? '');
}
$pref_location = $_SESSION['pref_location'] ?? '';
$pref_jurusan  = $_SESSION['pref_jurusan']  ?? '';

// Load all active items with their category name.
$all_items = $pdo->query("
    SELECT i.id, i.code, i.name, i.available_qty, i.total_qty, i.location,
           c.name AS category
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = TRUE
    ORDER BY c.name, i.name
")->fetchAll();

// Add the jurusan + front photo (stored in the uploads folder), and collect the dropdown lists
$locations = [];
$jurusans  = [];
foreach ($all_items as &$row) {
    $row['jurusan']     = item_jurusan((int)$row['id']);
    $row['front_photo'] = item_photos((int)$row['id'])['front'] ?? null;
    $locations[$row['location']] = $row['location'];
    if ($row['jurusan'] !== '') $jurusans[$row['jurusan']] = $row['jurusan'];
}
unset($row);
sort($locations);
sort($jurusans);

// Keep only the items that match the chosen location / jurusan ("" = any)
$items = array_values(array_filter($all_items, function ($it) use ($pref_location, $pref_jurusan) {
    if ($pref_location !== '' && $it['location'] !== $pref_location) return false;
    if ($pref_jurusan  !== '' && $it['jurusan'] !== '' && $it['jurusan'] !== $pref_jurusan) return false;   // shared items always show
    return true;
}));

// Build the list of category chips from the items themselves
$categories = array_values(array_unique(array_column($items, 'category')));
?>

<!-- ============ BLUE HERO BANNER with chips + search ============ -->
<section class="hero">
    <h1>Item Catalog</h1>
    <p class="subtitle">Choose the lab equipment or facility item you want to borrow</p>

    <div class="tabs" style="margin-bottom:0;">
        <!-- Chips: data-filter="all" shows everything -->
        <button type="button" class="tab active" data-filter="all">All</button>
        <?php foreach ($categories as $cat): ?>
            <button type="button" class="tab" data-filter="<?= e($cat) ?>"><?= e($cat) ?></button>
        <?php endforeach; ?>

        <input type="text" id="catalogSearch" class="pill-input push-right" placeholder="Search item...">
    </div>

    <!-- Where / jurusan: choosing a value reloads the page with ?location=...&jurusan=... -->
    <form method="GET" class="tabs" style="margin:14px 0 0;">
        <select name="location" class="pill-input" onchange="this.form.submit()" aria-label="Where do you want to borrow?">
            <option value="">Anywhere (apa saja)</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= e($loc) ?>" <?= $pref_location === $loc ? 'selected' : '' ?>><?= e($loc) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="jurusan" class="pill-input" onchange="this.form.submit()" aria-label="Jurusan">
            <option value="">All jurusan (apa saja)</option>
            <?php foreach ($jurusans as $j): ?>
                <option value="<?= e($j) ?>" <?= $pref_jurusan === $j ? 'selected' : '' ?>><?= e($j) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($pref_location !== '' || $pref_jurusan !== ''): ?>
            <a href="?location=&jurusan=" class="tab" style="background:#fff;">Clear filters</a>
        <?php endif; ?>
    </form>
</section>

<?= flash_render() /* e.g. "Please choose an item from the catalog first." */ ?>

<!-- ============ ITEM CARDS ============ -->
<div class="catalog-grid" id="catalogGrid">
    <?php if (empty($items)): ?>
        <p class="muted">No items match your filters. Try "Anywhere" or "All jurusan".</p>
    <?php endif; ?>

    <?php foreach ($items as $it):
        $available = (int)$it['available_qty'] > 0;   // true if at least 1 is in stock
    ?>
    <article class="item-card" data-category="<?= e($it['category']) ?>">

        <!-- Front photo uploaded by the admin, or the blank slot assets/img/items/<CODE>.png -->
        <div class="item-photo">
            <?= item_photo_html($it['front_photo'], $it['code'], $it['name']) ?>
        </div>

        <div class="item-body">
            <div class="item-category"><?= e($it['category']) ?></div>
            <div class="item-name"><?= e($it['name']) ?></div>
            <div class="item-meta"><?= e($it['location'] ?: 'Location not set') ?></div>
            <div class="item-meta">Stock <?= (int)$it['available_qty'] ?>/<?= (int)$it['total_qty'] ?></div>

            <div class="item-footer">
                <?php if ($available): ?>
                    <span class="badge available">Available</span>
                    <a class="btn" href="student_new_request.php?item_id=<?= (int)$it['id'] ?>">Request to borrow</a>
                <?php else: ?>
                    <span class="badge unavailable">Not Available</span>
                    <span class="btn disabled">Request to borrow</span>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<script>
// ---- Category chips: show only cards of the chosen category ----
document.querySelectorAll('.tab[data-filter]').forEach(function (chip) {
    chip.addEventListener('click', function () {
        // 1. Move the blue "active" look to the clicked chip
        document.querySelectorAll('.tab[data-filter]').forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');

        // 2. Show/hide cards (class "hidden-by-filter" = display:none, see style.css).
        //    The search box uses a different class, so both filters work together.
        var wanted = chip.getAttribute('data-filter');
        document.querySelectorAll('.item-card').forEach(function (card) {
            var show = (wanted === 'all') || (card.getAttribute('data-category') === wanted);
            card.classList.toggle('hidden-by-filter', !show);
        });
    });
});

// ---- Live search (function lives in assets/js/main.js) ----
document.addEventListener('DOMContentLoaded', function () {
    liveFilter('catalogSearch', '.item-card');
});
</script>

<?php include 'includes/footer.php'; ?>
