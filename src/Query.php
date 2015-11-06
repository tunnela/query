<?php
/**
 * Query, the easy to use MySQL query builder.
 *
 * @copyright   Copyright 2015, Lauri Tunnela
 * @license     http://tunne.la/MIT.txt The MIT License
 */

namespace Tunnela\Query;

/**
 * The MySQL query builder class.
 */
class Query implements \ArrayAccess {

	const SELECT = 1;

	const DELETE = 2;

	const UPDATE = 3;

	const INSERT = 4;

	/**
	 * Callable used to escape strings.
	 *
	 * @var callable
	 */
	protected static $_escaper = null;

	/**
	 * Should union selects be surrounded by brackets?
	 *
	 * @var bool
	 */
	protected $_unionBrackets = true;

	/**
	 * Query type
	 *
	 * @var integer
	 */
	protected $_type = null;

	/*
	 * Parameters used in query
	 *
	 * @var array
	 */
	protected $_parameters = array();

	/**
	 * Meta parameters' initialization
	 *
	 * @var array
	 */
	protected $_meta = array(
		'select' => array(),
		'delete' => array(),
		'update' => array(),
		'insert' => array(),
		'set' => array(),
		'from' => array(),
		'join' => array(),
		'where' => array(),
		'having' => array(),
		'group' => array(),
		'order' => array(),
		'limit' => array(),
		'columns' => array(),
		'supplement' => false,
		'ignore' => '',
		'into' => '',
		'unions' => array()
	);

	/**
	 * Instance factory
	 *
	 * @param  array $table
	 * @return new Query instance
	 */
	public static function _($table = array()) {
		return new Query($table);
	}

	/**
	 * Inits Query class and sets default properties
	 *
	 * @param mixed $table
	 */
	public function __construct($table = array()) {
		$this->_type = static::SELECT;

		if ($table) {
			$this->from($table);
		}
	}

	/**
	 * Changes query type to SELECT
	 *
	 * @param  mixed $select
	 * @return self
	 */
	public function select($select = '*') {
		$this->_type = static::SELECT;
		$this->_meta['select'] = array_merge($this->_meta['select'], is_array($select) ? $select : array($select));

		return $this;
	}

	/**
	 * Changes query type to DELETE
	 *
	 * @param  mixed $delete
	 * @return self
	 */
	public function delete($delete = array()) {
		$this->_type = static::DELETE;
		$this->_meta['delete'] = array_merge($this->_meta['delete'], is_array($delete) ? $delete : array($delete));

		return $this;
	}

	/**
	 * Changes query type to UPDATE
	 *
	 * @param  mixed $update
	 * @return self
	 */
	public function update($update = array()) {
		$this->_type = static::UPDATE;
		$update = (array) $update;

		foreach ($update as $key => $value) {
			$this->_meta['update'][] = static::getAliasQuery($value, is_int($key) ? null : $key, true);
		}
		return $this;
	}

	/**
	 * Changes query type to INSERT
	 *
	 * @param  array|string $table
	 * @param  mixed        $columns
	 * @return self
	 */
	public function into($table, $columns = array()) {
		$this->_type = static::INSERT;
		$this->_meta['into'] = $table;
		$this->_meta['columns'] = (array) $columns;

		return $this;
	}

	/**
	 * Inserted values
	 *
	 * @param  mixed $inserts Multilevel array for muliple rows. Single level for one insert.
	 * @return self
	 */
	public function insert($inserts = array()) {
		$isQuery = static::isQuery($this->_meta['insert']);

		if (static::isQuery($inserts)) {
			$this->_meta['insert'] = $inserts;
			return $this;
		}
		$multi = true;
		$inserts = (array) $inserts;

		foreach ($inserts as $insert) {
			if (!is_array($insert)) {
				$multi = false;
				break;
			}
		}
		$inserts = $multi ? $inserts : array($inserts);
		$this->_meta['insert'] = $isQuery ? $inserts : array_merge($this->_meta['insert'], $inserts);

		return $this;
	}

	/**
	 * Enable updating part of a MySQL query.
	 *
	 * @param  array|string $supplement
	 * @return self
	 */
	public function supplement($supplement = true) {
		$args = func_get_args();
		$argCount = count($args);

		if (is_bool($supplement)) {
			$this->_meta['supplement'] = $supplement;
		} else {
			if (!is_array($this->_meta['supplement'])) {
				$this->_meta['supplement'] = array();
			}
			if (is_array($supplement)) {
				foreach ($supplement as $key => $sup) {
					if (is_int($key) && is_string($sup)) {
						$supplement[$sup] = Expression::values($sup);
						unset($supplement[$key]);
					}
				}
				$this->_meta['supplement'] = array_merge($this->_meta['supplement'], $supplement);
			} else if ($argCount > 1) {
				$this->_meta['supplement'][$supplement] = $args[1];
			}
		}
		return $this;
	}

