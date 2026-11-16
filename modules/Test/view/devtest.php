<!DOCTYPE html>
<html>
<head>
    <title>DevToolbar Test</title>
</head>
<body>
    <h1>DevToolbar Test Page</h1>

    <h2>Debug Info:</h2>
    <pre>
REMOTE_ADDR: <?= $_SERVER['REMOTE_ADDR'] ?? 'undefined' ?>

DEV_SUBNET: <?= DEV_SUBNET ?? 'undefined' ?>

DEVMODE: <?= defined('DEVMODE') && DEVMODE ? 'TRUE' : 'FALSE' ?>

str_contains check: <?= str_contains($_SERVER['REMOTE_ADDR'], DEV_SUBNET) ? 'MATCH' : 'NO MATCH' ?>
    </pre>

    <h2>Expected:</h2>
    <p>If you're on 192.168.1.x network, you should see:</p>
    <ul>
        <li>DEVMODE: TRUE</li>
        <li>A dark toolbar at the bottom of this page</li>
    </ul>
</body>
</html>
