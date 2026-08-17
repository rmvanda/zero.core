<?php

namespace Zero\Tests\Core;

use Zero\Core\Application;
use Zero\Core\Attribute\AllowedMethods;
use Zero\Core\Attribute\AllowWithToken;
use Zero\Core\Attribute\AuditLog;
use Zero\Core\Attribute\RequireAuthLevel;
use Zero\Core\Attribute\RequiredParams;
use Zero\Core\Attribute\RequireLogin;
use Zero\Core\Attribute\RequirePermission;
use Zero\Core\Attribute\RestrictIP;
use Zero\Core\Attribute\Sanitize;
use Zero\Core\Attribute\Validate;

/**
 * Attribute order is an invariant, and this is what enforces it.
 *
 * #[AllowWithToken] ESTABLISHES the session that #[RequireLogin] and
 * #[RequirePermission] then check. Run them the other way round and token auth
 * is silently dead: the Bearer client is bounced to the login page before the
 * attribute that would have authenticated it ever runs. Nothing at runtime
 * notices — the endpoint is simply unreachable by token, and the failure looks
 * like a client problem.
 *
 * Application runs attributes in declaration order, class-level first
 * (`array_merge($effectiveClass, $methodAttributes)`), so the trap has a shape:
 * a class-level #[RequireLogin] with a method-level #[AllowWithToken] under it
 * reads correctly at both sites and is wrong when combined. No amount of
 * reading one file catches that.
 *
 * The order below is not invented here. docs/ATTRIBUTES.md has published it as
 * "Best Practice Order" all along, and modules/Index/view/zero-framework.php
 * publishes a second, slightly different one — an invariant stated twice, in
 * two places that disagree, and enforced nowhere. This test is the one place
 * that decides, and the docs point at it.
 *
 * Enforcing by test rather than by sorting the pipeline at runtime is
 * deliberate: source order stays truthful, so what you read in a module is
 * what runs, and the DevToolbar's attribute tab keeps meaning what it says.
 * The cost is that this is advisory — it catches a bad order in this repo, at
 * test time, not in a module that never runs the suite.
 */
class AttributeOrderTest extends ZeroTestCase
{
    /**
     * The pipeline, first to last. Every attribute in core/attribute/ must
     * appear — testEveryCoreAttributeIsRanked() fails if one is added without
     * being placed here, so the list cannot quietly fall behind.
     *
     * Why this order:
     *
     *   RestrictIP        A network-level deny. Cheapest, most absolute, and
     *                     the only gate that needs nothing from the session.
     *   AllowWithToken    Establishes a session from a Bearer token, so it has
     *                     to precede everything that reads one. This is the
     *                     pair the whole test exists for.
     *   RequireLogin      Who are you.
     *   RequireAuthLevel  Shelved (see docs/attributes/RequireAuthLevel.md) but
     *                     still live in modules/User, so it still needs a slot.
     *   RequirePermission What may you do. After RequireLogin, so an anonymous
     *                     caller gets 401 rather than 403.
     *   AuditLog          Reads User::currentId(), so it must follow
     *                     AllowWithToken or every Bearer-authenticated admin
     *                     action is recorded with no user against it.
     *   AllowedMethods    Request shape, after auth: an anonymous caller gets
     *                     401 rather than a 405 that confirms the endpoint
     *                     exists and what it accepts.
     *   RequiredParams    Nothing to validate until the caller is allowed in.
     *   Sanitize          After the params are known to be present.
     *   Validate          Last, so it judges the value that will actually be
     *                     used rather than the one before sanitizing.
     */
    private const ORDER = [
        RestrictIP::class,
        AllowWithToken::class,
        RequireLogin::class,
        RequireAuthLevel::class,
        RequirePermission::class,
        AuditLog::class,
        AllowedMethods::class,
        RequiredParams::class,
        Sanitize::class,
        Validate::class,
    ];

    /** Endpoint name used to exercise the __call fallback: never a real method. */
    private const VIRTUAL_ENDPOINT = 'zeroTestVirtualEndpointProbe';

    /** @var array<string,string>|null class => file, memoized across tests */
    private static ?array $moduleClasses = null;

    /* ── The check itself ────────────────────────────────────────────────── */

    /** Position in the pipeline, or null for an attribute nobody has placed. */
    private static function rank(string $attributeClass): ?int
    {
        $rank = array_search($attributeClass, self::ORDER, true);

        return $rank === false ? null : $rank;
    }

    private static function shortName(string $attributeClass): string
    {
        $parts = explode('\\', $attributeClass);

        return end($parts);
    }

