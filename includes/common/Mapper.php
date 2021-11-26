<?php
namespace SGW\common;

class Mapper
{
    public static function writeArray(&$c, &$data, $exclude = array()) {
        $mapper = $c->_mapper;
        if ($data) {
			foreach ($mapper->map as $property => $field) {
				if ($property[0] == '_') {
					continue;
				}
				if (!in_array($property, $exclude)) {
					$data[$field] = $c->$property;
				}
			}
			return true;
		}
		return false;
    }

}