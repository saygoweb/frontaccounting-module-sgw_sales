<?php

ini_set('xdebug.show_exception_trace', 0);

if (file_exists(__DIR__ . '/../../_frontaccounting')) {
	$rootPath = realpath(__DIR__ . '/../../_frontaccounting');
} else {
	$rootPath = realpath(__DIR__ . '/../../../..');
}

define('ROOT_PATH', $rootPath);
define('SRC_PATH', $rootPath);
define('TEST_PATH', $rootPath . '/modules/tests/php');