	/**
	 * Sets table(s) for SELECT and DELETE queries.
	 *
	 * @param  array|string $table
	 * @return self
	 */
	public function from($table = array()) {
		$table = is_array($table) ? $table : array($table);

		foreach ($table as $key => $value) {
			if (is_int($key)) {
				$this->_meta['from'][] = $value;
			} else {
				$this->_meta['from'][$key] = $value;
			}
		}
		return $this;
	}

	/**
	 * Changes query type to INSERT.
	 *
	 * @param  mixed $insert
	 * @return self
	 */
	public function ignore($ignore = true) {
		$this->_meta['ignore'] = $ignore ? 'IGNORE ' : '';

		return $this;
	}

	/**
	 * Sets order for query.
	 *
	 * @param  string $column   Column used in sorting results
	 * @param  string $order    ASC|DESC
	 * @param  string $collate  Collation used in sorting ie. utf8_unicode_ci
	 * @return self
	 */
	public function order($column, $order = null, $collate = null) {
		if (!preg_match('#^(ASC|DESC)$#i', $order)) {
			unset($order);
		}
		if ($collate) {
			$collate = 'COLLATE ' . $collate;
		} else {
			unset($collate);
		}
		$this->_meta['order'][] = implode(' ', compact('column', 'collate', 'order'));

		return $this;
	}

	/**
	 * Used in update query to update values.
	 *
	 * @param string|array $set Associative array containing new values for given columns (keys) or column name
	 * @return self
	 */
	public function set($set = array()) {
		$args = func_get_args();
		$argCount = count($args);

		if (is_array($set)) {
			$this->_meta['set'] = array_merge($this->_meta['set'], $set);
		} else if ($argCount > 1) {
			$this->_meta['set'][$set] = $args[1];
		}
		return $this;
	}

	/**
	 * Builds join parameter for later use. Supports all join methods.
	 *
	 * @param string       $type  Join type ie. join-left
	 * @param string|array $table Table name or a key-value array where key is alias and value is table name.
	 */
	protected function _join($type, $table, $conditionType = null, $conditions = null) {
		$conditionType = strtoupper($conditionType);
		$conditionTypes = array('USING', 'ON');

		if ($conditions && !in_array($conditionType, $conditionTypes)) {
			throw new \Exception("Invalid condition operator `{$conditionType}`.");
		}

		if ($type == 'join-straight') {
			$query = 'STRAIGHT_JOIN ';
		} else if (preg_match('#^join(\-(inner|cross|(left|right)(\-outer)?|natural(\-(left|right)(\-outer)?)?))?$#', $type, $match)) {
			$query = ltrim(strtoupper(preg_replace('#\-#', ' ', $match[1])) . ' JOIN ');
		} else {
			throw new \Exception("Invalid join type `{$type}`.");
		}
		$graves = true;

		if (static::isExpression($table)) {
			$key = null;
			$value = $table;
		} else {
			$table = (array) $table;
			reset($table);
			$key = key($table);
			$value = $table[$key];
		}
		if (static::isExpression($value)) {
			$value = '(' . $value . ')';
			$graves = false;
		}
		$query .= static::getAliasQuery($value, is_string($key) ? $key : null, $graves);

		if ($conditions) {
			if (is_array($conditions)) {
				array_unshift($conditions, array());

				$where = $this->invokeMethod('_condition', $conditions);
				$conditions = static::_buildConditionQuery($where, null);
			}
			$query .= ' ' . $conditionType . ' ' . trim($conditions);
		}
		$this->_meta['join'][] = $query;

		return $this;
	}

	/**
	 * Adds limit to query
	 */
	public function limit($rowCount = null, $offset = null) {
		if ($rowCount == null) {
			$this->_meta['limit'] = array();
		} else {
			$this->_meta['limit'] = array((int)$offset, (int)$rowCount);
		}
		return $this;
	}

	/**
	 * Adds group by to query
	 */
	public function group($group = array()) {
		$args = func_get_args();
		$argCount = count($args);

		if (is_array($group)) {
			$this->_meta['group'] = array_merge($this->_meta['group'], $group);
		} else {
			$this->_meta['group'][] = $group;
		}
		return $this;
	}	

