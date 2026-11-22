<?php
// The page the sign-in screen links to (/privacy). It says what the framework
// itself keeps, which is all core can speak for; a site with more to say
// writes its own in its own modules/Index/view/privacy.php.
?>
<div class="welcome">
    <h1>Privacy</h1>
    <p>This is what <?= htmlspecialchars(SITE_NAME) ?> keeps about you when you sign in.</p>

    <h2>What is stored</h2>
    <ul>
        <li>Your email address, which identifies your account.</li>
        <li>Your name and profile picture, when the service you sign in with provides them.</li>
        <li>A session cookie, which keeps you signed in on this browser.</li>
        <li>A record of some actions with the time and your IP address, kept to see what was done and by whom.</li>
    </ul>

    <h2>What it is used for</h2>
    <p>To sign you in, to connect you to what you create here, and to keep the site secure. Nothing else.</p>

    <h2>Questions</h2>
    <p>Ask the people who run this site.</p>
</div>
