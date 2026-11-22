<div class="content-wrapper sitemap-container">
    <h1>Sitemap</h1>
    <p class="sitemap-intro"><?= htmlspecialchars(sprintf(SITEMAP_INTRO, SITE_SHORT_NAME), ENT_NOQUOTES) ?></p>

    <section class="sitemap-section">
        <h2>Static Pages</h2>
        <ul class="sitemap-list">
            <?php foreach ($this->staticPages as $url => $title): ?>
                <li>
                    <a href="<?= htmlspecialchars($url) ?>">
                        <?= htmlspecialchars($title) ?>
                    </a>
                    <span class="sitemap-url"><?= htmlspecialchars($url) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="sitemap-section">
        <h2>Modules</h2>
        <?php foreach ($this->modulesWithRoutes as $module): ?>
            <div class="sitemap-module">
                <h3 class="sitemap-module-name">
                    <span class="material-symbols-outlined">folder</span>
                    <?= htmlspecialchars($module['name']) ?>
                </h3>
                <ul class="sitemap-routes">
                    <?php foreach ($module['routes'] as $route): ?>
                        <li>
                            <a href="<?= htmlspecialchars($route['url']) ?>">
                                <?= htmlspecialchars($route['name']) ?>
                            </a>
                            <span class="sitemap-route-meta">
                                <span class="sitemap-route-type"><?= htmlspecialchars($route['type']) ?></span>
                                <span class="sitemap-url"><?= htmlspecialchars($route['url']) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="sitemap-section">
        <h2>XML Sitemap</h2>
        <p>
            <a href="/sitemap/xml" class="btn-primary">
                <span class="material-symbols-outlined">download</span>
                Download XML Sitemap
            </a>
        </p>
        <p class="sitemap-note">
            For search engines and crawlers. Submit to Google Search Console, Bing Webmaster Tools, etc.
        </p>
    </section>
</div>
