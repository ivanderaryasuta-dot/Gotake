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
 * IMAGES
 *   Each card looks for a picture at  assets/img/items/<ITEM CODE>.png
 *   (e.g. an item with code PRJ-01 -> assets/img/items/PRJ-01.png).
 *   If it does not exist the card shows a blank white box.
 * ------------------------------------------------------------
 */
$page_title = 'Item Catalog';
require_once 'includes/header.php';
require_role('student');

// Load all active items with their category name.
// (location was added by database/update_schema.sql)
$items = $pdo->query("
    SELECT i.id, i.code, i.name, i.available_qty, i.total_qty, i.location,
           c.name AS category
    FROM items i
    JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = TRUE
    ORDER BY c.name, i.name
")->fetchAll();

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
</section>

<?= flash_render() /* e.g. "Please choose an item from the catalog first." */ ?>

<!-- ============ ITEM CARDS ============ -->
<div class="catalog-grid" id="catalogGrid">
    <?php if (empty($items)): ?>
        <p class="muted">No items are available yet.</p>
    <?php endif; ?>

    <?php foreach ($items as $it):
        $available = (int)$it['available_qty'] > 0;   // true if at least 1 is in stock
    ?>
    <article class="item-card" data-category="<?= e($it['category']) ?>">

        <!-- Item photo slot: assets/img/items/<CODE>.png -->
        <div class="item-photo">
            <?= img_slot('items/' . $it['code'], '', $it['name']) ?>
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
