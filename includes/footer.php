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
if (($role ?? '') === 'student' && empty($hide_popup)) {
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
    'clearance_requested' => 'Permission form needed',
];
?>
            </div>  <!-- close .content -->
        </main>     <!-- close .main-content -->
    </div>          <!-- close .app-container -->

    <?php if ($popups && USE_BOOTSTRAP): ?>
    <!-- Notification pop-up as a Bootstrap TOAST (top right). "x" = remind me later, "Got it" = mark as read. -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:3000;">
        <div class="toast gt-popup" id="noticeToast" role="alert" aria-live="assertive" aria-atomic="true"
             data-gt-show data-bs-autohide="false" style="width:min(400px, 92vw);">
            <div class="toast-header">
                <strong class="me-auto">Notifications</strong>
                <small class="text-body-secondary"><?= count($popups) ?> new</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close" title="Remind me later"></button>
            </div>
            <div class="toast-body" style="max-height:70vh; overflow-y:auto;">
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

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-sm" onclick="dismissNotices()">Got it</button>
                    <a href="notifications.php" class="btn btn-outline btn-sm">All notifications</a>
                    <a href="student_borrowings.php" class="btn btn-outline btn-sm">My Borrowings</a>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($popups): ?>
    <!-- Bootstrap switched off: the old pop-up (same .modal styles as the other pop-ups) -->
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
                <a href="notifications.php" class="btn btn-outline">All notifications</a>
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

    <!-- Bootstrap JavaScript (toasts) - on/off switch: includes/bootstrap_assets.php -->
    <?= bootstrap_script() ?>

    <!-- Shared helper functions (modals, fetch, table search) used by many pages -->
    <script src="assets/js/main.js"></script>

    <?php if ($popups): ?>
    <script>
    // "Got it": tell the server these notifications were seen, then close the pop-up.
    function dismissNotices() {
        postJSON('api/dismiss_pickup.php', {
            ids: <?= json_encode(array_map('intval', array_column($popups, 'id'))) ?>
        }).then(closeNotices)
          .catch(closeNotices);
    }
    // Closes the toast (Bootstrap) or the old pop-up
    function closeNotices() {
        var toast = document.getElementById('noticeToast');
        if (toast) {
            if (window.bootstrap) bootstrap.Toast.getOrCreateInstance(toast).hide(); else toast.style.display = 'none';
        } else {
            hideModal('noticeModal');
        }
    }
    </script>
    <?php endif; ?>
</body>
</html>
<?php
// Send the buffered page to the browser (the matching ob_start() is in header.php).
if (ob_get_level() > 0) ob_end_flush();
?>
