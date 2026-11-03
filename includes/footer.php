<?php
// ============================================================
// NOTIFICATION POP-UP (students only)            — v3: all types
// ------------------------------------------------------------
// Shows every UNREAD notification of the student in one pop-up, on whatever
// page they open next. Types written by the system:
//   approved     (database function approve_loan)   -> "Ready for pick-up!" + where/when
//   rejected     (database function reject_loan)    -> reason
//   picked_up    (api/hand_over.php)                 -> "Item picked up"
//   returned     (api/process_return.php)            -> "Item returned"
//   damage_fine  (api/process_return.php)            -> amount + description
//   fine_paid    (api/mark_fine_paid.php)            -> thank you
// (An 'approved' notice only matters until the items are picked up, so it is hidden after that.)
// "Got it" marks them as read (api/dismiss_pickup.php) so they do not return.
// ============================================================
$popups = [];
if (($role ?? '') === 'student') {
    try {
        $stmt = $pdo->prepare("
            SELECT n.id, n.type, n.message,
                   l.status AS loan_status, l.borrow_date, l.return_date, l.collateral,
                   (SELECT string_agg(i.name || ' x' || li.qty, ', ' ORDER BY i.name)
                      FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = l.id) AS items,
                   (SELECT string_agg(DISTINCT i.location, ', ')
                      FROM loan_items li JOIN items i ON i.id = li.item_id WHERE li.loan_id = l.id) AS location
            FROM notifications n
            LEFT JOIN loans l ON l.id = n.loan_id
            WHERE n.user_id = ? AND n.is_read = FALSE
              AND (n.type <> 'approved' OR l.status = 'approved')
            ORDER BY n.id DESC
            LIMIT 10
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $popups = $stmt->fetchAll();
    } catch (Exception $e) { $popups = []; }   // never break a page because of a pop-up
}
$popup_titles = [
    'approved'    => 'Ready for pick-up!',
    'rejected'    => 'Request rejected',
    'picked_up'   => 'Item picked up',
    'returned'    => 'Item returned',
    'damage_fine' => 'Damage fine',
    'fine_paid'   => 'Fine paid',
];
?>
            </div>  <!-- close .content -->
        </main>     <!-- close .main-content -->
    </div>          <!-- close .app-container -->

    <?php if ($popups): ?>
    <!-- Notification pop-up: same .modal styles as the other pop-ups -->
    <div class="modal active" id="noticeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Notifications</h2>
                <button class="close-btn" onclick="hideModal('noticeModal')" title="Remind me later">×</button>
            </div>

            <?php foreach ($popups as $n): ?>
                <div class="notice <?= e($n['type']) ?>">
                    <strong><?= e($popup_titles[$n['type']] ?? 'Notification') ?></strong>

                    <?php if ($n['type'] === 'approved'):
                        $future = strtotime($n['borrow_date']) > strtotime('today'); ?>
                        <?= e($n['items']) ?><br>
                        Pick up at: <?= e($n['location']) ?><br>
                        <?= $future ? 'Available from: ' : 'Pick up now. Borrow date: ' ?><?= e(format_date($n['borrow_date'])) ?><br>
                        Return by: <?= e(format_date($n['return_date'])) ?><br>
                        Bring your <?= e($n['collateral']) ?>; the staff will hold it until you return the item.
                    <?php else: ?>
                        <?= e($n['message']) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="btn-row" style="margin-top:6px;">
                <button class="btn btn-primary" onclick="dismissNotices()">Got it</button>
                <a href="student_borrowings.php" class="btn btn-outline">My Borrowings</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    /**
     * toggleSidebar()  —  opens/closes the sidebar on PHONES.
     * Adds or removes the class "open" on the sidebar and "active" on the dark
     * overlay. The CSS (assets/css/style.css, section 17) does the sliding.
     */
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    /**
     * Close the sidebar automatically after tapping a menu link on a phone.
     * Without this the menu would stay open after you navigate.
     */
    document.querySelectorAll('.sidebar nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });
    });
    </script>

    <!-- Shared helper functions (modals, fetch, table search) used by many pages -->
    <script src="assets/js/main.js"></script>

    <?php if ($popups): ?>
    <script>
    // "Got it": tell the server these notifications were seen, then close the pop-up.
    function dismissNotices() {
        postJSON('api/dismiss_pickup.php', {
            ids: <?= json_encode(array_map('intval', array_column($popups, 'id'))) ?>
        }).then(function () { hideModal('noticeModal'); })
          .catch(function () { hideModal('noticeModal'); });
    }
    </script>
    <?php endif; ?>
</body>
</html>
<?php
// Send the buffered page to the browser (the matching ob_start() is in header.php).
if (ob_get_level() > 0) ob_end_flush();
?>
