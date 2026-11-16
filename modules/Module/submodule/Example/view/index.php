<div class="example-page">
    <h1>Submodule</h1>
    <p>
        This page is served by a submodule. The parent module had no method
        matching the URL segment, so <code>Module::__call</code> resolved the
        matching directory under <code>submodule/</code> instead. Submodules
        nest infinitely.
    </p>
    <p>
        Its namespace is the <em>parent</em> module's full name, with no
        <code>submodule</code> segment. It is styled by its own stylesheet under
        <code>submodule/&lt;Name&gt;/assets/css/</code> &mdash; frames are
        inherited by walking up the tree, but <strong>assets are not</strong>.
    </p>
    <?php /* MKMOD:SKELETON-ONLY-START */ ?>
    <p>
        In the skeleton this page is <code>/module/example</code>, generated from
        <code>core/modules/Module/submodule/Example/</code>.
    </p>
    <?php /* MKMOD:SKELETON-ONLY-END */ ?>
</div>
