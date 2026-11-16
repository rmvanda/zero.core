<?php use Zero\Core\Strings; Strings::loadModule('Auth'); ?>
<p><?= htmlspecialchars(sprintf(AUTH_EMAIL_LOGIN_LEAD, SITE_SHORT_NAME), ENT_NOQUOTES) ?></p>

<p><a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars(sprintf(AUTH_EMAIL_LOGIN_LINK, SITE_SHORT_NAME), ENT_NOQUOTES) ?></a></p>

<p><?= htmlspecialchars(AUTH_EMAIL_PASTE_LEAD, ENT_NOQUOTES) ?><br>
   <?= htmlspecialchars($link) ?></p>

<p><?= htmlspecialchars(AUTH_EMAIL_LOGIN_EXPIRY, ENT_NOQUOTES) ?>

   <?= htmlspecialchars(AUTH_EMAIL_LOGIN_IGNORE, ENT_NOQUOTES) ?></p>
