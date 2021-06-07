<?php

namespace Garradin\Files;

use Garradin\Static_Cache;
use Garradin\DB;
use Garradin\Utils;
use Garradin\ValidationException;
use Garradin\Membres\Session;
use Garradin\Entities\Files\File;
use Garradin\Entities\Web\Page;

use KD2\DB\EntityManager as EM;
use KD2\ZipWriter;

use const Garradin\{FILE_STORAGE_BACKEND, FILE_STORAGE_QUOTA, FILE_STORAGE_CONFIG};

class Files
{
	/**
	 * To enable or disable quota check
	 */
	static protected $quota = true;

	static public function search(string $search, string $path = null): array
	{
		if (strlen($search) > 100) {
			throw new ValidationException('Recherche trop longue : maximum 100 caractères');
		}

		$where = '';
		$params = [trim($search)];

		if (null !== $path) {
			$where = ' AND path LIKE ?';
			$params[] = $path;
		}

		$query = sprintf('SELECT
			*,
			dirname(path) AS parent,
			snippet(files_search, \'<b>\', \'</b>\', \'…\', 2) AS snippet,
			rank(matchinfo(files_search), 0, 1.0, 1.0) AS points
			FROM files_search
			WHERE files_search MATCH ? %s
			ORDER BY points DESC
			LIMIT 0,50;', $where);

		return DB::getInstance()->get($query, ...$params);
	}

	static public function list(string $parent = ''): array
	{
		if ($parent !== '') {
			File::validatePath($parent);
		}

		// Update this path
		return self::callStorage('list', $parent);
	}

	static public function zip(string $parent, ?Session $session)
	{
		$file = Files::get($parent);

		if (!$file) {
			throw new UserException('Ce répertoire n\'existe pas.');
		}

		if ($session && !$file->checkReadAccess($session)) {
			throw new UserException('Vous n\'avez pas accès à ce répertoire');
		}

		$zip = new ZipWriter('php://output');
		$zip->setCompression(0);

		$add_file = function ($subpath) use ($zip, $parent, &$add_file) {
			foreach (self::list($subpath) as $file) {
				if ($file->type == $file::TYPE_DIRECTORY) {
					$add_file($file->path);
					continue;
				}

				$dest_path = substr($file->path, strlen($parent . '/'));
				$zip->add($dest_path, null, $file->fullpath());
			}
		};

		$add_file($parent);

		$zip->close();
	}

	static public function listForContext(string $context, ?string $ref = null)
	{
		$path = $context;

		if ($ref) {
			$path .= '/' . $ref;
		}

		return self::list($path);
	}

	static public function delete(string $path): void
	{
		$file = self::get($path);

		if (!$file) {
			return;
		}

		$file->delete();
	}

	static public function callStorage(string $function, ...$args)
	{
		$class_name = __NAMESPACE__ . '\\Storage\\' . FILE_STORAGE_BACKEND;

		call_user_func([$class_name, 'configure'], FILE_STORAGE_CONFIG);

		return call_user_func_array([$class_name, $function], $args);
	}

	/**
	 * Copy all files from a storage backend to another one
	 * This can be used to move from SQLite to FileSystem for example
	 * Note that this only copies files, and is not removing them from the source storage backend.
	 */
	static public function migrateStorage(string $from, string $to, $from_config = null, $to_config = null, ?callable $callback = null): void
	{
		$from = __NAMESPACE__ . '\\Storage\\' . $from;
		$to = __NAMESPACE__ . '\\Storage\\' . $to;

		if (!class_exists($from)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $from);
		}

		if (!class_exists($to)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $to);
		}

		call_user_func([$from, 'configure'], $from_config);
		call_user_func([$to, 'configure'], $to_config);

		try {
			call_user_func([$from, 'checkLock']);
			call_user_func([$to, 'checkLock']);

			call_user_func([$from, 'lock']);
			call_user_func([$to, 'lock']);

			$db = DB::getInstance();
			$db->begin();
			$i = 0;

			self::migrateDirectory($from, $to, '', $i, $callback);

			$db->commit();
		}
		finally {
			call_user_func([$from, 'unlock']);
			call_user_func([$to, 'unlock']);
		}
	}

