<?php
$siteName = SITE_NAME;
?>
</main>

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></p>
</footer>

<?php $this->getScripts() ?>
</body>
</html>