	/**
	 * This method was barrowed from li3 framework.
	 *
	 * @copyright Copyright 2014, Union of RAD (http://union-of-rad.org)
	 * @license http://opensource.org/licenses/bsd-license.php The BSD License
	 *
	 * Calls a method on this object with the given parameters. Provides an OO wrapper
	 * for call_user_func_array, and improves performance by using straight method calls
	 * in most cases.
	 *
	 * @param string $method  Name of the method to call
	 * @param array $params  Parameter list to use when calling $method
	 * @return mixed  Returns the result of the method call
	 */
	public function invokeMethod($method, $params = array()) {
		switch (count($params)) {
			case 0:
				return $this->{$method}();
			case 1:
				return $this->{$method}($params[0]);
			case 2:
				return $this->{$method}($params[0], $params[1]);
			case 3:
				return $this->{$method}($params[0], $params[1], $params[2]);
			case 4:
				return $this->{$method}($params[0], $params[1], $params[2], $params[3]);
			case 5:
				return $this->{$method}($params[0], $params[1], $params[2], $params[3], $params[4]);
			default:
				return call_user_func_array(array(&$this, $method), $params);
		}
	}

	/**
	 * Adds parameters to where clause.
	 *
	 * @return self
	 */
	public function where() {
		$args = func_get_args();

		array_unshift($args, $this->_meta['where']);

		$this->_meta['where'] = $this->invokeMethod('_condition', $args);

		return $this;
	}

	/**
	 * Adds parameters to having clause
	 *
	 * @return self
	 */
	public function having() {
		$args = func_get_args();
		
		array_unshift($args, $this->_meta['having']);
		
		$this->_meta['having'] = $this->invokeMethod('_condition', $args);

		return $this;
	}

	/**
	 * Builds a condition array for later use by combining new parameters
	 * with the old ones.
	 *
	 * @param  array $meta Old meta array
	 * @param  mixed $where New parameter(s)
	 * @return array New parameter array for later use
	 */
	protected function _condition($meta = array(), $where = array()) {
		$args = func_get_args();
		$argCount = count($args);

		if (is_array($where)) {
			$meta = array_merge($meta, $where);
		} else if ($argCount > 3 || ($argCount == 3 && preg_match('#\{:([a-z0-9_-]+)\}#ui', $where))) {
			$isAssoc = false;

			if (is_array($args[2])) {
				foreach ($args[2] as $key => $value) {
					if (is_string($key)) {
						$isAssoc = true;
						break;
					}
				}
			}
			$meta[] = Expression::params($where, $isAssoc ? $args[2] : array_slice($args, 2));
		} else if ($argCount == 3) {
			$meta[$where] = $args[2];
		} else {
			$meta[] = $where;
		}
		return $meta;
	}

	/**
	 * Enable or disable brackets surrounding union selects.
	 *
	 * @param  bool $brackets
	 * @return self
	 */
	public function unionBrackets($brackets = true) {
		$this->_unionBrackets = $brackets;
		return $this;
	}

	/**
	 * This magic method works as an interface for join and union calls.
	 * It's also used to clear parameter data.
	 *
	 * @return self;
	 */
	public function __call($name, $arguments) {
		if (preg_match('#(join[a-z]*(?=Using|On)|join[a-z]*)(Using$|On$|$)#i', $name, $match) && isset($arguments[0])) {
			$argCount = count($arguments);
			$conditionType = strtoupper($match[2]) == 'USING' ? 'USING' : 'ON';
			$conditions = null;

			if ($argCount > 1) {
				$conditions = $argCount == 2 ? '(' . $arguments[1] . ')' : array_slice($arguments, 1);
			}
			$type = strtolower(preg_replace_callback('#[A-Z]#', function($match) { 
				return "-" . strtolower($match[0]); 
			}, $match[1]));

			return $this->_join(
				$type, 
				$arguments[0],
				$conditionType,
				$conditions
			);
		} else if (preg_match('#union(All|Distinct)?$#i', $name, $match) && isset($arguments[0])) {
			$type = '';
			$union = strtoupper($match[1]);

			if (in_array($union, array('ALL', 'DISTINCT'))) {
				$type = $union;
			}
			return $this->_union($type, $arguments[0]);
		} else if (preg_match('#clear([a-z]+)?$#i', $name, $match)) {
			if ($match[1]) {
				$this->_meta[strtolower($match[1])] = array();
			} else {
				$this->_meta = array();
			}
			return $this;
		}
	}

