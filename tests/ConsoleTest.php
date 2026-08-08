<?php

namespace Zero\Tests\Core;

use Zero\Core\Console;

class ConsoleTest extends ZeroTestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLogFile = sys_get_temp_dir() . '/zero_test_' . uniqid() . '.log';

        // Reset the cached threshold via reflection
        $ref = new \ReflectionClass(Console::class);
        $prop = $ref->getProperty('threshold');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }

        // Reset threshold cache
        $ref = new \ReflectionClass(Console::class);
        $prop = $ref->getProperty('threshold');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /* ── Message storage (DevToolbar) ── */

    public function testLogStoresMessage(): void
    {
        Console::log('test message', 'DEBUG', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('test message', $messages[0]['message']);
        $this->assertSame('DEBUG', $messages[0]['level']);
    }

    public function testClearMessages(): void
    {
        Console::log('message 1', 'INFO', $this->testLogFile);
        Console::log('message 2', 'WARN', $this->testLogFile);
        $this->assertCount(2, Console::getMessages());

        Console::clearMessages();
        $this->assertCount(0, Console::getMessages());
    }

    public function testMessageIncludesTimestamp(): void
    {
        Console::log('test', 'DEBUG', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertArrayHasKey('timestamp', $messages[0]);
        $this->assertIsInt($messages[0]['timestamp']);
    }

    public function testMessageIncludesCallerInfo(): void
    {
        Console::log('test', 'DEBUG', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertArrayHasKey('caller', $messages[0]);
        // Caller should reference this test file, not Console.php
        $this->assertStringContainsString('ConsoleTest.php', $messages[0]['caller']);
    }

    /* ── Magic static dispatch ── */

    public function testDebugDispatch(): void
    {
        Console::debug('debug message', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('DEBUG', $messages[0]['level']);
    }

    public function testWarnDispatch(): void
    {
        Console::warn('warning message', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertSame('WARN', $messages[0]['level']);
    }

    public function testErrorDispatch(): void
    {
        Console::error('error message', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertSame('ERROR', $messages[0]['level']);
    }

    public function testInfoDispatch(): void
    {
        Console::info('info message', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertSame('INFO', $messages[0]['level']);
    }

    /* ── File logging ── */

    public function testWritesToLogFile(): void
    {
        Console::log('file test', 'INFO', $this->testLogFile);

        $this->assertFileExists($this->testLogFile);
        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('file test', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testLogAppendsNotOverwrites(): void
    {
        Console::log('first', 'INFO', $this->testLogFile);
        Console::log('second', 'WARN', $this->testLogFile);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('first', $content);
        $this->assertStringContainsString('second', $content);
    }

    public function testLogIncludesIpAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        Console::log('ip test', 'DEBUG', $this->testLogFile);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('192.168.1.100', $content);
    }

    public function testLogIncludesCallerFile(): void
    {
        Console::log('caller test', 'DEBUG', $this->testLogFile);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('ConsoleTest.php', $content);
    }

    /* ── Level handling ── */

    public function testNullLevelDefaultsToDebug(): void
    {
        Console::log('default level', null, $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertSame('DEBUG', $messages[0]['level']);
    }

    public function testNumericLevel(): void
    {
        Console::log('numeric', 4, $this->testLogFile); // ERROR = index 4

        $messages = Console::getMessages();
        $this->assertSame('ERROR', $messages[0]['level']);
    }

    public function testUnknownLevelFallsBackToDebug(): void
    {
        Console::log('unknown', 'NONSENSE', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertSame('UNKNOWN(NONSENSE)', $messages[0]['level']);
    }

    /* ── Message serialization ── */

    public function testArrayMessageSerialized(): void
    {
        Console::log(['key' => 'value'], 'DEBUG', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertStringContainsString('key', $messages[0]['message']);
        $this->assertStringContainsString('value', $messages[0]['message']);
    }

    public function testObjectMessageSerialized(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        Console::log($obj, 'DEBUG', $this->testLogFile);

        $messages = Console::getMessages();
        $this->assertStringContainsString('test', $messages[0]['message']);
    }

    /* ── Messages always stored regardless of threshold ── */

    public function testMessagesStoredEvenBelowThreshold(): void
    {
        // Set threshold to ERROR (index 4) — DEBUG messages shouldn't log to file
        // but should still be stored for DevToolbar
        if (!defined('ZERO_LOG_LEVEL_INT')) {
            define('ZERO_LOG_LEVEL_INT', 4);
        }

        // Reset threshold cache so constant is picked up
        $ref = new \ReflectionClass(Console::class);
        $prop = $ref->getProperty('threshold');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Console::log('below threshold', 'DEBUG', $this->testLogFile);

        // Message should be stored for DevToolbar
        $messages = Console::getMessages();
        $this->assertCount(1, $messages);

        // But not written to file
        $this->assertFileDoesNotExist($this->testLogFile);
    }
}
