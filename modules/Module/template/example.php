<?php
/*
 * template/ is convention, not framework. Nothing in core/ reads it.
 *
 * Use it for render fragments that are not endpoint views: an email body, a row
 * partial included in a loop, a PDF layout. Views (which the framework resolves
 * via $this->viewPath) belong in view/.
 *
 * Notebook/template/ and Issue/template/ are the working examples.
 */
?>
<div class="skeleton-fragment">
    <?= htmlspecialchars($label ?? 'Example fragment') ?>
</div>
