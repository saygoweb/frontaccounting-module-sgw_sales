<?php

namespace SGW_Sales\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Release 4 spec §3.4. activate_extension() lists both scripts in order, 1.4 checked
 * by dt_next's nullability; and update_1.4.sql holds only statements
 * FrontAccounting's db_import() can run — no helper queries after it.
 */
class ActivateExtensionTest extends TestCase
{
    public function testActivationListsBothScriptsIn14Order(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 2) . '/hooks.php');
        $this->assertMatchesRegularExpression(
            "/'update_1\\.0\\.sql' => array\\('sales_recurring'\\),\\s*'update_1\\.4\\.sql' => "
            . "array\\('sales_recurring', 'dt_next', array\\('Null' => 'YES'\\)\\)/",
            $hooks
        );
    }

    public function testUpdate14HasNoHelperQueries(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/update_1.4.sql');
        $this->assertStringNotContainsString('Upgrade helpers', $sql);
        foreach (preg_split('/\R/', trim($sql)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $this->assertStringStartsNotWith('SELECT', strtoupper($line), $line);
            $this->assertStringEndsWith(';', $line, 'every statement ends in a semicolon: ' . $line);
        }
    }
}
