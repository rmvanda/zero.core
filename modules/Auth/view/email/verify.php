<?php use Zero\Core\Strings; Strings::loadModule('Auth'); ?>
<p><?= htmlspecialchars(\Zero\Core\Strings::fill(AUTH_EMAIL_VERIFY_LEAD, SITE_SHORT_NAME), ENT_NOQUOTES) ?></p>

<p><?= htmlspecialchars(AUTH_EMAIL_VERIFY_INSTRUCTIONS, ENT_NOQUOTES) ?>

   <?= htmlspecialchars(AUTH_EMAIL_VERIFY_EXPIRY, ENT_NOQUOTES) ?></p>

<p><a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars(AUTH_EMAIL_VERIFY_LINK, ENT_NOQUOTES) ?></a></p>

<p><?= htmlspecialchars(AUTH_EMAIL_PASTE_LEAD, ENT_NOQUOTES) ?><br>
   <?= htmlspecialchars($link) ?></p>

<p><?= htmlspecialchars(\Zero\Core\Strings::fill(AUTH_EMAIL_VERIFY_IGNORE, SITE_SHORT_NAME), ENT_NOQUOTES) ?>

   <?= htmlspecialchars(AUTH_EMAIL_VERIFY_IGNORE_REST, ENT_NOQUOTES) ?></p>
