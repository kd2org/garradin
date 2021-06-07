<?php

namespace Garradin\Membres;

use Garradin\Membres;
use Garradin\Config;
use Garradin\DB;
use Garradin\Utils;
use Garradin\CSV;
use Garradin\CSV_Custom;
use Garradin\UserException;

class Import
{
	protected $champs;

	/**
	 * Importer un CSV générique
	 * @return boolean                   TRUE en cas de succès
	 */
	public function fromCustomCSV(CSV_Custom $csv, int $current_user_id)
	{
		$db = DB::getInstance();
		$db->begin();
		$membres = new Membres;

		foreach ($csv->iterate() as $line => $row)
		{
			if (!empty($row->numero) && $row->numero > 0)
			{
				$numero = (int)$row->numero;
			}
			else
			{
				unset($row->numero);
				$numero = false;
			}

			try {
				if ($numero && ($id = $membres->getIDWithNumero($numero)))
				{
					if ($id === $current_user_id)
					{
						// Ne pas modifier le membre courant, on risque de se tirer une balle dans le pied
						continue;
					}

					$membres->edit($id, (array)$row);
				}
				else
				{
					$membres->add((array)$row, false);
				}
			}
			catch (UserException $e)
			{
				$db->rollback();
				throw new UserException('Erreur sur la ligne ' . $line . ' : ' . $e->getMessage());
			}
		}

		$db->commit();
		return true;
	}

	/**
	 * Importer un CSV de la liste des membres depuis un export Garradin
	 * @param  string $path     Chemin vers le CSV
	 * @param  int    $current_user_id
	 * @return boolean          TRUE en cas de succès
	 */
	public function fromGarradinCSV($path, $current_user_id)
	{
		if (!file_exists($path) || !is_readable($path))
		{
			throw new \RuntimeException('Fichier inconnu : '.$path);
		}

		$fp = CSV::open($path);

		if (!$fp)
		{
			return false;
		}

		$db = DB::getInstance();
		$db->begin();
		$membres = new Membres;

		// On récupère les champs qu'on peut importer
		$champs_membres = Config::getInstance()->get('champs_membres');
		$champs_multiples = $champs_membres->getMultiples();
		$champs = $champs_membres->getKeys();
		$champs[] = 'date_inscription';

		$line = 0;
		$delim = CSV::findDelimiter($fp);
		CSV::skipBOM($fp);

		while (!feof($fp))
		{
			$row = fgetcsv($fp, 4096, $delim);

			$line++;

			if (empty($row))
			{
				continue;
			}

			if ($line == 1)
			{
				if (empty($row[0]) || !is_string($row[0]) || is_numeric($row[0]))
				{
					$db->rollback();
					throw new UserException('Erreur sur la ligne 1 : devrait contenir l\'en-tête des colonnes.');
				}

				$columns = array_flip($row);
				continue;
			}

			if (!isset($columns)) {
				throw new UserException('Entête introuvable');
			}

			if (count($row) != count($columns))
			{
				$db->rollback();
				throw new UserException('Erreur sur la ligne ' . $line . ' : le nombre de colonnes est incorrect.');
			}

			$data = [];

			foreach ($columns as $name=>$id)
			{
				$name = trim($name);

				// Champs qui n'existent pas dans le schéma actuel
				if (!in_array($name, $champs))
					continue;

				// Ignorer les champs vides
				if (trim($row[$id]) === '') {
					continue;
				}

				$data[$name] = $row[$id];

				// Restitution de la valeur binaire des champs à choix multiple
				if (isset($champs_multiples[$name])) {
					$values = explode(';', $data[$name]);
					$data[$name] = 0;

					foreach ($values as $v) {
						$v = trim($v);
						$found = array_search($v, $champs_multiples[$name]->options);

						if ($found !== false) {
							$data[$name] |= 0x01 << $found;
						}
					}
				}
			}

			if (!empty($data['numero']) && $data['numero'] > 0)
			{
				$numero = (int)$data['numero'];
			}
			else
			{
				unset($data['numero']);
				$numero = false;
			}

			try {
				if ($numero && ($id = $membres->getIDWithNumero($numero)))
				{
					if ($id === $current_user_id)
					{
						// Ne pas modifier le membre courant, on risque de se tirer une balle dans le pied
						continue;
					}

					$membres->edit($id, $data);
				}
				else
				{
					$membres->add($data, false);
				}
			}
			catch (UserException $e)
			{
				$db->rollback();
				throw new UserException('Erreur sur la ligne ' . $line . ' : ' . $e->getMessage());
			}
		}

		$db->commit();

		fclose($fp);
		return true;
	}

	protected function export(array $list = null)
	{
		$db = DB::getInstance();

		$champs = Config::getInstance()->get('champs_membres')->getKeys();
		$champs = array_map([$db, 'quoteIdentifier'], $champs);
		$fields = 'm.' . implode(', m.', $champs);

		if ($list) {
			$list = array_map('intval', $list);
			$where = sprintf('WHERE m.%s', $db->where('id', $list));
		}
		else {
			$where = '';
		}

		$sql = sprintf('SELECT %s, c.name AS "Catégorie membre" FROM membres AS m
			INNER JOIN users_categories AS c ON m.id_category = c.id
			%s ORDER BY c.id;', $fields, $where);

		$res = $db->iterate($sql);

		return [
			array_keys((array) $res->current()),
			$res,
			sprintf('Export membres - %s - %s', Config::getInstance()->get('nom_asso'), date('Y-m-d')),
		];
	}

	public function toCSV(array $list = null): void
	{
		list($champs, $result, $name) = $this->export($list);
		CSV::toCSV($name, $result, $champs, [$this, 'exportRow']);
	}

	public function toODS(array $list = null): void
	{
		list($champs, $result, $name) = $this->export($list);
		CSV::toODS($name, $result, $champs, [$this, 'exportRow']);
	}

	public function exportRow(\stdClass $row) {
		if (null === $this->champs) {
			$this->champs = Config::getInstance()->get('champs_membres')->getAll();
		}

		foreach ($this->champs as $id => $config) {
			if ($config->type == 'date') {
				$row->$id = \DateTime::createFromFormat('!Y-m-d', $row->$id);
			}
			elseif ($config->type == 'datetime') {
				$row->$id = \DateTime::createFromFormat('!Y-m-d H:i:s', $row->$id);
			}
			// convertir les champs à choix multiple de binaire vers liste séparée par des points virgules
			elseif ($config->type == 'multiple') {
				$out = [];

				foreach ($config->options as $b => $name)
				{
					if ($row->$id & (0x01 << $b)) {
						$out[] = $name;
					}
				}

				$row->$id = implode(';', $out);
			}
		}

		return $row;
	}
}