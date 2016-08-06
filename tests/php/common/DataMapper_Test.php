<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use SGW\common\DataMapper;

class TestClassModel {
	public $testProperty;
}

class DataMapperTest extends PHPUnit_Framework_TestCase
{
	function testAutoTable_TwoWords_OK()
	{
		$name = DataMapper::autoTable(new TestClassModel());
		$this->assertEquals('test_class', $name);
	}
	
	function testSplitUpper_TwoWords_OK()
	{
		$actual = DataMapper::splitUpper('TestWord');
		$this->assertEquals(array('Test', 'Word'), $actual);
	}
	
	function testPropertyName_TwoWords_OK()
	{
		$name = DataMapper::propertyName('testTwoThreeFour');
		$this->assertEquals('test_two_three_four', $name);
	}
	
	function testAutoMap_TestClass_OK()
	{
		$actual = DataMapper::autoMap(new TestClassModel());
		$this->assertEquals(array('testProperty' => 'test_property'), $actual);
	}
	
	function testCreate_OK()
	{
		$actual = DataMapper::createByClass('0_', new TestClassModel());
		$this->assertEquals(array('testProperty' => 'test_property'), $actual->map);
		$this->assertEquals('0_test_class', $actual->table);
	}
}