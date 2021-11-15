<?php
namespace SGW_Sales\db;

class DB
{
    private static $tablePrefix = '';

    public static function init($tablePrefix) {
        self::$tablePrefix = $tablePrefix;
    }

    public static function tablePrefix() {
        return self::$tablePrefix;
    }

    public static function prefix($table) {
        return "`" . self::$tablePrefix . $table . "`";
    }
}