<?php
// Who is looking, from the session. The wording constants come from
// app/strings/*.ini; core defines English for any key the site leaves out
// (core/skeleton/strings/).
$siteName = SITE_NAME;
$signedIn = \Zero\Core\Session::isLoggedIn();
$who = $signedIn ? \Zero\Core\Session::user() : null;
?>
<body>
<a class="skip-link" href="#main"><?= htmlspecialchars(SITE_SKIP_TO_CONTENT) ?></a>

<header class="site-header">
    <a class="site-brand" href="/"><?= htmlspecialchars($siteName) ?></a>
    <nav class="site-nav" aria-label="Main">
        <a href="/"><?= htmlspecialchars(SITE_HOME) ?></a>
<?php if ($signedIn && \Zero\Core\Session::can('allow.admin')): ?>
        <a href="/admin"><?= htmlspecialchars(SITE_ADMIN) ?></a>
<?php endif ?>
<?php if ($signedIn): ?>
<?php if ($who !== null && (string) $who->name !== ''): ?>
        <span class="site-user"><?= htmlspecialchars((string) $who->name) ?></span>
<?php endif ?>
        <a href="/auth/logout"><?= htmlspecialchars(SITE_SIGN_OUT) ?></a>
<?php else: ?>
        <a href="/user/login"><?= htmlspecialchars(SITE_SIGN_IN) ?></a>
<?php endif ?>
    </nav>
</header>

<main id="main" class="site-main">
