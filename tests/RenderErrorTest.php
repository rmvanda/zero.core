<?php

namespace Zero\Tests\Core;

/**
 * Response::renderError() as a JSON client sees it.
 *
 * renderError() ends in exit(), so each case runs in a child PHP process and
 * the test reads what it printed. The child loads this checkout's Response
 * and HTTPError, and throws from an endpoint that had already set $this->data.
 */
class RenderErrorTest extends ZeroTestCase
{
    private const CHILD = <<<'PHP'
<?php
[, $core, $detail] = $argv + [2 => null];
require $core . '/HTTPError.php';
require $core . '/Response.php';
require dirname($core) . '/vendor/autoload.php';
define('ZERO_ROOT', dirname($core) . '/');
define('MODULE_PATH', ZERO_ROOT . 'modules/');
\Zero\Core\Console::$defaultLogFile = '/dev/null';
\Zero\Core\Request::$Module = 'NoSuchModule';
\Zero\Core\Request::$acceptsJSON = true;

$response = new class extends \Zero\Core\Response {
    public function endpoint(?string $detail): void {
        $this->data = ['half_built' => 'endpoint data'];
        throw new \Zero\Core\HTTPError(404, $detail);
    }
};
try {
    $response->endpoint($detail);
} catch (\Zero\Core\HTTPError $e) {
    $response->renderError($e);
}
PHP;

    /** @return array|null The JSON body renderError() printed */
    private function renderJson(?string $detail): ?array
    {
        $script = tempnam(sys_get_temp_dir(), 'render-error-');
        file_put_contents($script, self::CHILD);

        $args = [PHP_BINARY, $script, dirname(__DIR__)];
        if ($detail !== null) {
            $args[] = $detail;
        }
        $output = shell_exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null');
        unlink($script);

        return json_decode((string)$output, true);
    }

    public function testAJsonClientGetsTheDetail(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'Not Found', 'detail' => 'Item not found', 'code' => 404],
            $this->renderJson('Item not found')
        );
    }

    public function testAnErrorWithoutADetailHasNoDetailField(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'Not Found', 'code' => 404],
            $this->renderJson(null)
        );
    }

    public function testTheEndpointsDataIsNotSentWithTheError(): void
    {
        $body = $this->renderJson('Item not found');

        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('data', $body);
    }
}
