<?php ?>

<h3>CloudFlare IP Block Test</h3>

<form id="cf-block-form">
    <label>IP Address</label>
    <input type="text" name="ip" placeholder="e.g. 1.2.3.4" required>
    <label>Reason (optional)</label>
    <input type="text" name="reason" placeholder="Blocked by Zero Tolerance">
    <br><br>
    <button type="submit">Block IP</button>
</form>

<h4>Result</h4>
<pre id="cf-result">Awaiting submission...</pre>

<script>
document.getElementById('cf-block-form').addEventListener('submit', function(e) {
    e.preventDefault();

    var resultEl = document.getElementById('cf-result');
    resultEl.textContent = 'Sending...';

    var formData = new FormData(this);

    fetch('/test/cloudflare', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        resultEl.textContent = JSON.stringify(data, null, 2);
    })
    .catch(function(err) {
        resultEl.textContent = 'Error: ' + err.message;
    });
});
</script>
