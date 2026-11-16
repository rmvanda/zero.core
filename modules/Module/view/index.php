<div class="skeleton-page">
    <h1>Module Skeleton</h1>

    <?php /* MKMOD:SKELETON-ONLY-START */ ?>
    <p>
        You are looking at <code>core/modules/Module/</code>, the reference module.
        Every directory in it is a worked example of something the framework
        or a project convention understands.
    </p>
    <?php /* MKMOD:SKELETON-ONLY-END */ ?>

    <?php if (isset($this->echo)): ?>
        <p class="skeleton-echo">ping echoed: <?= htmlspecialchars($this->echo) ?></p>
    <?php endif; ?>

    <module-panel>
        <span slot="heading">This is a ShadowComponent</span>
        It lives in <code>assets/component/module-panel.html</code> and was
        registered automatically by <code>Response::getComponents()</code>.
    </module-panel>

    <h2>Try it</h2>
    <ul>
        <li><a href="/module/ping/hello">/module/ping/hello</a> &mdash; URL segment becomes an argument</li>
        <li><a href="/module/not-found">/module/not-found</a> &mdash; renders <code>view/error404.php</code></li>
        <?php /* MKMOD:SKELETON-ONLY-START */ ?>
        <li><a href="/module/example">/module/example</a> &mdash; the submodule</li>
        <?php /* MKMOD:SKELETON-ONLY-END */ ?>
    </ul>
</div>