	/**
	 * Builds a union array for later use by combining new parameters
	 * with the old ones.
	 *
	 * @param  string $type Union type ie. ALL
	 * @param  mixed  $selects The right side of the union
	 * @return self
	 */
	protected function _union($type, $selects) {
		$selects = is_array($selects) ? $selects : array($selects);

		foreach ($selects as $select) {
			if (!static::isQuery($select)) {
				throw new \Exception("Invalid union query.");
			}
			$this->_meta['unions'][] = array($type, $select, $this->_unionBrackets);
		}
		return $this;
	}

	/**
	 * Builds final MySQL query by using given parameters.
	 *
	 * @return MySQL query string
	 */
	public function string() {
		$params = array();

		if ($this->_type == static::SELECT) {
			$query = '{:unionLeft}SELECT {:select}{:from}{:join}{:where}{:group}{:having}{:order}{:limit}{:unionRight}{:union}';
		} else if ($this->_type == static::DELETE) {
			$query = 'DELETE {:delete}{:from}{:join}{:where}{:group}{:having}{:order}{:limit}';
		} else if ($this->_type == static::UPDATE) {
			$query = 'UPDATE {:update}{:join}{:set}{:where}{:group}{:having}{:order}{:limit}';
		} else if ($this->_type == static::INSERT) {
			$query = 'INSERT {:ignore}INTO {:into}{:columns}{:insert}{:supplement}';
		} else {
			throw new \Exception('Invalid Query type.');
		}
		$where = $this->_buildConditionQuery($this->_meta['where']);
		$having = $this->_buildConditionQuery($this->_meta['having']);
		$union = $this->_buildUnionQuery();

		return static::insertParams($query, $params + array(
			'into' => $this->_meta['into'] . ' ',
			'insert' => $this->_buildInsertQuery(),
			'columns' => $this->_buildColumnsQuery(),
			'ignore' => $this->_meta['ignore'],
			'select' => $this->_buildSelectQuery(),
			'delete' => $this->_buildDeleteQuery(),
			'update' => $this->_buildUpdateQuery(),
			'set' => $this->_buildSetQuery(),
			'from' => $this->_buildFromQuery(),
			'join' => $this->_buildJoinQuery(),
			'where' => $where ? 'WHERE ' . $where : '',
			'group' => $this->_buildGroupQuery(),
			'having' => $having ? 'HAVING ' . $having : '',
			'order' => $this->_buildOrderQuery(),
			'limit' => $this->_buildLimitQuery(),
			'supplement' => $this->_buildSupplementQuery(),
			'union' => $union,
			'unionLeft' => $union && $this->_unionBrackets ? '(' : '',
			'unionRight' => $union && $this->_unionBrackets ? ') ' : ''
		));
	}

	/**
	 * Adds grave accents to column name. 
	 * Ie. db.column becomes `db`.`column`.
	 *
	 * @param  $str   Column name
	 * @return string
	 */
	public static function graves($str) {
		$parts = array();

		foreach (explode('.', $str) as $part) {
			$part = trim($part);
			$parts[] = $part == '*' ? $part : '`' . $part . '`';
		}
		return implode('.', $parts);
	}

