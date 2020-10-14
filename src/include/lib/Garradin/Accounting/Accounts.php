<?php

namespace Garradin\Accounting;

use Garradin\Entities\Accounting\Account;
use Garradin\Utils;
use Garradin\DB;
use KD2\DB\EntityManager;

class Accounts
{
	protected $chart_id;
	protected $em;

	public function __construct(int $chart_id)
	{
		$this->chart_id = $chart_id;
		$this->em = EntityManager::getInstance(Account::class);
	}

	static public function get(int $id)
	{
		return EntityManager::findOneById(Account::class, $id);
	}

	public function getIdFromCode(string $code): int
	{
		return $this->em->col('SELECT id FROM @TABLE WHERE code = ?;', $code);
	}

	/**
	 * Return common accounting accounts from current chart
	 * (will not return analytical and volunteering accounts)
	 */
	public function listCommonTypes(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? AND type != 0 AND type NOT IN (?, ?) ORDER BY code COLLATE NOCASE;',
			$this->chart_id, Account::TYPE_ANALYTICAL, Account::TYPE_VOLUNTEERING);
	}

	/**
	 * Return all accounts from current chart
	 */
	public function listAll(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? ORDER BY code COLLATE NOCASE;',
			$this->chart_id);
	}

	/**
	 * Return only analytical accounts
	 */
	public function listAnalytical(): array
	{
		return $this->em->DB()->getAssoc($this->em->formatQuery('SELECT id, label FROM @TABLE WHERE id_chart = ? AND type = ? ORDER BY code COLLATE NOCASE;'), $this->chart_id, Account::TYPE_ANALYTICAL);
	}

	/**
	 * Return only analytical accounts
	 */
	public function listVolunteering(): array
	{
		return $this->em->all('SELECT * FROM @TABLE WHERE id_chart = ? AND type = ? ORDER BY code COLLATE NOCASE;',
			$this->chart_id, Account::TYPE_VOLUNTEERING);
	}

	/**
	 * List common accounts, grouped by type
	 * @return array
	 */
	public function listCommonGrouped(array $types = null): array
	{
		if (null === $types) {
			$types = '';
		}
		else {
			$types = array_map('intval', $types);
			$types = ' AND ' . $this->em->DB()->where('type', $types);
		}

		$out = [];
		$query = $this->em->iterate('SELECT * FROM @TABLE WHERE id_chart = ? AND type != 0 ' . $types . ' ORDER BY type, code COLLATE NOCASE;',
			$this->chart_id);

		foreach ($query as $row) {
			if (!isset($out[$row->type])) {
				$out[$row->type] = (object) [
					'label'    => Account::TYPES_NAMES[$row->type],
					'type'     => $row->type,
					'accounts' => [],
				];
			}

			$out[$row->type]->accounts[] = $row;
		}

		return $out;
	}

	public function getNextCodesForTypes(): array
	{
		$db = DB::getInstance();
		$codes = $db->getAssoc(sprintf('SELECT type, MAX(code) FROM %s WHERE id_chart = ? AND type > 0 GROUP BY type;', Account::TABLE), $this->chart_id);

		foreach ($codes as &$code) {
			if (($letter = substr($code, -1)) && !is_numeric($letter)) {
				$code = substr($code, 0, -1);
				$letter = strtoupper($letter);
				$letter = ($letter == 'Z') ? 'AA' : chr(ord($letter)+1);
			}
			else {
				$letter = 'A';
			}

			$code = $code . $letter;
		}

		unset($code);
		return $codes;
	}
}