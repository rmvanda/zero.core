<?php
/*
 * Included by $this->getAuxiliary('nav'), which resolves
 * MODULE_PATH . Request::$Module . "/aux/nav.php".
 *
 * You do NOT call this yourself. The global frame at
 * app/frontend/frame/header.php calls getAuxiliary("nav") for whichever module
 * is handling the request, so simply creating this file injects the fragment
 * into <shadow-nav>. Calling it again from a view renders it twice.
 *
 * Real modules (Llm, Comfy, System) use this to inject <li slot="subitem">
 * entries into the global shadow-nav. It is a plain include: $this is in scope.
 */
?>
<li slot="subitem" class="skeleton-nav-item">
    <a href="/module/">
        <span class="material-symbols-outlined">extension</span>
        Module Skeleton
    </a>
</li>