	static protected function migrateDirectory(string $from, string $to, string $path, int &$i, ?callable $callback)
	{
		$db = DB::getInstance();

		foreach (call_user_func([$from, 'list'], $path) as $file) {
			if (++$i >= 100) {
				$db->commit();
				$db->begin();
				$i = 0;
			}

			if ($file->type == File::TYPE_DIRECTORY) {
				call_user_func([$to, 'mkdir'], $file);
				self::migrateDirectory($from, $to, $file->path, $i, $callback);
			}
			else {
				$from_path = call_user_func([$from, 'getFullPath'], $file);
				call_user_func([$to, 'storePath'], $file, $from_path);
			}

			if (null !== $callback) {
				$callback($file);
			}

			unset($file);
		}
	}

	/**
	 * Delete all files from a storage backend
	 */
	static public function truncateStorage(string $backend, $config = null): void
	{
		$backend = __NAMESPACE__ . '\\Storage\\' . $backend;

		call_user_func([$backend, 'configure'], $config);

		if (!class_exists($backend)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $backend);
		}

		call_user_func([$backend, 'truncate']);
	}

	static public function get(string $path, int $type = null): ?File
	{
		try {
			File::validatePath($path);
		}
		catch (ValidationException $e) {
			return null;
		}

		$file = self::callStorage('get', $path);

		if (!$file || ($type && $file->type != $type)) {
			return null;
		}

		return $file;
	}

	static public function getFromURI(string $uri): ?File
	{
		$uri = trim($uri, '/');
		$uri = rawurldecode($uri);

		return self::get($uri, File::TYPE_FILE);
	}

	static public function getContext(string $path): ?string
	{
		$context = strtok($path, '/');

		if (!array_key_exists($context, File::CONTEXTS_NAMES)) {
			return null;
		}

		return $context;
	}

	static public function getContextRef(string $path): ?string
	{
		$context = strtok($path, '/');
		return strtok('/') ?: null;
	}

	static public function getBreadcrumbs(string $path): array
	{
		$parts = explode('/', $path);
		$breadcrumbs = [];
		$path = '';

		foreach ($parts as $part) {
			$path = trim($path . '/' . $part, '/');
			$breadcrumbs[$path] = $part;
		}

		return $breadcrumbs;
	}

	static public function getQuota(): float
	{
		return FILE_STORAGE_QUOTA ?? self::callStorage('getQuota');
	}

	static public function getUsedQuota(bool $force_refresh = false): float
	{
		if ($force_refresh || Static_Cache::expired('used_quota', 3600)) {
			$quota = self::callStorage('getTotalSize');
			Static_Cache::store('used_quota', $quota);
		}
		else {
			$quota = (float) Static_Cache::get('used_quota');
		}

		return $quota;
	}

	static public function getRemainingQuota(bool $force_refresh = false): float
	{
		if (FILE_STORAGE_QUOTA !== null) {
			return FILE_STORAGE_QUOTA - self::getUsedQuota($force_refresh);
		}

		return self::callStorage('getRemainingQuota');
	}

	static public function checkQuota(int $size = 0): void
	{
		if (!self::$quota) {
			return;
		}

		$remaining = self::getRemainingQuota(true);

		if (($remaining - (float) $size) < 0) {
			throw new ValidationException('L\'espace disque est insuffisant pour réaliser cette opération');
		}
	}

	static public function enableQuota(): void
	{
		self::$quota = true;
	}

	static public function disableQuota(): void
	{
		self::$quota = false;
	}

	static public function getVirtualTableName(): string
	{
		if (FILE_STORAGE_BACKEND == 'SQLite') {
			return 'files';
		}

		return 'tmp_files';
	}

	static public function syncVirtualTable(string $parent = '')
	{
		if (FILE_STORAGE_BACKEND == 'SQLite') {
			// No need to create a virtual table, use the real one
			return;
		}

		$db = DB::getInstance();
		$db->begin();
		$db->exec('CREATE TEMP TABLE IF NOT EXISTS tmp_files AS SELECT * FROM files WHERE 0;');

		foreach (Files::list($parent) as $file) {
			$db->insert('tmp_files', $file->asArray(true));
		}

		$db->commit();
	}
}
