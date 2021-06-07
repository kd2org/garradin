<?php

namespace Garradin;

class DynamicList implements \Countable
{
	protected $columns;
	protected $tables;
	protected $conditions;
	protected $group;
	protected $order;
	protected $modifier;
	protected $export_callback;
	protected $title = 'Liste';
	protected $count = 'COUNT(*)';
	protected $desc = true;
	protected $per_page = 100;
	protected $page = 1;

	private $count_result;

	public function __construct(array $columns, string $tables, string $conditions = '1')
	{
		$this->columns = $columns;
		$this->tables = $tables;
		$this->conditions = $conditions;
		$this->order = key($columns);
	}

	public function __isset($key)
	{
		return property_exists($this, $key);
	}

	public function __get($key)
	{
		return $this->$key;
	}

	public function setTitle(string $title) {
		$this->title = $title;
	}

	public function setModifier(callable $fn) {
		$this->modifier = $fn;
	}

	public function setExportCallback(callable $fn) {
		$this->export_callback = $fn;
	}

	public function setPageSize(?int $size) {
		$this->per_page = $size;
	}

	public function setConditions(string $conditions)
	{
		$this->conditions = $conditions;
	}

	public function orderBy(string $key, bool $desc)
	{
		if (!array_key_exists($key, $this->columns)) {
			throw new UserException('Invalid order: ' . $key);
		}

		$this->order = $key;
		$this->desc = $desc;
	}

	public function groupBy(string $value)
	{
		$this->group = $value;
	}

	public function count(): int
	{
		if (null === $this->count_result) {
			$sql = sprintf('SELECT %s FROM %s WHERE %s;', $this->count, $this->tables, $this->conditions);
			$this->count_result = DB::getInstance()->firstColumn($sql);
		}

		return (int) $this->count_result;
	}

	public function export(string $name, string $format = 'csv')
	{
		$this->setPageSize(null);
		$columns = [];

		foreach ($this->columns as $key => $column) {
			if (empty($column['label'])) {
				$columns[] = $key;
				continue;
			}

			$columns[] = $column['label'];
		}

		if ('csv' == $format) {
			CSV::toCSV($name, $this->iterate(false), $this->getHeaderColumns(true), $this->export_callback);
		}
		else if ('ods' == $format) {
			CSV::toODS($name, $this->iterate(false), $this->getHeaderColumns(true), $this->export_callback);
		}
		else {
			throw new UserException('Invalid export format');
		}
	}

	public function asArray(): array
	{
		$out = [];

		foreach ($this->iterate(true) as $row) {
			$out[] = $row;
		}

		return $out;
	}

	public function paginationURL()
	{
		return Utils::getModifiedURL('?p=[ID]');
	}

	public function orderURL(string $order, bool $desc)
	{
		$query = array_merge($_GET, ['o' => $order, 'd' => (int) $desc]);
		$url = Utils::getSelfURI($query);
		return $url;
	}

	public function setCount(string $count)
	{
		$this->count = $count;
	}

	public function getHeaderColumns(bool $label_only = false)
	{
		$columns = [];

		foreach ($this->columns as $alias => $properties) {
			if (isset($properties['only_with_order']) && !($properties['only_with_order'] == $this->order && !$this->desc)) {
				continue;
			}

			// Skip columns that require a certain order AND paginated result
			if (isset($properties['only_with_order']) && $this->page > 1) {
				continue;
			}

			if (!isset($properties['label'])) {
				continue;
			}

			$columns[$alias] = $label_only ? $properties['label'] : $properties;
		}

		return $columns;
	}

	public function iterate(bool $include_hidden = true)
	{
		$start = ($this->page - 1) * $this->per_page;
		$columns = [];

		foreach ($this->columns as $alias => $properties) {
			// Skip columns that require a certain order (eg. calculating a running sum)
			if (isset($properties['only_with_order']) && !($properties['only_with_order'] == $this->order && !$this->desc)) {
				continue;
			}

			// Skip columns that require a certain order AND paginated result
			if (isset($properties['only_with_order']) && $this->page > 1) {
				continue;
			}

			if (!isset($properties['label']) && !$include_hidden) {
				continue;
			}

			$select = array_key_exists('select', $properties) ? $properties['select'] : $alias;

			if (null === $select) {
				$select = 'NULL';
			}

			$columns[] = sprintf('%s AS %s', $select, $alias);
		}

		$columns = implode(', ', $columns);

		if (isset($this->columns[$this->order]['order'])) {
			$order = sprintf($this->columns[$this->order]['order'], $this->desc ? 'DESC' : 'ASC');
		}
		else {
			$order = $this->order;

			if (true === $this->desc) {
				$order .= ' DESC';
			}
		}

		$group = $this->group ? 'GROUP BY ' . $this->group : '';

		$sql = sprintf('SELECT %s FROM %s WHERE %s %s ORDER BY %s',
			$columns, $this->tables, $this->conditions, $group, $order);

		if (null !== $this->per_page) {
			$sql .= sprintf(' LIMIT %d,%d', $start, $this->per_page);
		}

		foreach (DB::getInstance()->iterate($sql) as $row) {
			if ($this->modifier) {
				call_user_func_array($this->modifier, [&$row]);
			}

			yield $row;
		}
	}

	public function loadFromQueryString()
	{
		if (!empty($_GET['export'])) {
			$this->export($this->title, $_GET['export']);
			exit;
		}

		if (!empty($_GET['o'])) {
			$this->orderBy($_GET['o'], !empty($_GET['d']));
		}

		if (!empty($_GET['p'])) {
			$this->page = (int)$_GET['p'];
		}
	}
}