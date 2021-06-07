<?php

namespace Garradin\Files\Storage;

use Garradin\Entities\Files\File;
use Garradin\Files\Files;

use Garradin\Static_Cache;
use Garradin\DB;
use Garradin\Utils;

use KD2\DB\EntityManager as EM;

use const Garradin\{DB_FILE, DATA_ROOT};

class SQLite implements StorageInterface
{
	static public function configure(?string $config): void
	{
	}

	/**
	 * Renvoie le chemin vers le fichier local en cache, et le crée s'il n'existe pas
	 * @return string Chemin local
	 */
	static protected function _getFilePathFromCache(File $file): string
	{
		$cache_id = 'files.' . $file->pathHash();

		if (!Static_Cache::exists($cache_id))
		{
			$db = DB::getInstance();

			try {
				$blob = $db->openBlob('files_contents', 'content', $file->id());
			}
			catch (\Exception $e) {
				if (!strstr($e->getMessage(), 'no such rowid')) {
					throw $e;
				}

				throw new \RuntimeException('File does not exist in DB: ' . $file->path);
			}

			Static_Cache::storeFromPointer($cache_id, $blob);
			fclose($blob);
		}

		return Static_Cache::getPath($cache_id);
	}

	static public function storePath(File $file, string $source_path): bool
	{
		return self::store($file, $source_path, null);
	}

	static public function storeContent(File $file, string $source_content): bool
	{
		return self::store($file, null, $source_content);
	}

	static protected function store(File $file, ?string $source_path, ?string $source_content): bool
	{
		if (!isset($source_path) && !isset($source_content)) {
			throw new \InvalidArgumentException('Either source_path or source_content must be supplied');
		}

		$db = DB::getInstance();

		$file->size = $source_content !== null ? strlen($source_content) : filesize($source_path);

		$file->save();

		$id = $file->id();

		$db->preparedQuery('INSERT OR REPLACE INTO files_contents (id, content) VALUES (?, zeroblob(?));',
			$id, $file->size);

		$blob = $db->openBlob('files_contents', 'content', $id, 'main', \SQLITE3_OPEN_READWRITE);

		if (null !== $source_content) {
			fwrite($blob, $source_content);
		}
		else {
			$in = fopen($source_path, 'r');
			stream_copy_to_stream($in, $blob);
			fclose($in);
		}

		fclose($blob);

		$cache_id = 'files.' . $file->pathHash();
		Static_Cache::remove($cache_id);

		if ($file->parent) {
			self::touch($file->parent);
		}

		return true;
	}

	static public function getFullPath(File $file): ?string
	{
		return self::_getFilePathFromCache($file);
	}

	static public function display(File $file): void
	{
		readfile(self::getFullPath($file));
	}

	static public function fetch(File $file): string
	{
		return file_get_contents(self::getFullPath($file));
	}

	static public function get(string $path): ?File
	{
		$sql = 'SELECT * FROM @TABLE WHERE path = ? LIMIT 1;';
		return EM::findOne(File::class, $sql, $path);
	}

	static public function list(string $path): array
	{
		return EM::getInstance(File::class)->all('SELECT * FROM @TABLE WHERE parent = ? ORDER BY type DESC, name COLLATE NOCASE ASC;', $path);
	}

	static public function listDirectoriesRecursively(string $path): array
	{
		$files = [];
		$it = DB::getInstance()->iterate('SELECT path FROM files WHERE parent LIKE ? ORDER BY path;', $path . '/%');

		foreach ($it as $file) {
			$files[] = $file->path;
		}

		return $files;
	}

	static public function exists(string $path): bool
	{
		return DB::getInstance()->test('files', 'path = ?', $path);
	}

	static public function delete(File $file): bool
	{
		$db = DB::getInstance();

		$cache_id = 'files.' . $file->pathHash();
		Static_Cache::remove($cache_id);

		$db->delete('files_contents', 'id = ?', $file->id());

		// Delete recursively
		if ($file->type == File::TYPE_DIRECTORY) {
			foreach (Files::list($file->path) as $subfile) {
				$subfile->delete();
			}
		}

		if ($file->parent) {
			self::touch($file->parent);
		}

		return true;
	}

	static public function move(File $file, string $new_path): bool
	{
		$current_path = $file->path;
		$file->set('path', $new_path);
		$file->set('parent', Utils::dirname($new_path));
		$file->set('name', Utils::basename($new_path));
		$file->save();

		if ($file->type == File::TYPE_DIRECTORY) {
			// Move sub-directories and sub-files
			DB::getInstance()->preparedQuery('UPDATE files SET parent = ?, path = TRIM(? || \'/\' || name, \'/\') WHERE parent = ?;', $new_path, $new_path, $current_path);
		}

		if ($file->parent) {
			self::touch($file->parent);
		}

		return true;
	}

	static public function touch(string $path): bool
	{
		return DB::getInstance()->preparedQuery('UPDATE files SET modified = ? WHERE path = ?;', new \DateTime, $path);
	}

	static public function mkdir(File $file): bool
	{
		$file->save();

		if ($file->parent) {
			self::touch($file->parent);
		}

		return true;
	}

	static public function getTotalSize(): float
	{
		return (float) DB::getInstance()->firstColumn('SELECT SUM(size) FROM files;');
	}

	/**
	 * @see https://www.crazyws.fr/dev/fonctions-php/fonction-disk-free-space-et-disk-total-space-pour-ovh-2JMH9.html
	 * @see https://github.com/jdel/sspks/commit/a890e347f32e9e3e50a0dd82398947633872bf38
	 */
	static public function getQuota(): float
	{
		$quota = @disk_total_space(DATA_ROOT);
		return $quota === false ? (float) \PHP_INT_MAX : (float) $quota;
	}

	static public function getRemainingQuota(): float
	{
		$quota = @disk_free_space(DATA_ROOT);
		return $quota === false ? (float) \PHP_INT_MAX : (float) $quota;
	}

	static public function truncate(): void
	{
		$db = DB::getInstance();
		$db->exec('DELETE FROM files_contents; DELETE FROM files; VACUUM;');
	}

	static public function lock(): void
	{
		DB::getInstance()->exec('INSERT INTO files (name, path, parent, type) VALUES (\'.lock\', \'.lock\', \'\', 2);');
	}

	static public function unlock(): void
	{
		DB::getInstance()->exec('DELETE FROM files WHERE path = \'.lock\';');
	}

	static public function checkLock(): void
	{
		$lock = DB::getInstance()->firstColumn('SELECT 1 FROM files WHERE path = \'.lock\';');

		if ($lock) {
			throw new \RuntimeException('File storage is locked');
		}
	}
}
