<?php
/*
 * This module's admin CSS is inlined automatically — there is no manual
 * loading step to call from a view. Auto mode already inlines it, and that
 * is the documented preference (core/docs/ADMIN_ASSETS.md).
 *
 * AdminResponse::getStylesheets() -> inlineAdminAssets('css') searches
 *   1. core/modules/Admin/assets/css/   (shared base: admin.css)
 *   2. {this module}/Admin/assets/css/
 * for filenames matching: admin.css, {moduleName}.css, {endpoint}.css.
 *
 * $this->moduleName is strtolower(class basename), which is why this
 * module's stylesheet is named to match. Auth, Llm and Comfy all follow
 * this convention.
 *
 * (An older, manual layout globs Admin/assets/*.css FLAT, not
 * Admin/assets/css/. Links and S use that older layout. Don't mix them.)
 */
?>

<div class="content-wrapper">
    <h1>Manage Module</h1>
    <?php /* MKMOD:SKELETON-ONLY-START */ ?>
    <p class="skeleton-admin-note">
        This is the skeleton's admin interface. It is registered by the
        existence of <code>Module/Admin/</code> and described by
        <code>Module/Admin/admin-config.php</code>.
    </p>
    <?php /* MKMOD:SKELETON-ONLY-END */ ?>
</div>
