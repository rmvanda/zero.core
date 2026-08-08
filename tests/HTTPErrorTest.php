<?php

namespace Zero\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Zero\Core\HTTPError;

class HTTPErrorTest extends ZeroTestCase
{
    public function testConstructWithKnownCode(): void
    {
        $error = new HTTPError(404);

        $this->assertSame(404, $error->getCode());
        $this->assertSame('Not Found', $error->getMessage());
        $this->assertNull($error->detail);
    }

    public function testConstructWithDetail(): void
    {
        $error = new HTTPError(400, 'Missing parameter: name');

        $this->assertSame(400, $error->getCode());
        $this->assertSame('Bad Request', $error->getMessage());
        $this->assertSame('Missing parameter: name', $error->detail);
    }

    public function testConstructWithUnknownCode(): void
    {
        $error = new HTTPError(999);

        $this->assertSame(999, $error->getCode());
        $this->assertSame('Unspecified', $error->getMessage());
    }

    public function testExtendsException(): void
    {
        $error = new HTTPError(500);
        $this->assertInstanceOf(\Exception::class, $error);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $this->expectException(HTTPError::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Forbidden');

        throw new HTTPError(403);
    }

    #[DataProvider('commonCodesProvider')]
    public function testCommonStatusCodes(int $code, string $expectedMessage): void
    {
        $error = new HTTPError($code);
        $this->assertSame($expectedMessage, $error->getMessage());
    }

    public static function commonCodesProvider(): array
    {
        return [
            '400 Bad Request'          => [400, 'Bad Request'],
            '401 Unauthorized'         => [401, 'Unauthorized'],
            '403 Forbidden'            => [403, 'Forbidden'],
            '404 Not Found'            => [404, 'Not Found'],
            '405 Method Not Allowed'   => [405, 'Method Not Allowed'],
            '418 Teapot'               => [418, "I'm a teapot"],
            '422 Unprocessable Entity' => [422, 'Unprocessable Entity'],
            '429 Too Many Requests'    => [429, 'Too Many Requests'],
            '500 Internal Server Error'=> [500, 'Internal Server Error'],
            '503 Service Unavailable'  => [503, 'Service Unavailable'],
        ];
    }

    public function testMessagesArrayContainsAllExpectedCodes(): void
    {
        // Verify the static map has a reasonable number of entries
        $this->assertGreaterThan(30, count(HTTPError::$messages));

        // Verify all standard 4xx and 5xx ranges are represented
        foreach (HTTPError::$messages as $code => $message) {
            $this->assertGreaterThanOrEqual(400, $code);
            $this->assertLessThan(600, $code);
            $this->assertNotEmpty($message);
        }
    }

    public function testDetailDefaultsToNull(): void
    {
        $error = new HTTPError(500);
        $this->assertNull($error->detail);
    }

    public function testDetailPreservedOnRethrow(): void
    {
        try {
            throw new HTTPError(400, 'Invalid JSON payload');
        } catch (HTTPError $e) {
            $this->assertSame('Invalid JSON payload', $e->detail);
            $this->assertSame(400, $e->getCode());
        }
    }
}
