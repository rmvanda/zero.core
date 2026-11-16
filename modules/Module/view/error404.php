<div class="skeleton-page">
    <h1>404</h1>
    <p>
        <code>Response::renderError()</code> looked for
        <code>view/error404.php</code> in this module's already-resolved
        viewPath and found this file. Name yours <code>error{code}.php</code>.
    </p>
    <?php
    /*
     * The HTTPError's $detail string is NOT exposed to this view. renderError()
     * keeps it in a local, hands it to Console::error(), and uses it only in the
     * synthetic fallback HTML rendered when no errorNNN.php exists, and in the
     * JSON body (HTTPError::toArray()), which this view never affects.
     *
     * What a view DOES get is $this->title, which renderError() sets to
     * "Error: {message}" before dispatching here.
     */
    ?>
    <p class="skeleton-detail"><?= htmlspecialchars($this->title ?? '') ?></p>
</div>
