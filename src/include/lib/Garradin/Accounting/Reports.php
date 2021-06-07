<?php

namespace Garradin\Accounting;

use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Accounting\Line;
use Garradin\Entities\Accounting\Transaction;
use Garradin\Utils;
use Garradin\DB;
use KD2\DB\EntityManager;

class Reports
{
	static public function getWhereClause(array $criterias): string
	{
		$where = [];

		if (!empty($criterias['year'])) {
			$where[] = sprintf('t.id_year = %d', $criterias['year']);
		}

		if (!empty($criterias['position'])) {
			$db = DB::getInstance();
			$where[] = $db->where('position', $criterias['position']);
		}

		if (!empty($criterias['exclude_position'])) {
			$db = DB::getInstance();
			$where[] = $db->where('position', 'NOT IN', $criterias['exclude_position']);
		}

		if (!empty($criterias['type'])) {
			$criterias['type'] = array_map('intval', (array)$criterias['type']);
			$where[] = sprintf('a.type IN (%s)', implode(',', $criterias['type']));
		}

		if (!empty($criterias['exclude_type'])) {
			$criterias['exclude_type'] = array_map('intval', (array)$criterias['exclude_type']);
			$where[] = sprintf('a.type NOT IN (%s)', implode(',', $criterias['exclude_type']));
		}

		if (!empty($criterias['user'])) {
			$where[] = sprintf('t.id IN (SELECT id_transaction FROM acc_transactions_users WHERE id_user = %d)', $criterias['user']);
		}

		if (!empty($criterias['creator'])) {
			$where[] = sprintf('t.id_creator = %d', $criterias['creator']);
		}

		if (!empty($criterias['service_user'])) {
			$where[] = sprintf('t.id IN (SELECT tu.id_transaction FROM acc_transactions_users tu WHERE id_service_user = %d)', $criterias['service_user']);
		}

		if (!empty($criterias['analytical'])) {
			$where[] = sprintf('l.id_analytical = %d', $criterias['analytical']);
		}

		if (!count($where)) {
			throw new \LogicException('Unknown criteria');
		}

		return implode(' AND ', $where);
	}

	/**
	 * Return account sums per year or per account
	 * @param  bool $by_year If true will return accounts grouped by year, if false it will return years grouped by account
	 */
	static public function getAnalyticalSums(bool $by_year = false): \Generator
	{
		$sql = 'SELECT a.label AS account_label, a.description AS account_description, a.id AS id_account,
			y.id AS id_year, y.label AS year_label, y.start_date, y.end_date,
			SUM(l.credit - l.debit) AS sum, SUM(l.credit) AS credit, SUM(l.debit) AS debit,
			(SELECT SUM(l2.credit - l2.debit) FROM acc_transactions_lines l2
				INNER JOIN acc_transactions t2 ON t2.id = l2.id_transaction
				INNER JOIN acc_accounts a2 ON a2.id = l2.id_account
				WHERE a2.position = %d AND l2.id_analytical = l.id_analytical AND t2.id_year = t.id_year) * -1 AS sum_expense,
			(SELECT SUM(l2.credit - l2.debit) FROM acc_transactions_lines l2
				INNER JOIN acc_transactions t2 ON t2.id = l2.id_transaction
				INNER JOIN acc_accounts a2 ON a2.id = l2.id_account
				WHERE a2.position = %d AND l2.id_analytical = l.id_analytical AND t2.id_year = t.id_year) AS sum_revenue
			FROM acc_transactions_lines l
			INNER JOIN acc_transactions t ON t.id = l.id_transaction
			INNER JOIN acc_accounts a ON a.id = l.id_analytical
			INNER JOIN acc_years y ON y.id = t.id_year
			GROUP BY %s
			ORDER BY %s;';

		if ($by_year) {
			$group = 'y.id, a.id';
			$order = 'y.start_date DESC, a.code COLLATE NOCASE';
		}
		else {
			$group = 'a.id, y.id';
			$order = 'a.code COLLATE NOCASE, y.id';
		}

		$sql = sprintf($sql, Account::EXPENSE, Account::REVENUE, $group, $order);

		$current = null;

		static $sums = ['credit', 'debit', 'sum'];

		$total = function (\stdClass $current, bool $by_year) use ($sums)
		{
			$out = (object) [
				'label' => 'Total',
				'id_account' => $by_year ? null : $current->id,
				'id_year' => $by_year ? $current->id : null,
			];

			foreach ($sums as $s) {
				$out->{$s} = $current->{$s};
			}

			return $out;
		};

		foreach (DB::getInstance()->iterate($sql) as $row) {
			$id = $by_year ? $row->id_year : $row->id_account;

			if (null !== $current && $current->id !== $id) {
				$current->items[] = $total($current, $by_year);

				yield $current;
				$current = null;
			}

			if (null === $current) {
				$current = (object) [
					'id' => $by_year ? $row->id_year : $row->id_account,
					'label' => $by_year ? $row->year_label : $row->account_label,
					'description' => !$by_year ? $row->account_description : null,
					'items' => []
				];

				foreach ($sums as $s) {
					$current->$s = 0;
				}
			}

			$row->label = !$by_year ? $row->year_label : $row->account_label;
			$current->items[] = $row;

			foreach ($sums as $s) {
				$current->$s += $row->$s;
			}
		}

		if ($current === null) {
			return;
		}

		$current->items[] = $total($current, $by_year);

		yield $current;
	}

