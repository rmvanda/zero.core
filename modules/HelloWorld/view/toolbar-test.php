<!-- Styles extracted to assets/css/toolbar-test.css (auto-loaded by framework) -->

<h1>DevToolbar Test Page</h1>

<div class="info-box">
    <h2>Debug Info:</h2>
    <p><strong>Remote Address:</strong> <?= $_SERVER['REMOTE_ADDR'] ?></p>
    <p><strong>DEV_SUBNET:</strong> <?= DEV_SUBNET ?></p>
    <p><strong>DEVMODE:</strong> <?= defined('DEVMODE') && DEVMODE ? 'TRUE ✓' : 'FALSE ✗' ?></p>
</div>

<div class="info-box">
    <h2>Expected Behavior:</h2>
    <p>If you're on the dev network (192.168.1.x), you should see:</p>
    <ul>
        <li>DEVMODE: TRUE ✓</li>
        <li>A dark debug toolbar at the bottom of this page</li>
        <li>The toolbar shows: Module, Execution Time, Memory Usage, Plugins count</li>
        <li>Click the toolbar to expand and see full details</li>
    </ul>
</div>

<div class="info-box">
    <h2>Session Data:</h2>
    <pre><?= print_r($_SESSION, true) ?></pre>
</div>

<p class="toolbar-scroll-hint">
    Scroll down to see the DevToolbar at the bottom of the page...
</p>