    /**
     * Every out-of-order pair in what $class::$endpoint will actually run.
     *
     * Asks Application::resolveAttributes() rather than re-deriving the
     * sequence, so the replace/additive filtering and the __call fallback are
     * the framework's own and cannot drift away from what this checks.
     *
     * @return string[] One human-readable line per violation.
     */
    private function violations(string $class, string $endpoint): array
    {
        $resolved = Application::resolveAttributes($class, $endpoint);

        $names = array_map(fn($a) => $a->getName(), $resolved['effective']);

        $found    = [];
        $highest  = -1;
        $highestName = null;

        foreach ($names as $name) {
            $rank = self::rank($name);

            if ($rank === null) {
                $found[] = sprintf(
                    '#[%s] is not in AttributeOrderTest::ORDER, so nothing knows where it belongs',
                    self::shortName($name)
                );
                continue;
            }

            if ($rank < $highest) {
                $found[] = sprintf(
                    '#[%s] runs after #[%s], but belongs before it',
                    self::shortName($name),
                    self::shortName($highestName)
                );
            }

            if ($rank > $highest) {
                $highest     = $rank;
                $highestName = $name;
            }
        }

        return $found;
    }

    /* ── Discovering what to check ───────────────────────────────────────── */

    /**
     * Every module class, loaded.
     *
     * Reflection only — nothing is constructed, so a module's constructor
     * (which may want Redis, a database, or a live request) never runs. Reading
     * the class name out of the source before requiring means a file that does
     * not declare what its path implies fails loudly here rather than silently
     * dropping out of coverage.
     *
     * @return array<string,string> fully-qualified class name => file path
     */
    private static function moduleClasses(): array
    {
        if (self::$moduleClasses !== null) {
            return self::$moduleClasses;
        }

        $root = dirname(__DIR__, 2) . '/modules';

        $files = array_merge(
            glob("$root/*/[A-Z]*.php") ?: [],
            glob("$root/*/submodule/*/[A-Z]*.php") ?: [],
            glob("$root/*/Admin/[A-Z]*.php") ?: []
        );
        sort($files);

        $classes   = [];
        $unmapped  = [];

        foreach ($files as $file) {
            $src = file_get_contents($file);

            preg_match('/^\s*namespace\s+([^;{]+)\s*;/m', $src, $ns);
            preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cl);

            if (!$ns || !$cl) {
                $unmapped[] = $file;
                continue;
            }

            require_once $file;

            $fqcn = trim($ns[1]) . '\\' . $cl[1];
            if (!class_exists($fqcn, false)) {
                $unmapped[] = "$file (expected $fqcn)";
                continue;
            }

            $classes[$fqcn] = $file;
        }

        if ($unmapped) {
            self::fail(
                "Could not resolve a class for these module files, so they went unchecked:\n  "
                . implode("\n  ", $unmapped)
            );
        }

