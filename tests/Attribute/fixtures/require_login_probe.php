<?php

/**
 * Out-of-process probe for RequireLoginTest.
 *
 * RequireLogin::handler() denies by calling header()+exit() (via the
 * private redirectToLogin()), which would kill the PHPUnit process running
 * the test itself rather than fail it — the same reason RequirePermission's
 * identical-shaped redirect is left untested in RequirePermissionTest. This
 * script runs handler() in its own OS process (spawned by RequireLoginTest
 * via proc_open) so the exit() only ends that child, and prints the
 * observable effect on a single stdout line for the parent test to parse.
 *
 * Usage: php require_login_probe.php '<json $_SESSION, or "null">'
 *   - argv[1] "null"  → no session at all: session_start() is never called
 *     and $_SESSION is left completely undefined, matching a request that
 *     never touched a session.
 *   - argv[1] '{...}' → session_start() is called and $_SESSION is replaced
 *     with the decoded array before RequireLogin::handler() runs.
 *
 * Output (exactly one line):
 *   APPROVED               handler() returned true, no redirect happened
 *   HEADER:<Location value> redirectToLogin() ran; this was the header it sent
 *   WARN:<message>          a PHP warning/notice fired before either of the above
 */

// Shadow header(): an unqualified call inside namespace Zero\Core\Attribute
// resolves to a same-namespace function before falling back to the global
// one, so this intercepts RequireLogin's header('Location: ...') call
// without changing production code at all.
namespace Zero\Core\Attribute {
    function header(string $value): void {
        echo "HEADER:{$value}\n";
    }
}

namespace {
    require __DIR__ . '/../../../../vendor/autoload.php';

    set_error_handler(function (int $errno, string $errstr): bool {
        echo "WARN:{$errstr}\n";
        return true;
    });

    // Same override ZeroTestCase uses: the real log path is not writable by
    // the test user, and a failed fopen would otherwise surface as an
    // unrelated WARN line on stdout.
    \Zero\Core\Console::$defaultLogFile = sys_get_temp_dir() . '/require_login_probe.log';

    $arg = $argv[1] ?? 'null';
    $session = json_decode($arg, true);

    if ($session !== null) {
        session_start();
        $_SESSION = $session;
    }
    // else: no session at all — $_SESSION is left undefined, exactly like a
    // request that never called session_start().

    $attr = new \Zero\Core\Attribute\RequireLogin();
    $approved = $attr->handler();

    // Only reached when handler() did NOT redirect/exit.
    echo $approved ? "APPROVED\n" : "REJECTED_WITHOUT_EXIT\n";
}
