<?php
namespace Zero\Core\Module\Module\Tests;

use Zero\Tests\Core\ZeroTestCase;

/**
 * The test mkmod copies into a new module. Keep it trivially true, and keep it
 * free of the literal name "Module" — it derives its module from its own path.
 *
 * MKMOD:SKELETON-ONLY-START
 * The skeleton's other tests (ModuleTest, MkmodTest) assert skeleton-specific
 * facts and are deliberately NOT copied.
 * MKMOD:SKELETON-ONLY-END
 */
class ModuleSmokeTest extends ZeroTestCase
{
    public function testControllerFileExists(): void
    {
        $moduleDir  = dirname(__DIR__);
        $moduleName = basename($moduleDir);
        $this->assertFileExists("$moduleDir/$moduleName.php");
    }
}
