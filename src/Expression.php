<?php
/**
 * Query, the easy to use MySQL query builder.
 *
 * @copyright   Copyright 2015, Lauri Tunnela
 * @license     http://tunne.la/MIT.txt The MIT License
 */

namespace Tunnela\Query;

/**
 * Expression class is used in combination with the Query class.
 */
class Expression {

	const RAW = '_';

	const PARAMS = 'params';

	const SPRINTF = 'sprintf';

	const GRAVES = 'graves';

	const VALUES = 'values';

	const LIKE = 'like';

	const STARTS_LIKE = 'startsLike';

	const ENDS_LIKE = 'endsLike';

	const CALLBACK = 'callback';

	protected $_type;

	protected $_options;

	protected static $_handlers = array();

	protected function __construct($options = array(), $type = null) {
		static::$_handlers = array(
			static::RAW => function($options) {
				return $options[0];
			},
			static::PARAMS => function($options) {
				if (!empty($options[1]) && !is_array($options[1])) {
					$options[1] = array($options[1]);
				}
				return Query::insertParams($options[0], $options[1], isset($options[2]) ? $options[2] : true);
			},
			static::SPRINTF => function($options) {
				return call_user_func_array('sprintf', $options);
			},
			static::GRAVES => function($options) {
				return Query::graves($options[0]);
			},
			static::VALUES => function($options) {
				return 'VALUES(' . Query::graves($options[0]) . ')';
			},
			static::LIKE => function($options) {
				if (count($options) >= 2) {
					return Query::graves($options[0]) . " LIKE '%" . Query::like($options[1]) . "%'";
				}
				return "'%" . Query::like($options[0]) . "%'";
			},
			static::ENDS_LIKE => function($options) {
				if (count($options) >= 2) {
					return Query::graves($options[0]) . " LIKE '%" . Query::like($options[1]) . "'";
				}
				return "'%" . Query::like($options[0]) . "'";
			},
			static::STARTS_LIKE => function($options) {
				if (count($options) >= 2) {
					return Query::graves($options[0]) . " LIKE '" . Query::like($options[1]) . "%'";
				}
				return "'" . Query::like($options[0]) . "%'";
			},
			static::CALLBACK => function($options, $query) {
				if (!$query || empty($options[0]) || !is_callable($options[0])) {
					return '';
				}
				return call_user_func($options[0], $query);
			}
		);

		$type = $type ?: static::RAW;

		if (!isset(static::$_handlers[$type])) {
			throw new \Exception('Unknown expression type `$type`');
		}
		$this->_type = $type;
		$this->_options = $options;
	}

	/**
	 * Instance factory
	 * 
	 * @param  string 	$type Expression type
	 * @param  array 	$options Options used in expression formation
	 * @return self
	 */
	public static function __callStatic($type, $options) {
		return new static($options, $type);
	}

	/**
	 * Converts expression instance to string
	 * 
	 * @param  Tunnela\Query $query Instance of Query using current Expression
	 * @return string
	 */
	public function string($query = null) {
		$handler = static::$_handlers[$this->_type];
		return (string) $handler($this->_options, $query);
	}

	/**
	 * Magic method to return expression string
	 * 
	 * @return string
	 */
	public function __toString() {
		try {
			return $this->string();
		} catch (\Exception $e) {
			trigger_error($e, E_USER_ERROR);
		}
	}
}

?>