        return self::$moduleClasses = $classes;
    }

    /** Endpoint names worth checking on a class: its own public methods, plus __call. */
    private static function endpointsOf(\ReflectionClass $reflection): array
    {
        $endpoints = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Inherited framework methods carry no attributes of their own and
            // would only re-test the class-level ones, once per module.
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            $endpoints[] = $method->getName();
        }

        // A virtual endpoint resolves through __call(), which contributes its
        // own attributes on the endpoint's behalf. That path has to be checked
        // too, and no real method name reaches it.
        if ($reflection->hasMethod('__call')) {
            $endpoints[] = self::VIRTUAL_ENDPOINT;
        }

        // A class with no endpoints at all still has class-level attributes
        // worth ordering.
        return $endpoints ?: [self::VIRTUAL_ENDPOINT];
    }

    /* ── Tests ───────────────────────────────────────────────────────────── */

    /**
     * ORDER has to name every attribute that exists, or a new one is unplaced
     * and this whole test quietly stops covering it. This is the half that
     * keeps the list honest.
     */
    public function testEveryCoreAttributeIsRanked(): void
    {
        $files = glob(dirname(__DIR__) . '/attribute/*.php') ?: [];
        $this->assertNotEmpty($files, 'Found no attributes in core/attribute/ — the glob is wrong');

        $unranked = [];
        foreach ($files as $file) {
            $class = 'Zero\\Core\\Attribute\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;   // not an attribute class; nothing to place
            }

            if (self::rank($class) === null) {
                $unranked[] = self::shortName($class);
            }
        }

        $this->assertSame([], $unranked, sprintf(
            "These attributes have no place in the pipeline. Add them to %s::ORDER, "
            . "where the decision is recorded, rather than leaving it to declaration order:\n  %s",
            self::class,
            implode("\n  ", $unranked)
        ));
    }

    /** ...and nothing is ranked that no longer exists. */
    public function testEveryRankedAttributeStillExists(): void
    {
        foreach (self::ORDER as $class) {
            $this->assertTrue(
                class_exists($class),
                "ORDER names {$class}, which no longer exists — remove it"
            );
        }
    }

    /**
     * The real check: every endpoint of every module, in the order the
     * framework will actually run it.
     *
     * One test rather than one per module because the useful output is the
     * whole list of what is wrong, not the first module that happens to sort
     * first.
     */
    public function testEveryModuleEndpointDeclaresAttributesInPipelineOrder(): void
    {
        $classes = self::moduleClasses();
        $this->assertNotEmpty($classes, 'Found no module classes to check');

        $report = [];
        $gated  = 0;

        foreach ($classes as $class => $file) {
            $reflection = new \ReflectionClass($class);

            foreach (self::endpointsOf($reflection) as $endpoint) {
                if (Application::resolveAttributes($class, $endpoint)['effective']) {
                    $gated++;
                }

                foreach ($this->violations($class, $endpoint) as $violation) {
                    $where = $endpoint === self::VIRTUAL_ENDPOINT
                        ? "{$class} (via __call)"
                        : "{$class}::{$endpoint}()";

                    $report[] = "  {$where}\n      {$violation}\n      {$file}";
                }
            }
        }

        // assertTrue rather than assertSame([], ...): the message below already
        // lists every violation with its file, and assertSame would print the
        // same list again as an array diff, one escaped line per finding.
        $this->assertTrue($report === [], sprintf(
            "Attributes are declared in an order the framework will run wrongly.\n"
            . "Class-level attributes always run before method-level ones, so a violation\n"
            . "can span the two. The pipeline order is in %s::ORDER.\n\n%s\n",
            self::class,
            implode("\n", $report)
        ));

        // A pass means nothing if the scan examined nothing. Break the globs,
        // the path-to-class mapping or endpointsOf() and this test would go
        // green having checked zero endpoints, which is the failure mode a
        // linting test is most prone to and least likely to be noticed.
        $this->assertGreaterThan(
            100,
            $gated,
            "Only {$gated} endpoints resolved to any attribute at all — the scan is not reaching the modules"
        );
    }

    /* ── Self-tests: a scanner that cannot fail is not a test ────────────── */

    /**
     * The trap this exists for, and the one no single file reveals: both
     * declarations read correctly on their own.
     */
    public function testCatchesAClassLevelGateAboveAMethodLevelAllowWithToken(): void
    {
        $violations = $this->violations(OrderProbeClassGated::class, 'api');

        $this->assertCount(1, $violations, 'The class/method split went undetected');
        $this->assertStringContainsString('AllowWithToken', $violations[0]);
        $this->assertStringContainsString('RequireLogin', $violations[0]);
    }

    /** The same mistake made in one place. */
    public function testCatchesAMisorderWithinASingleDeclarationSite(): void
    {
        $this->assertCount(1, $this->violations(OrderProbeMisordered::class, 'api'));
    }

    /** An unplaced attribute is reported rather than skipped over. */
    public function testCatchesAnAttributeThatIsNotInTheOrder(): void
    {
        $violations = $this->violations(OrderProbeUnranked::class, 'api');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('not in', $violations[0]);
    }

    /** The shape every live module uses — must stay silent. */
    public function testAcceptsTheCorrectOrder(): void
    {
        $this->assertSame([], $this->violations(OrderProbeCorrect::class, 'api'));
    }

    /**
     * A method-level attribute that REPLACES its class-level twin leaves one
     * in the pipeline, not two — so the check must read what survives
     * resolution, not what was declared.
     */
    public function testAcceptsAMethodLevelOverrideOfAClassLevelGate(): void
    {
        $this->assertSame([], $this->violations(OrderProbeOverride::class, 'api'));
    }
}

/* ── Probe fixtures ──────────────────────────────────────────────────────
 *
 * Not in Zero\Module, so the module scan above never picks them up.
 */

/** Class gated, token auth added per-method: correct at both sites, broken together. */
#[RequireLogin]
class OrderProbeClassGated
{
    #[AllowWithToken]
    public function api() {}
}

/** The same inversion, declared in one place. */
class OrderProbeMisordered
{
    #[RequireLogin]
    #[AllowWithToken]
    public function api() {}
}

/** Stands in for an attribute added to core/attribute/ and never placed in ORDER. */
#[\Attribute(\Attribute::TARGET_ALL)]
class OrderProbeStrayAttribute {}

/** Carries an attribute the pipeline has never heard of. */
class OrderProbeUnranked
{
    #[OrderProbeStrayAttribute]
    public function api() {}
}

/** What Lists, Kanban, Notebook, Comfy, Doorbell and Sqrlcam all do. */
#[AllowWithToken]
#[RequireLogin]
class OrderProbeCorrect
{
    #[AllowedMethods('POST')]
    #[RequiredParams(['title'])]
    public function api() {}
}

/** Method-level RequireLogin replaces the class-level one; only one runs. */
#[RequireLogin]
class OrderProbeOverride
{
    #[AllowWithToken]
    #[RequireLogin('/somewhere/else')]
    public function api() {}
}