	/*
	 * Builds `columns` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildColumnsQuery() {
		$cols = array();
		$columns = '';

		if ($this->_meta['columns']) {
			foreach ($this->_meta['columns'] as $key => $column) {
				$cols[] = static::graves($column);
			}
			$columns = '(' . implode(', ', $cols) . ')';
		}
		return $columns ? $columns . ' ' : '';
	}

	/**
	 * Builds `union` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildUnionQuery() {
		$unions = array();

		foreach ($this->_meta['unions'] as $union) {
			$unions[] = 'UNION ' . ($union[0] ? $union[0] . ' ' : '') . 
			($union[2] ? '(' : '') . $union[1] . ($union[2] ? ')' : '');
		}
		return $unions ? implode(' ', $unions) . ' ' : '';
	}

	/**
	 * Builds `insert` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildInsertQuery() {
		if (static::isQuery($this->_meta['insert'])) {
			return ' ' . $this->_meta['insert'];
		}
		$columns = array();
		$insert = array();
		$self = __CLASS__;

		foreach ($this->_meta['insert'] as $values) {
			ksort($values);
			
			array_walk($values, function(&$value, $column) use (&$columns, $self) {
				$columns[] = $column;
				$value = $self::str($value);
			});
			$insert[] = '(' . implode(', ', $values) . ')';
		}
		$columns = array_unique($columns);

		if (!$this->_meta['columns'] && !array_filter($columns, 'is_int')) {
			$this->_meta['columns'] = array_filter($columns, 'is_string');
		}
		return $insert ? 'VALUES ' . implode(', ', $insert) : '';
	}

	/**
	 * Builds `group` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildGroupQuery() {
		return $this->_meta['group'] ? 'GROUP BY ' . implode(', ', $this->_meta['group']) . ' ' : '';
	}

	/**
	 * Builds `supplement` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildSupplementQuery() {
		$supplement = $this->_meta['supplement'];
		$data = array();

		if (is_array($supplement)) {
			foreach ($supplement as $key => $value) {
				if (!static::isExpression($value)) {
					$value = static::str($value);
				}
				$data[] = static::graves($key) . ' = ' . $value;
			}
		} else if ($supplement) {
			foreach ($this->_meta['columns'] as $column) {
				$data[] = static::graves($column) . ' = VALUES(' . static::graves($column) . ')';
			}
		}
		return $data ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $data) : '';
	}

	/**
	 * Builds `set` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildSetQuery() {
		$set = $this->_meta['set'];
		$data = array();

		foreach ($set as $key => $value) {
			$data[] = static::graves($key) . ' = ' . static::str($value);
		}
		return 'SET ' . implode(', ', $data) . ' ';
	}

	/**
	 * Builds `join` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildJoinQuery() {
		return $this->_meta['join'] ? implode(' ', $this->_meta['join']) . ' ' : '';
	}

	/**
	 * Builds `order` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildOrderQuery() {
		return $this->_meta['order'] ? 'ORDER BY ' . implode(', ', $this->_meta['order']) . ' ' : '';
	}

	/**
	 * Builds `limit` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildLimitQuery() {
		return $this->_meta['limit'] ? 'LIMIT ' . implode(', ', $this->_meta['limit']) : '';
	}

	/**
	 * Builds `from` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildFromQuery() {
		$from = array();

		foreach ($this->_meta['from'] as $key => $value) {
			$isExpression = static::isExpression($value);
			$isQuery = static::isQuery($value);

			if ($isQuery) {
				$value = '(' . $value . ')';
			}
			$from[] = static::getAliasQuery($value, is_int($key) ? null : $key, !$isExpression);
		}
		$from = $from ? implode(', ', $from) : '';

		return $from ? 'FROM ' . $from . ' ' : '';
	}

	/**
	 * Builds `delete` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildDeleteQuery() {
		return implode(', ', $this->_meta['delete']) . ' ';
	}

	/**
	 * Builds `update` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildUpdateQuery() {
		return implode(', ', $this->_meta['update']) . ' ';
	}

	/**
	 * Builds `having` or `where` parameter for final MySQL query.
	 * 
	 * @return string
	 */
	protected function _buildConditionQuery($where, $rootKey = null, $root = true) {
		$where = (array) $where;
		$combineWith = 'AND';

		if (isset($where[0]) && is_string($where[0]) && preg_match('#^(AND|OR|XOR|&&|\|\|)$#i', $where[0])) {
			$combineWith = $where[0];
			unset($where[0]);
		}
		$data = array();

		foreach ($where as $key => $value) {
			if (is_callable($value)) {
				$data[] = '(' . $value($this) . ')';
			} else if (static::isExpression($value)) {
				$query = '(' . $value->string($this) . ')';

				if (is_string($key)) {
					$query = static::graves($key) . ' = ' . $query;
				}
				$data[] = $query;
			} else if (is_array($value)) {
				$data[] = $this->_buildConditionQuery($value, is_string($key) ? $key : null, false);
			} else if (is_int($key) && $rootKey === null) {
				$data[] = static::str($value);
			} else {
				$data[] = static::graves($rootKey === null ? $key : $rootKey) . ' = ' . static::str($value);
			}
		}
		$data = implode(' ' . $combineWith . ' ', $data);

		return $data ? ($root ? '' : '(') . $data . ($root ? ' ' : ')') : '';
	}

