<?php ?>

<h3>Request::convertJSONtoPOST() replication</h3>

<p>
    This POSTs a JSON body to <code>/test/test-json</code> using the
    framework-standard headers (<code>Content-Type: application/json</code>,
    <code>Accept: application/json</code>,
    <code>X-Requested-With: XMLHttpRequest</code>). Because
    <code>convertJSONtoPOST()</code> is currently disabled in
    <code>core/Request.php</code>, PHP will NOT auto-populate <code>$_POST</code>
    for a JSON body &mdash; so the endpoint reports whether <code>$_POST</code>
    is empty and, if so, whether the raw JSON is still available in
    <code>php://input</code>.
</p>

<h4>Test body being sent</h4>
<pre id="tj-body"></pre>

<button type="button" id="tj-send">POST JSON to /test/test-json</button>

<h4>Raw server response</h4>
<pre id="tj-result">Awaiting submission...</pre>

<script>
// Simple test body of data — the "JSON body" the framework would normally
// need convertJSONtoPOST() to surface into $_POST.
const testBody = {
    message: "hello from fetch",
    number: 42,
    nested: { a: 1, b: [2, 3, 4] }
};

document.getElementById('tj-body').textContent = JSON.stringify(testBody, null, 2);

document.getElementById('tj-send').addEventListener('click', function () {
    const resultEl = document.getElementById('tj-result');
    resultEl.textContent = 'Sending...';

    fetch('/test/test-json', {
        method: 'POST',
        headers: {
            // Framework-standard headers for a JSON request
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(testBody),
        credentials: 'same-origin'
    })
    // Read as raw text so nothing the server emits is hidden from us.
    .then(function (res) { return res.text(); })
    .then(function (text) { resultEl.textContent = text; })
    .catch(function (err) { resultEl.textContent = 'Error: ' + err.message; });
});
</script>
