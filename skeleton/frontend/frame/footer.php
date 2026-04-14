    </main>

    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-section">
                <h4><?= SITE_NAME ?></h4>
                <p>Built with <a href="https://github.com/rmvanda/zero.core">Zero Framework</a></p>
            </div>
            <div class="footer-section">
                <h4>Links</h4>
                <ul class="footer-links">
                    <li><a href="/">Home</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
        </div>
    </footer>

<?php
$this->getComponents();
$this->getScripts();
?>
    <script src="/assets/js/main.js"></script>
</body>
</html>
