<?php

namespace Zero\Tests\Core;

use Zero\Core\User;

/**
 * Static guard against the "argument silently discarded" bug class.
 *
 * Zero\Core\User exposes several public static accessors — getName(),
 * getEmail(), getId(), etc. — that take ZERO parameters and read $_SESSION.
 * PHP's user-defined functions discard extra call-site arguments silently,
 * with no warning and no error. Three call sites in TheButton passed a user
 * id to User::getName()/getEmail() anyway, and each one silently answered
 * with the CURRENT SESSION USER instead of the one it named — e.g. an
 * invitation email that greeted its recipient by the sender's name.
 *
 * This test is a SOURCE-LEVEL / STATIC check, not a behavioural one. It does
 * not run any code and cannot observe a fix take effect at runtime; it can
 * only prove that no `User::<zero-param-method>(...)` call site in the
 * scanned trees passes an argument. A behavioural regression test would need
 * to drive a real call site through its public entry point, which for
 * TheButton's private sendInviteEmail()/linkEmailInvite() would mean
 * instantiating a Module subclass under full framework/session state — the
 * brief for this guard deliberately avoided that fragility.
 *
 * The method list is derived by reflection, not hardcoded, so this guard
 * keeps covering whatever zero-parameter public statics exist on User at any
 * given time — including after sub-project B removes getName()/getEmail()
 * entirely, and including accessors this bug report never named (getId(),
 * getAll(), logout(), getAllPermissions() are zero-parameter too).
 *
 * Out of scope: aliased imports. The regex matches literal `User::` — a call
 * site importing the class under another name (e.g.
 * `use \Zero\Core\User as SessionUser;` then `SessionUser::getId($x)`) is
 * invisible to it. This is not exhaustive over every possible spelling of a
 * call site, only over the literal `User::` one.
 */
class SessionAccessorArityTest extends ZeroTestCase
{
    /**
     * Directories scanned for offending call sites, relative to the repo
     * root — the same set the Step 6 grep this guard replaces covered:
     * modules, core, app, aux, lib, entity, plugin, swoole. lib/ is
     * first-party wrappers (Api, CloudFlare, ComfyUI, Http, Mail, Ollama,
     * Push), not vendored code — real vendor/node_modules/ trees are never
     * scanned regardless (see the skip list in findOffendingCallSites()).
     *
     * Not every directory here exists in every checkout — swoole/ in
     * particular is tracked by no repository — so findOffendingCallSites()
     * skips a missing directory silently rather than failing the test.
     */
    private const SCAN_DIRS = ['modules', 'core', 'app', 'aux', 'lib', 'entity', 'plugin', 'swoole'];

    public function testNoCallSitePassesAnArgumentToAZeroParameterSessionAccessor(): void
    {
        $methods = $this->zeroParameterPublicStaticMethods();

        // A guard that silently covers nothing is worse than no guard —
        // if reflection ever finds zero such methods, something about
        // User's shape changed enough that this test needs re-thinking,
        // not a quiet pass.
        $this->assertNotEmpty(
            $methods,
            'Reflection found no zero-parameter public static methods on '
            . 'Zero\Core\User. Either the class changed shape or reflection '
            . 'is broken — this guard has nothing to check either way.'
        );

        $offenses = $this->findOffendingCallSites($methods);

        $this->assertSame(
            [],
            $offenses,
            "Found call site(s) passing an argument to a zero-parameter \n"
            . "Zero\\Core\\User accessor. PHP discards the argument silently \n"
            . "and the call answers from \$_SESSION instead of the user \n"
            . "named at the call site:\n  "
            . implode("\n  ", $offenses)
        );
    }

    /**
     * Every public static method on Zero\Core\User that takes no parameters
     * — the shape that makes a call-site argument silently discardable.
     */
    private function zeroParameterPublicStaticMethods(): array
    {
        $reflection = new \ReflectionClass(User::class);
        $names = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getNumberOfParameters() === 0) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * Scan every directory in SCAN_DIRS for `User::<name>(` calls where the
     * parenthesised text is anything other than optional whitespace.
     *
     * @param string[] $methodNames
     * @return string[] "file:line: matched text" for every offending call
     */
    private function findOffendingCallSites(array $methodNames): array
    {
        $root = dirname(__DIR__, 2);
        $alternation = implode('|', array_map(
            fn(string $m) => preg_quote($m, '/'),
            $methodNames
        ));
        $pattern = '/\bUser::(?:' . $alternation . ')\(([^)]*)\)/';

        $offenses = [];

        foreach (self::SCAN_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                // Skip vendored/generated trees and any tests/ directory —
                // a fixture asserting the bug (like this test's own docblock
                // example) would otherwise trip its own guard, and
                // modules/Notebook/tests/ carries a node_modules tree that
                // would otherwise be scanned for nothing.
                if (preg_match('#(^|/)(vendor|node_modules|\.git|tests)(/|$)#', $path)) {
                    continue;
                }

                $contents = file_get_contents($path);
                if ($contents === false) {
                    continue;
                }

                // Blank out comments before matching, preserving newlines so
                // line numbers still line up. Without this, this very test's
                // own explanatory comments in TheButton.php — which quote
                // the offending call shape as prose — would trip the guard
                // they're describing.
                $codeOnly = $this->stripComments($contents);

                if (!preg_match_all($pattern, $codeOnly, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[1] as $i => [$args, $offset]) {
                    if (trim($args) === '') {
                        continue; // zero-arg call — the correct usage
                    }

                    $line = substr_count($contents, "\n", 0, $offset) + 1;
                    $offenses[] = sprintf('%s:%d: %s', $path, $line, trim($matches[0][$i][0]));
                }
            }
        }

        return $offenses;
    }

    /**
     * Replace comment token text with blank space, keeping every newline so
     * the character offsets used for line-number reporting stay valid.
     */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $text);
            } else {
                $out .= $text;
            }
        }

        return $out;
    }
}
