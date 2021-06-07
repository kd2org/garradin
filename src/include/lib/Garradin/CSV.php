<?php

namespace Garradin;

use KD2\Office\Calc\Writer as ODSWriter;

class CSV
{
	static public function readAsArray(string $path)
	{
		if (!file_exists($path) || !is_readable($path))
		{
			throw new \RuntimeException('Fichier inconnu : '.$path);
		}

		$fp = self::open($path);

		if (!$fp)
		{
			return false;
		}

		$delim = self::findDelimiter($fp);
		self::skipBOM($fp);

		$line = 0;
		$out = [];
		$nb_columns = null;

		while (!feof($fp))
		{
			$row = fgetcsv($fp, 4096, $delim);
			$line++;

			if (empty($row))
			{
				continue;
			}

			if (null === $nb_columns)
			{
				$nb_columns = count($row);
			}

			if (count($row) != $nb_columns)
			{
				throw new UserException('Erreur sur la ligne ' . $line . ' : incohérence dans le nombre de colonnes avec la première ligne.');
			}

			$out[$line] = $row;
		}

		fclose($fp);

		return $out;
	}

	static public function open(string $file)
	{
		ini_set('auto_detect_line_endings', true);
		return fopen($file, 'r');
	}

	static public function findDelimiter(&$fp)
	{
		$line = '';

		while ($line === '' && !feof($fp))
		{
			$line = fgets($fp, 4096);
		}

		if (strlen($line) >= 4095) {
			throw new UserException('Fichier CSV illisible : la première ligne est trop longue.');
		}

		// Delete the columns content
		$line = preg_replace('/".*?"/', '', $line);

		$delims = [
			';' => substr_count($line, ';'),
			',' => substr_count($line, ','),
			"\t"=> substr_count($line, "\t")
		];

		arsort($delims);
		reset($delims);

		rewind($fp);

		return key($delims);
	}

	static public function skipBOM(&$fp)
	{
		// Skip BOM
		if (fgets($fp, 4) !== chr(0xEF) . chr(0xBB) . chr(0xBF))
		{
			fseek($fp, 0);
		}
	}

	static public function row($row): string
	{
		$row = (array) $row;

		array_walk($row, function (&$field) {
			$field = strtr($field, ['"' => '""', "\r\n" => "\n"]);
		});

		return sprintf("\"%s\"\r\n", implode('","', $row));
	}

	static public function export(string $format, string $name, iterable $iterator, ?array $header = null, ?callable $row_map_callback = null): void
	{
		if ('csv' == $format) {
			self::toCSV(... array_slice(func_get_args(), 1));
		}
		else {
			self::toODS(... array_slice(func_get_args(), 1));
		}
	}

	static protected function rowToArray($row, ?callable $row_map_callback)
	{
		if (null !== $row_map_callback) {
			call_user_func_array($row_map_callback, [&$row]);
		}

		if (is_object($row) && $row instanceof Entity) {
			$row = $row->asArray();
		}
		elseif (is_object($row)) {
			$row = (array) $row;
		}

		foreach ($row as $key => &$v) {
			if ((is_object($v) && !($v instanceof \DateTimeInterface)) || is_array($v)) {
				throw new \UnexpectedValueException(sprintf('Unexpected value for "%s": %s', $key, gettype($v)));
			}
		}

		return $row;
	}

	static public function toCSV(string $name, iterable $iterator, ?array $header = null, ?callable $row_map_callback = null): void
	{
		header('Content-type: application/csv');
		header(sprintf('Content-Disposition: attachment; filename="%s.csv"', $name));

		$fp = fopen('php://output', 'w');

		if ($header) {
			fputs($fp, self::row($header));
		}

		if (!($iterator instanceof \Iterator) || $iterator->valid()) {
			foreach ($iterator as $row) {
				$row = self::rowToArray($row, $row_map_callback);

				foreach ($row as $key => &$v) {
					if (is_object($v) && $v instanceof \DateTimeInterface) {
						if ($v->format('His') == '000000') {
							$v = $v->format('d/m/Y');
						}
						else {
							$v = $v->format('d/m/Y H:i:s');
						}
					}
				}

				if (!$header)
				{
					fputs($fp, self::row(array_keys($row)));
					$header = true;
				}

				fputs($fp, self::row($row));
			}
		}

		fclose($fp);
	}

	static public function toODS(string $name, iterable $iterator, ?array $header = null, ?callable $row_map_callback = null): void
	{
		header('Content-type: application/vnd.oasis.opendocument.spreadsheet');
		header(sprintf('Content-Disposition: attachment; filename="%s.ods"', $name));

		$ods = new ODSWriter;
		$ods->table_name = $name;

		if ($header) {
			$ods->add((array) $header);
		}

		if (!($iterator instanceof \Iterator) || $iterator->valid()) {
			foreach ($iterator as $row) {
				$row = self::rowToArray($row, $row_map_callback);

				if (!$header)
				{
					$ods->add(array_keys($row));
					$header = true;
				}

				$ods->add((array) $row);
			}
		}

		$ods->output();
	}

	static public function importUpload(array $file, array $expected_columns): \Generator
	{
		if (empty($file['size']) || empty($file['tmp_name'])) {
			throw new UserException('Fichier invalide');
		}

		return self::import($file['tmp_name'], $expected_columns);
	}

	static public function import(string $file, array $expected_columns): \Generator
	{
		$fp = fopen($file, 'r');

		if (!$fp) {
			throw new UserException('Le fichier ne peut être ouvert');
		}

		// Find the delimiter
		$delim = self::findDelimiter($fp);
		self::skipBOM($fp);

		$line = 0;

		$columns = fgetcsv($fp, 4096, $delim);
		$columns = array_map('trim', $columns);

		// Check for required columns
		foreach ($expected_columns as $column) {
			if (!in_array($column, $columns, true)) {
				throw new UserException(sprintf('La colonne "%s" est absente du fichier importé', $column));
			}
		}

		while (!feof($fp))
		{
			$row = fgetcsv($fp, 4096, $delim);
			$line++;

			// Empty line, skip
			if (empty($row)) {
				continue;
			}

			if (count($row) != count($columns))
			{
				throw new UserException('Erreur sur la ligne ' . $line . ' : le nombre de colonnes est incorrect.');
			}

			$row = array_combine($columns, $row);

			yield $line => $row;
		}

		fclose($fp);
	}
}