	/**
	 * Builds `select` parameter for final MySQL query
	 * 
	 * @return string
	 */
	protected function _buildSelectQuery() {
		$select = (array) $this->_meta['select'];
		$data = array();

		foreach ($select as $key => $value) {
			$graves = true;

			if (static::isExpression($value)) {
				$graves = false;
				$value = '(' . $value . ')';
			}
			$data[] = static::getAliasQuery($value, is_string($key) ? $key : null, $graves);
		}
		return $data ? implode(", ", $data) . ' ' : '* ';
	}

	/**
	 * Adds alias or/and grave accents to query.
	 * 
	 * @param  string $query  Ie. column name or query
	 * @param  string $alias  Alias for $query
	 * @param  bool   $graves If true, grave accents are added
	 * @return string
	 */
	public static function getAliasQuery($query, $alias, $graves = true) {
		if (!$alias) {
			unset($alias);
		} else {
			$alias = static::graves($alias);
		}
		if ($graves) {
			$query = static::graves($query);
		}
		return implode(' AS ', compact('query', 'alias'));
	}

	/**
	 * Used to insert values from key => value based array to parameter string.
	 *
	 * @param  string $str    String containing parameter placeholders ie. {:param}
	 * @param  array  $params Parameter array containing key-value pairs ie. param => test. 
	 *                        Value `test` replaces {:param}
	 * @return string         on which placeholders are replaced with correct values
	 */
	public static function insertParams($str, $params, $qstr = false, $removeEmpty = true) {
		$self = __CLASS__;

		return preg_replace_callback('#\{:([a-z0-9_-]+)\}#ui', function($match) use ($params, $qstr, $removeEmpty, $self) {
			$value = isset($params[$match[1]]) ? $params[$match[1]] : ($removeEmpty ? '' : $match[0]);
			return $qstr && !$self::isExpression($value) ? $self::str($value) : $value;
		}, $str);
	}

	/**
	 * Used to check whether given variable can be used as an expression.
	 *
	 * @param  mixed $value Variable to check
	 * @return bool
	 */
	public static function isExpression($value) {
		return $value instanceof Expression || $value instanceof static;
	}

	/**
	 * Used to check whether given variable is instance of Query
	 *
	 * @param  mixed $value Variable to check
	 * @return bool
	 */
	public static function isQuery($value) {
		return $value instanceof static;
	}

	/**
	 * Used to escape scalar or attach escape callable.
	 *
	 * @param  scalar|callable $callable Scalar to escape or callable for escaping.
	 * @return string|null
	 */
	public static function escape($callable) {
		if ($callable === null) {
			return Expression::_('NULL');
		} else if (is_scalar($callable)) {
			return static::$_escaper ? call_user_func(static::$_escaper, $callable) : $callable;
		} else if (is_callable($callable)) {
			static::$_escaper = $callable;
		} else {
			throw new \Exception("Given argument is not callable nor scalar.");
		}
	}

	/**
	 * Escapes and converts array to comma separated string.
	 *
	 * @param  array $arr Array to convert
	 * @return string
	 */
	public static function listify($arr) {
		foreach ($arr as &$value) {
			$value = static::str($value);
			unset($value);
		}
		return implode(', ', $arr);
	}

	/**
	 * Escapes and adds quotes to string.
	 *
	 * @param  string $str String to escape
	 * @return string
	 */
	public static function str($str) {
		if ($str === null) {
			return static::escape($str);
		}
		if (is_array($str)) {
			return static::listify($str);
		}
		return "'" . static::escape($str) . "'";
	}

	/**
	 * Escapes ie. string %text%_% to \%text\%\_\%
	 *
	 * @param  string $str String to escape
	 * @return string
	 */
	public static function like($str) {
		return str_replace(array('%', '_'), array('\\%', '\\_'), static::escape($str));
	}

	public function offsetSet($offset, $value) {
		if (is_null($offset)) {
			$this->_parameters[] = $value;
		} else {
			$this->_parameters[$offset] = $value;
		}
		return $this;
	}

	public function offsetExists($offset) {
		return isset($this->_parameters[$offset]);
	}

	public function offsetUnset($offset) {
		unset($this->_parameters[$offset]);
		return $this;
	}

	public function offsetGet($offset) {
		return isset($this->_parameters[$offset]) ? $this->_parameters[$offset] : null;
	}

	public function parameters($parameters = array()) {
		if (!$parameters) {
			return $this->_parameters;
		}
		$this->_parameters = $parameters + $this->_parameters;

		return $this;
	}

	public function __toString() {
		try {
			return $this->string();
		} catch (\Exception $e) {
			trigger_error($e, E_USER_ERROR);
		}
	}
}

?>