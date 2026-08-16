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
 * Usage: php require_login_probe.php '<json $_SESSION, or "null">' [redirect] [json]
 *   - argv[1] "null"  → no session at all: session_start() is never called
 *     and $_SESSION is left completely undefined, matching a request that
 *     never touched a session.
 *   - argv[1] '{...}' → session_start() is called and $_SESSION is replaced
 *     with the decoded array before RequireLogin::handler() runs.
 *   - argv[2] absent or "null" → #[RequireLogin] with no redirect override.
 *     Anything else is passed to the constructor as $redirect, so the test
 *     can pin which Location a custom (or malformed) target produces.
 *   - argv[3] "json" → Request::$acceptsJSON = true, the shape that makes
 *     handler() throw HTTPError(401) instead of redirecting.
 *
 * Output (exactly one line):
 *   APPROVED                 handler() returned true, no redirect happened
 *   HEADER:<Location value>  redirectToLogin() ran; this was the header it sent
 *   HTTPERROR:<code>:<detail> handler() threw instead of redirecting (JSON path)
 *   WARN:<message>           a PHP warning/notice fired before any of the above
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

    $redirect = ($argv[2] ?? 'null') === 'null' ? null : $argv[2];

    // Request is normally populated by its constructor from $_SERVER. Nothing
    // constructs one here, so the flag is set directly — handler() reads only
    // this one static off it.
    \Zero\Core\Request::$acceptsJSON = ($argv[3] ?? '') === 'json';

    $attr = new \Zero\Core\Attribute\RequireLogin($redirect);

    try {
        $approved = $attr->handler();
    } catch (\Zero\Core\HTTPError $e) {
        // The JSON denial path: a real 401 instead of a 302, so an XHR sees a
        // status it can act on rather than the login page's HTML.
        echo "HTTPERROR:{$e->getCode()}:{$e->detail}\n";
        exit(0);
    }

    // Only reached when handler() did NOT redirect/exit/throw.
    echo $approved ? "APPROVED\n" : "REJECTED_WITHOUT_EXIT\n";
}
