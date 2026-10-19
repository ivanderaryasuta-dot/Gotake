            </div>  <!-- close .auth-card -->
        </div>      <!-- close .auth-panel -->
    </div>          <!-- close .auth-wrap -->
</body>
</html>
<?php
// Send the buffered page to the browser (matching ob_start() is in auth_header.php).
if (ob_get_level() > 0) ob_end_flush();
?>
