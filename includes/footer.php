            </div>  <!-- close .content -->
        </main>     <!-- close .main-content -->
    </div>          <!-- close .app-container -->

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
</body>
</html>
<?php
// Send the buffered page to the browser (the matching ob_start() is in header.php).
if (ob_get_level() > 0) ob_end_flush();
?>
