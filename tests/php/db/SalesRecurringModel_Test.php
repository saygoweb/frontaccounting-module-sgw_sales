<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

require_once(__DIR__ . '/../TestConfig.php');
require_once(TEST_PATH . '/TestEnvironment.php');

use SGW_Sales\db\SalesRecurringModel;
use SGW\common\DataMapper;

class SaleRecurringModelTest extends PHPUnit_Framework_TestCase
{
	function setUp() {
		if ( ($msg=TestEnvironment::isGoodToGo()) != 'OK') {
			$this->markTestSkipped($msg);
		} else {
			require_once(__DIR__ . '/../../../hooks.php');
			TestEnvironment::includeFile('admin/db/maintenance_db.inc');
			$hook = new hooks_sgw_sales();
			$hook->activate_extension(0, false);
			$sql = 'DELETE FROM ' . TB_PREF . 'sales_recurring WHERE trans_no=998';
			db_query($sql);
			$sql = 'DELETE FROM ' . TB_PREF . 'sales_recurring WHERE trans_no=999';
			db_query($sql);
		}
	}
	
	function tearDown() {
	}
	
	function testSalesRecurringCRUD_OK()
	{
		$modelCreate = new SalesRecurringModel();
		// Read non-existant
		$actual = SalesRecurringModel::readByTransNo(999);
		$this->assertEquals(false, $actual);
		
		// Create
		$modelCreate->dtStart = '2016-07-03';
		$modelCreate->dtEnd = '9999-01-01';
		$modelCreate->dtLast = '';
		$modelCreate->repeats = SalesRecurringModel::REPEAT_YEARLY;
		$modelCreate->every = 1;
		$modelCreate->transNo = 999;
		$modelCreate->write();
		$this->assertNotFalse($modelCreate->id);
		
		// Read back and Update
		$modelUpdate = new SalesRecurringModel();
		$actual = SalesRecurringModel::readByTransNo(999);
		$this->assertEquals(true, $actual);
		$this->assertEquals($modelCreate->id, $modelUpdate->id);
		$this->assertEquals($modelCreate->dtStart, $modelUpdate->dtStart);
		
		$modelUpdate->dtStart = '2016-08-04';
		$modelUpdate->write();

		// Read back after Update
		$modelUpdated = SalesRecurringModel::readByTransNo(999);
		$this->assertEquals($modelUpdate->id, $modelUpdated->id);
		$this->assertEquals($modelUpdate->dtStart, $modelUpdated->dtStart);
		
		// Delete
		$model = new SalesRecurringModel();
		$mapper = $model->_mapper;
		$actual = $mapper->delete($modelUpdated->id);
		$this->assertEquals(true, $actual);
		$actual = $mapper->delete($modelUpdated->id);
		$this->assertEquals(false, $actual);
		
	}
	
}