	static public function getSumsByInterval(array $criterias, int $interval)
	{
		$where = self::getWhereClause($criterias);
		$where_interval = !empty($criterias['year']) ? sprintf(' WHERE id_year = %d', $criterias['year']) : '';

		$db = DB::getInstance();

		$sql = sprintf('SELECT
			strftime(\'%%s\', MIN(date)) / %d AS start_interval,
			strftime(\'%%s\', MAX(date)) / %1$d AS end_interval
			FROM acc_transactions %s;',
			$interval, $where_interval);

		$result = (array)$db->first($sql);
		extract($result);

		if (!isset($start_interval, $end_interval)) {
			return [];
		}

		$out = array_fill_keys(range($start_interval, $end_interval), 0);

		$sql = sprintf('SELECT strftime(\'%%s\', t.date) / %d AS interval, SUM(l.credit) - SUM(l.debit) AS sum, t.id_year
			FROM acc_transactions t
			INNER JOIN acc_transactions_lines l ON l.id_transaction = t.id
			INNER JOIN acc_accounts a ON a.id = l.id_account
			WHERE %s
			GROUP BY %s ORDER BY %3$s;', $interval, $where, isset($criterias['year']) ? 'interval' : 't.id_year, interval');

		$data = $db->getGrouped($sql);
		$sum = 0;
		$year = null;

		foreach ($out as $k => &$v) {
			if (array_key_exists($k, $data)) {
				$row = $data[$k];
				if ($row->id_year != $year) {
					$sum = 0;
					$year = $row->id_year;
				}

				$sum += $data[$k]->sum;
			}

			$v = $sum;
		}

		unset($v);

		return $out;
	}

	static public function getResult(array $criterias): int
	{
		$where = self::getWhereClause($criterias);
		$sql = sprintf('SELECT SUM(l.credit) - SUM(l.debit)
			FROM %s l
			INNER JOIN %s t ON t.id = l.id_transaction
			INNER JOIN %s a ON a.id = l.id_account
			WHERE %s AND a.position = ?;',
			Line::TABLE, Transaction::TABLE, Account::TABLE, $where);

		$db = DB::getInstance();
		$a = $db->firstColumn($sql, Account::REVENUE);
		$b = $db->firstColumn($sql, Account::EXPENSE);

		return (int)$a - (int)$b * -1;
	}

	static public function getClosingSumsWithAccounts(array $criterias, ?string $order = null, bool $reverse = false, bool $remove_zero = true): array
	{
		$where = self::getWhereClause($criterias);

		$order = $order ?: 'a.code COLLATE NOCASE';
		$reverse = $reverse ? '* -1' : '';
		$remove_zero = $remove_zero ? 'HAVING sum != 0' : '';

		// Find sums, link them to accounts
		$sql = sprintf('SELECT a.id, a.code, a.label, a.position, SUM(l.credit) AS credit, SUM(l.debit) AS debit,
			SUM(l.credit - l.debit) %s AS sum
			FROM %s l
			INNER JOIN %s t ON t.id = l.id_transaction
			INNER JOIN %s a ON a.id = l.id_account
			WHERE %s
			GROUP BY l.id_account
			%s
			ORDER BY %s;',
			$reverse, Line::TABLE, Transaction::TABLE, Account::TABLE, $where, $remove_zero, $order);
		return DB::getInstance()->getGrouped($sql);
	}

	static public function getTrialBalance(array $criterias): array
	{
		return self::getClosingSumsWithAccounts($criterias, null, false, false);
	}

	static public function getBalanceSheet(array $criterias): array
	{
		$out = [
			Account::ASSET => [],
			Account::LIABILITY => [],
			'sums' => [
				Account::ASSET => 0,
				Account::LIABILITY => 0,
			],
		];

		$position_criteria = ['position' => [Account::ASSET, Account::LIABILITY, Account::ASSET_OR_LIABILITY]];
		$list = self::getClosingSumsWithAccounts($criterias + $position_criteria);

		foreach ($list as $row) {
			if ($row->sum == 0) {
				// Ignore empty accounts
				continue;
			}

			$position = $row->position;

			if ($position == Account::ASSET_OR_LIABILITY) {
				$position = $row->sum < 0 ? Account::ASSET : Account::LIABILITY;
				$row->sum = abs($row->sum);
			}
			elseif ($position == Account::ASSET) {
				// reverse number for assets
				$row->sum *= -1;
			}

			$out[$position][] = $row;
		}

		$result = self::getResult($criterias);

		if ($result != 0) {
			$out[Account::LIABILITY][] = (object) [
				'id' => null,
				'label' => $result > 0 ? 'Résultat de l\'exercice courant (excédent)' : 'Résultat de l\'exercice courant (perte)',
				'sum' => $result,
			];
		}

		// Calculate the total sum for assets and liabilities
		foreach ($out as $position => $rows) {
			if ($position == 'sums') {
				continue;
			}

			$sum = 0;
			foreach ($rows as $row) {
				$sum += $row->sum;
			}

			$out['sums'][$position] = $sum;
		}

		return $out;
	}

	/**
	 * Return list of favorite accounts (accounts with a type), grouped by type, with their current sum
	 * @return \Generator list of accounts grouped by type
	 */
	static public function getClosingSumsFavoriteAccounts(array $criterias): \Generator
	{
		$where = self::getWhereClause($criterias);

		$sql = sprintf('SELECT a.id, a.code, a.label, a.description, a.type,
			SUM(l.credit) - SUM(l.debit) AS sum
			FROM %s a
			INNER JOIN %s t ON t.id = l.id_transaction
			INNER JOIN %s l ON a.id = l.id_account
			WHERE a.type != 0 AND %s
			GROUP BY l.id_account
			ORDER BY a.type, a.code COLLATE NOCASE;', Account::TABLE, Transaction::TABLE, Line::TABLE, $where);

		$group = null;

		foreach (DB::getInstance()->iterate($sql) as $row) {
			if (null !== $group && $row->type !== $group->type) {
				yield $group;
				$group = null;
			}

			if (null === $group) {
				$group = (object) [
					'label'    => Account::TYPES_NAMES[$row->type],
					'type'     => $row->type,
					'accounts' => []
				];
			}

			$reverse = Account::isReversed($row->type) ? -1 : 1;
			$row->sum *= $reverse;

			$group->accounts[] = $row;
		}

		if (null !== $group) {
			yield $group;
		}
	}

	/**
	 * Grand livre
	 */
	static public function getGeneralLedger(array $criterias): \Generator
	{
		$where = self::getWhereClause($criterias);

		$db = DB::getInstance();

		$sql = sprintf('SELECT
			t.id_year, l.id_account, t.id, t.date, t.reference,
			l.debit, l.credit, l.reference AS line_reference, t.label, l.label AS line_label,
			a.label AS account_label, a.code AS account_code
			FROM acc_transactions t
			INNER JOIN acc_transactions_lines l ON l.id_transaction = t.id
			INNER JOIN acc_accounts a ON a.id = l.id_account
			WHERE %s
			ORDER BY a.code COLLATE NOCASE, t.date, t.id;', $where);

		$account = null;
		$debit = $credit = 0;

		foreach ($db->iterate($sql) as $row) {
			if (null !== $account && $account->id != $row->id_account) {
				yield $account;
				$account = null;
			}

			if (null === $account) {
				$account = (object) [
					'code'  => $row->account_code,
					'label' => $row->account_label,
					'id'    => $row->id_account,
					'id_year' => $row->id_year,
					'sum'   => 0,
					'debit' => 0,
					'credit'=> 0,
					'lines' => [],
				];
			}

			$row->date = \DateTime::createFromFormat('Y-m-d', $row->date);

			$account->sum += ($row->credit - $row->debit);
			$account->debit += $row->debit;
			$account->credit += $row->credit;
			$debit += $row->debit;
			$credit += $row->credit;
			$row->running_sum = $account->sum;

			unset($row->account_code, $row->account_label, $row->id_account, $row->id_year);

			$account->lines[] = $row;
		}

		if (null === $account) {
			return;
		}

		$account->all_debit = $debit;
		$account->all_credit = $credit;

		yield $account;
	}

	static public function getJournal(array $criterias): \Generator
	{
		$where = self::getWhereClause($criterias);

		$sql = sprintf('SELECT
			t.id_year, l.id_account, l.debit, l.credit, t.id, t.date, t.reference,
			l.reference AS line_reference, t.label, l.label AS line_label,
			a.label AS account_label, a.code AS account_code
			FROM acc_transactions t
			INNER JOIN acc_transactions_lines l ON l.id_transaction = t.id
			INNER JOIN acc_accounts a ON l.id_account = a.id
			WHERE %s ORDER BY t.date, t.id;', $where);

		$transaction = null;
		$db = DB::getInstance();

		foreach ($db->iterate($sql) as $row) {
			if (null !== $transaction && $transaction->id != $row->id) {
				yield $transaction;
				$transaction = null;
			}

			if (null === $transaction) {
				$transaction = (object) [
					'id'        => $row->id,
					'label'     => $row->label,
					'date'      => \DateTime::createFromFormat('Y-m-d', $row->date),
					'reference' => $row->reference,
					'lines'     => [],
				];
			}

			$transaction->lines[] = (object) [
				'account_label' => $row->account_label,
				'account_code'  => $row->account_code,
				'label'         => $row->line_label,
				'reference'     => $row->line_reference,
				'id_account'    => $row->id_account,
				'credit'        => $row->credit,
				'debit'         => $row->debit,
				'id_year'       => $row->id_year,
			];
		}

		if (null === $transaction) {
			return;
		}

		yield $transaction;
	}

	static public function getStatement(array $criterias): array
	{
		$revenue = Reports::getClosingSumsWithAccounts($criterias + ['position' => Account::REVENUE]);
		$expense = Reports::getClosingSumsWithAccounts($criterias + ['position' => Account::EXPENSE], null, true);

		$get_sum = function (array $in): int {
			$sum = 0;

			foreach ($in as $row) {
				$sum += $row->sum;
			}

			return $sum;
		};

		$revenue_sum = $get_sum($revenue);
		$expense_sum = $get_sum($expense);
		$result = $revenue_sum - $expense_sum;

		return compact('revenue', 'expense', 'revenue_sum', 'expense_sum', 'result');
	}
}
