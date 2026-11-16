<?php
// The wording a site can change comes from app/strings/*.ini; a site that has
// not copied core's strings/site.ini still gets core's defaults.
$siteName = SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/assets/css/main.css?v=<?= @filemtime(ZERO_ROOT . 'app/frontend/www/assets/css/main.css') ?>">
<?php $this->getStylesheets() ?>
</head>
