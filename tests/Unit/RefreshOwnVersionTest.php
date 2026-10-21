<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * hooks_sgw_sales::refresh_own_version() with FrontAccounting's base hooks class
 * and extension functions stubbed. The hook is global and snake_case by contract,
 * so each test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RefreshOwnVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sgw_sales-hook-' . uniqid('', true);
        mkdir($this->root . '/modules/sgw_sales/_init', 0777, true);
        $GLOBALS['path_to_root'] = $this->root;
        $GLOBALS['fa_ext_written'] = array();
        $GLOBALS['fa_ext_list'] = array(
            array('package' => 'graphql', 'type' => 'extension', 'version' => '1.0'),
            array('package' => 'sgw_sales', 'type' => 'extension', 'version' => '2.4-1.5'),
            array('package' => 'other', 'type' => 'extension', 'version' => '7'),
        );
        if (!class_exists('hooks', false)) {
            eval('class hooks { public $module_name; }');
            eval('function get_company_extensions() { return $GLOBALS["fa_ext_list"]; }');
            eval('function update_extensions($exts) { $GLOBALS["fa_ext_written"][] = $exts; return true; }');
        }
        require_once dirname(__DIR__, 2) . '/hooks.php';
    }

    protected function tearDown(): void
    {
        $init = $this->root . '/modules/sgw_sales/_init';
        foreach (glob($init . '/*') ?: array() as $f) {
            unlink($f);
        }
        rmdir($init);
        rmdir($this->root . '/modules/sgw_sales');
        rmdir($this->root . '/modules');
        rmdir($this->root);
    }

    /**
     * hooks_sgw_sales is global and loaded in setUp(), so static analysis cannot see it.
     * @return mixed
     */
    private function hooks()
    {
        // The constructor connects to the company's database; the hook needs none of that.
        /** @var class-string $name */
        $name = 'hooks_sgw_sales';
        $class = new \ReflectionClass($name);
        $hooks = $class->newInstanceWithoutConstructor();
        $class->getProperty('module_name')->setValue($hooks, 'sgw_sales');
        return $hooks;
    }

    private function writeConfig(string $text): void
    {
        file_put_contents($this->root . '/modules/sgw_sales/_init/config', $text);
    }

    public function testSetsOnlyThisModulesVersion(): void
    {
        $this->writeConfig("Name: Recurring Sales\nVersion: 2.4-9.9.9\n");
        $before = $GLOBALS['fa_ext_list'];

        $this->hooks()->refresh_own_version();

        $this->assertCount(1, $GLOBALS['fa_ext_written']);
        $written = $GLOBALS['fa_ext_written'][0];
        $this->assertSame('2.4-9.9.9', $written[1]['version']);
        $this->assertSame(serialize($before[0]), serialize($written[0]));
        $this->assertSame(serialize($before[2]), serialize($written[2]));
    }

    public function testNoConfigWritesNothing(): void
    {
        $this->hooks()->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }

    public function testConfigWithoutVersionWritesNothing(): void
    {
        $this->writeConfig("Name: Recurring Sales\n");
        $this->hooks()->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }

    public function testMatchingVersionWritesNothing(): void
    {
        $this->writeConfig("Version: 2.4-1.5\n");
        $this->hooks()->refresh_own_version();
        $this->assertSame(array(), $GLOBALS['fa_ext_written']);
    }
}
