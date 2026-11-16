<?php
// Keycloak TEST page. Two states:
//  - "before": show the Login with Keycloak button (entry to /auth/user?via=11)
//  - "after":  show confirmation + key claims once login completed via Keycloak
$loggedInViaKeycloak = (($_SESSION['login_provider'] ?? null) === 'keycloak');
?>
<main class="keycloak-test">
    <h1 class="keycloak-test__title">Keycloak Test</h1>

    <?php if ($loggedInViaKeycloak): ?>
        <div class="keycloak-test__result">
            <p class="keycloak-test__status">
                <span class="material-symbols-outlined">check_circle</span>
                Logged in via Keycloak
            </p>

            <dl class="keycloak-test__claims">
                <dt>Name</dt>
                <dd><?= htmlspecialchars($_SESSION['name'] ?? '') ?></dd>
                <dt>Email</dt>
                <dd><?= htmlspecialchars($_SESSION['email'] ?? '') ?></dd>
                <dt>Verified</dt>
                <dd><?= !empty($_SESSION['verified']) ? 'true' : 'false' ?></dd>
                <dt>User ID</dt>
                <dd><?= htmlspecialchars((string)(\Zero\Core\Session::userId() ?? '')) ?></dd>
            </dl>

            <a class="keycloak-test__logout" href="/auth/logout">Log out</a>
        </div>
    <?php else: ?>
        <p class="keycloak-test__intro">Authenticate against the Keycloak realm to test the integration.</p>
        <a class="keycloak-test__button" href="/auth/user?via=11">
            <span class="material-symbols-outlined">login</span>
            Login with Keycloak
        </a>
    <?php endif; ?>
</main>
