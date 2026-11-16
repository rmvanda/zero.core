<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $this->pageTitle ?? 'Module' ?></title>

    <link rel="stylesheet" href="/assets/css/main.css">
    <?php
    // A site adds its own scripts (a component library, analytics) and fonts here,
    // after the stylesheets, in app/frontend/frame/_head-scripts.php. The piece is
    // found through the same lookup frames use; core's own copy is empty.
    if ($headScripts = $this->framePiecePath('_head-scripts')) {
        include $headScripts;
    }
    ?>

    <?php
    /*
     * A module that overrides frame/head.php takes over the <head> entirely.
     * These three calls are what re-enable the framework's auto-loading.
     * Delete them and your module's CSS, JS, and components silently vanish.
     *
     * Submodules with no frame/ of their own inherit this file: defineBasePaths()
     * walks up the directory tree until it finds one.
     */
    $this->getStylesheets();
    $this->getScripts();
    $this->getComponents();
    ?>
</head>
