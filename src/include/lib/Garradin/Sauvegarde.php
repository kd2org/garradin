<?php

namespace Garradin;

use Garradin\Membres\Session;
use Garradin\Files\Files;

use KD2\ZipWriter;

class Sauvegarde
{
	const NEED_UPGRADE = 0x01 << 2;
	const NOT_AN_ADMIN = 0x01 << 3;

	const INTEGRITY_FAIL = 41;
	const NOT_A_DB = 42;
	const NO_APP_ID = 43;

	/**
	 * Renvoie la liste des fichiers SQLite sauvegardés
	 * @param  boolean $auto Si true ne renvoie que la liste des sauvegardes automatiques
	 * @return array 		 Liste des fichiers
	 */
	public function getList(bool $auto_only = false): array
	{
		$ext = $auto_only ? 'auto\.\d+\.sqlite' : 'sqlite';

		$out = [];
		$dir = dir(DATA_ROOT);

		while ($file = $dir->read())
		{
			// Keep only backup files
			if ($file[0] == '.' || !is_file(DATA_ROOT . '/' . $file)
				|| !preg_match('![\w\d._-]+\.' . $ext . '$!i', $file) && $file != basename(DB_FILE)) {
				continue;
			}

			if ($file == basename(DB_FILE)) {
				continue;
			}

			$name = preg_replace('/^association\.(.*)\.sqlite$/', '$1', $file);
			$auto = null;

			if (substr($name, 0, 5) == 'auto.') {
				$auto = (int) substr($name, 5);
				$name = sprintf('Automatique n°%d', $auto);
			}
			elseif (0 === strpos($name, 'pre-upgrade-')) {
				$name = sprintf('Avant mise à jour %s', substr($name, strlen('pre-upgrade-')));
			}
			elseif (preg_match('/^\d{4}-/', $name)) {
				$name = 'Sauvegarde manuelle';
			}
			else {
				$name = str_replace('.sqlite', '', $file);
			}

			// Skip non-auto files
			if ($auto_only && !$auto) {
				continue;
			}

			$db = new \SQLite3(DATA_ROOT . '/' . $file, \SQLITE3_OPEN_READONLY);
			$version = DB::getVersion($db);
			$db->close();

			$out[$file] = (object) [
				'filename'    => $file,
				'date'        => filemtime(DATA_ROOT . '/' . $file),
				'name'        => $name != $file ? $name : null,
				'version'     => $version,
				'can_restore' => version_compare($version, Upgrade::MIN_REQUIRED_VERSION, '>='),
				'auto'        => $auto,
				'size'        => filesize(DATA_ROOT . '/' . $file),
			];
		}

		$dir->close();

		// Reverse date order
		uasort($out, function ($a, $b) {
			return $a->date > $b->date ? -1 : 1;
		});

		return $out;
	}

	/**
	 * Crée une nouvelle sauvegarde
	 * @param  boolean $auto Si true le nom de fichier sera celui de la sauvegarde automatique courante,
	 * sinon le nom sera basé sur la date (sauvegarde manuelle)
	 * @return string Le nom de fichier de la sauvegarde ainsi créée
	 */
	public function create(bool $auto = false, ?string $name = null): string
	{
		$suffix = $name ?? ($auto ? 'auto.1' : date('Y-m-d-His'));

		$backup = str_replace('.sqlite', sprintf('.%s.sqlite', $suffix), DB_FILE);

		$this->make($backup);

		return basename($backup);
	}

	protected function make(string $dest)
	{
		// Acquire lock
		$version = \SQLite3::version();
		$db = DB::getInstance();

		Utils::safe_unlink($dest);

		if ($version['versionNumber'] >= 3027000) {
			// use VACUUM INTO instead when SQLite 3.27+ is required
			$db->exec(sprintf('VACUUM INTO %s;', $db->quote($dest)));
		}
		else {
			// use ::backup since PHP 7.4.0+
			// https://www.php.net/manual/en/sqlite3.backup.php
			$dest_db = new \SQLite3($dest);

			$db->backup($dest_db);
			$dest_db->exec('PRAGMA journal_mode = DELETE;');
			$dest_db->exec('VACUUM;');
			$db->close();
		}
	}

	/**
	 * Effectue une rotation des sauvegardes automatiques
	 * association.auto.1.sqlite deviendra association.auto.2.sqlite par exemple
	 */
	public function rotate(): void
	{
		$config = Config::getInstance();
		$nb = $config->get('nombre_sauvegardes');

		$list = $this->getList(true);

		// Sort backups from oldest to newest
		usort($list, function ($a, $b) {
			return $a->auto > $b->auto ? -1 : 1;
		});

		// Delete oldest backups + 1 as we are about to create a new one
		$delete = count($list) - ($nb - 1);

		for ($i = 0; $i < $delete; $i++) {
			$backup = array_shift($list);
			$this->remove($backup->filename);
		}

		$i = count($list) + 1;

		// Rotate old backups
		foreach ($list as $file) {
			$old = DATA_ROOT . DIRECTORY_SEPARATOR . $file->filename;
			$new = sprintf('%s/association.auto.%d.sqlite', DATA_ROOT, $i--);

			if ($old !== $new) {
				rename($old, $new);
			}
		}
	}

	/**
	 * Crée une sauvegarde automatique si besoin est
	 * @return boolean true
	 */
	public function auto()
	{
		$config = Config::getInstance();

		// Pas besoin d'aller plus loin si on ne fait pas de sauvegarde auto
		if ($config->get('frequence_sauvegardes') == 0 || $config->get('nombre_sauvegardes') == 0)
			return true;

		$list = $this->getList(true);

		if (count($list))
		{
			$last = current($list)->date;
		}
		else
		{
			$last = false;
		}

		// Test de la date de création de la dernière sauvegarde
		if ($last >= (time() - ($config->get('frequence_sauvegardes') * 3600 * 24)))
		{
			return true;
		}

		// Si pas de modif depuis la dernière sauvegarde, ça sert à rien d'en faire
		if ($last >= filemtime(DB_FILE))
		{
			return true;
		}

		$this->rotate();
		$this->create(true);

		return true;
	}

	/**
	 * Efface une sauvegarde locale
	 * @param  string $file Nom du fichier à supprimer
	 * @return boolean		true si le fichier a bien été supprimé, false sinon
	 */
	public function remove($file)
	{
		if (preg_match('!\.\.+!', $file) || !preg_match('!^[\w\d._-]+\.sqlite$!i', $file) 
			|| $file == basename(DB_FILE))
		{
			throw new UserException('Nom de fichier non valide.');
		}

		return Utils::safe_unlink(DATA_ROOT . '/' . $file);
	}

	/**
	 * Renvoie sur la sortie courante le contenu du fichier de base de données sélectionné ou courant
	 */
	public function dump(?string $file = null): void
	{
		$config = Config::getInstance();
		$tmp_file = null;

		if (null === $file) {
			$file = DB_FILE;
			$name = sprintf('%s - Sauvegarde données - %s.sqlite', $config->get('nom_asso'), date('Y-m-d'));

			$tmp_file = tempnam(sys_get_temp_dir(), 'gdin');
			$this->make($tmp_file);

			$file = $tmp_file;
		}
		else {
			if (preg_match('!\.\.+!', $file) || !preg_match('!^[\w\d._ -]+$!iu', $file)) {
				throw new UserException('Nom de fichier non valide.');
			}

			$name = sprintf('%s - %s', $config->get('nom_asso'), str_replace('association.', '', $file));
			$file = DATA_ROOT . '/' . $file;

			if (!file_exists($file)) {
				throw new UserException('Le fichier fourni n\'existe pas.');
			}
		}

		$hash_length = strlen(sha1(''));

		header('Content-type: application/octet-stream');
		header(sprintf('Content-Disposition: attachment; filename="%s"', $name));
		header(sprintf('Content-Length: %d', filesize($file) + $hash_length));

		readfile($file);

		// Add integrity hash
		echo sha1_file($file);

		if (null !== $tmp_file) {
			@unlink($tmp_file);
		}
	}

	public function dumpFilesZip(): void
	{
		$name = Config::getInstance()->get('nom_asso') . ' - Documents.zip';
		header('Content-type: application/zip');
		header(sprintf('Content-Disposition: attachment; filename="%s"', $name));

		$zip = new ZipWriter('php://output');
		$zip->setCompression(0);

		$add_directory = function ($path) use ($zip, &$add_directory) {
			foreach (Files::list($path) as $file) {
				if ($file->type == $file::TYPE_DIRECTORY) {
					$add_directory($file->path);
				}
				else {
					$zip->add($file->path, null, $file->fullpath());
				}
			}
		};

		$add_directory('');
		$zip->close();
	}

	/**
	 * Restaure une sauvegarde locale
	 * @param  string $file Le nom de fichier à utiliser comme point de restauration
	 * @return boolean true si la restauration a fonctionné, false sinon
	 */
	public function restoreFromLocal(string $file)
	{
		if (preg_match('!\.\.+!', $file) || !preg_match('!^[\w\d._ -]+$!iu', $file))
		{
			throw new UserException('Nom de fichier non valide.');
		}

		if (!file_exists(DATA_ROOT . '/' . $file))
		{
			throw new UserException('Le fichier fourni n\'existe pas.');
		}

		return $this->restoreDB(DATA_ROOT . '/' . $file, false, false);
	}

	/**
	 * Restaure une copie distante (fichier envoyé)
	 * @param  array   $file    Tableau provenant de $_FILES
	 * @param  integer $user_id ID du membre actuellement connecté, utilisé pour 
	 * vérifier qu'il est toujours administrateur dans la sauvegarde
	 * @param  boolean $check_integrity Vérifier l'intégrité de la sauvegarde avant de restaurer
	 * @return boolean true
	 */
	public function restoreFromUpload($file, $user_id, $check_integrity = true)
	{
		if (empty($file['size']) || empty($file['tmp_name']) || !empty($file['error']))
		{
			throw new UserException('Le fichier n\'a pas été correctement envoyé. Essayer de le renvoyer à nouveau.');
		}

		if ($check_integrity)
		{
			$integrity = $this->checkIntegrity($file['tmp_name']);

			if ($integrity === null)
			{
				throw new UserException('Le fichier fourni n\'est pas une base de donnée SQLite3.', self::NOT_A_DB);
			}
			elseif ($integrity === false)
			{
				throw new UserException('Le fichier fourni a été modifié par un programme externe.', self::INTEGRITY_FAIL);
			}
		}

		$r = $this->restoreDB($file['tmp_name'], $user_id, true);

		if ($r)
		{
			Utils::safe_unlink($file['tmp_name']);
		}

		return $r;
	}

	/**
	 * Vérifie l'intégrité d'une sauvegarde Garradin
	 * @param  string $file_path Chemin absolu vers la base de donnée
	 * @return boolean|null
	 */
	protected function checkIntegrity($file_path, $remove_hash = true)
	{
		$size = filesize($file_path);
		$fp = fopen($file_path, 'r+');

		$header = fread($fp, 16);

		// Vérifie que le fichier est bien une base SQLite3
		if ($header !== "SQLite format 3\000")
		{
			fclose($fp);
			return null;
		}

		fseek($fp, -40, SEEK_END);

		$hash = fread($fp, 40);

		// Ne ressemble pas à un hash sha1
		if (!preg_match('/[a-f0-9]{40}/', $hash))
		{
			fclose($fp);
			return false;
		}

		$max = $size - 40;

		// Suppression du hash
		if ($remove_hash)
		{
			ftruncate($fp, $max);
		}

		fclose($fp);

		$file_hash = sha1_file($file_path);

		// Vérification du hash
		return ($file_hash === $hash);
	}

	/**
	 * Restauration de base de données, la fonction qui le fait vraiment
	 * @param  string $file Chemin absolu vers la base de données à utiliser
	 * @return mixed 		true si rien ne va plus, ou self::NEED_UPGRADE si la version de la DB
	 * ne correspond pas à la version de Garradin (mise à jour nécessaire).
	 */
	protected function restoreDB($file, $user_id = false, $check_foreign_keys = false)
	{
		$return = 1;

		// Essayons déjà d'ouvrir la base de données à restaurer en lecture
		try {
			$db = new \SQLite3($file, \SQLITE3_OPEN_READONLY);
		}
		catch (\Exception $e)
		{
			throw new UserException('Le fichier fourni n\'est pas une base de données valide. ' .
				'Message d\'erreur de SQLite : ' . $e->getMessage(), self::NOT_A_DB);
		}

		DB::registerCustomFunctions($db);

		try {
			// Regardons ensuite si la base de données n'est pas corrompue
			$check = $db->querySingle('PRAGMA integrity_check;', false);
		}
		catch (\Exception $e)
		{
			// Ici SQLite peut rejeter un message type "file is encrypted or is not a db"
			throw new UserException('Le fichier fourni n\'est pas une base de données valide. ' .
				'Message d\'erreur de SQLite : ' . $e->getMessage(), self::NOT_A_DB);
		}

		if (strtolower(trim($check)) != 'ok')
		{
			throw new UserException('Le fichier fourni est corrompu. Erreur SQLite : ' . $check);
		}

		if ($check_foreign_keys)
		{
			$check = $db->querySingle('PRAGMA foreign_key_check;');

			if ($check)
			{
				throw new UserException('Le fichier fourni est corrompu. Certaines clés étrangères référencent des lignes qui n\'existent pas.');
			}
		}

		// On ne peut pas faire de vérifications très poussées sur la structure de la base de données,
		// celle-ci pouvant changer d'une version à l'autre et on peut vouloir importer une base
		// un peu vieille, mais on vérifie quand même que ça ressemble un minimum à une base garradin
		$table = $db->querySingle('SELECT 1 FROM sqlite_master WHERE type=\'table\' AND tbl_name=\'config\';');

		if (!$table)
		{
			throw new UserException('Le fichier fourni ne semble pas contenir de données liées à Garradin.');
		}

		// On récupère la version
		$version = DB::getVersion($db);

		// On ne permet pas de restaurer une vieille version
		if (version_compare($version, Upgrade::MIN_REQUIRED_VERSION, '<'))
		{
			throw new UserException(sprintf('Ce fichier a été créé avec une version trop ancienne (%s), il n\'est pas possible de le restaurer.', $version));
		}

		// Vérification de l'AppID
		$appid = $db->querySingle('PRAGMA application_id;', false);

		if ($appid !== DB::APPID)
		{
			throw new UserException('Ce fichier n\'est pas une sauvegarde Garradin (application_id ne correspond pas).', self::NO_APP_ID);
		}

		// Empêchons l'admin de se tirer une balle dans le pied
		if ($user_id)
		{
			if (version_compare($version, '1.1', '<')) {
				$sql = 'SELECT 1 FROM membres_categories WHERE id = (SELECT id_categorie FROM membres WHERE id = %d) AND droit_connexion >= %d AND droit_config >= %d';
			}
			else {
				$sql = 'SELECT 1 FROM users_categories WHERE id = (SELECT id_category FROM membres WHERE id = %d) AND perm_connect >= %d AND perm_config >= %d';
			}

			$sql = sprintf($sql, $user_id, Session::ACCESS_READ, Session::ACCESS_ADMIN);
			$is_still_admin = $db->querySingle($sql);

			if (!$is_still_admin)
			{
				$return |= self::NOT_AN_ADMIN;
			}
		}


		$db->close();

		$backup = str_replace('.sqlite', date('.Y-m-d-His') . '.avant_restauration.sqlite', DB_FILE);

		DB::getInstance()->close();

		if (!rename(DB_FILE, $backup))
		{
			throw new \RuntimeException('Unable to backup current DB file.');
		}

		if (!copy($file, DB_FILE))
		{
			rename($backup, DB_FILE);
			throw new \RuntimeException('Unable to copy backup DB to main location.');
		}

		unlink($backup);

		if ($return & self::NOT_AN_ADMIN)
		{
			// Forcer toutes les catégories à pouvoir gérer les droits
			$db = DB::getInstance();
			$db->update('users_categories', [
				'perm_users' => Session::ACCESS_ADMIN,
				'perm_connect' => Session::ACCESS_READ
			]);
		}

		if ($version != garradin_version())
		{
			$return |= self::NEED_UPGRADE;
		}
		else {
			// Force l'installation de plugin système si non existant dans la sauvegarde existante
			Plugin::checkAndInstallSystemPlugins();

			// Check and upgrade plugins, if a software upgrade is necessary, plugins will be upgraded after the upgrade
			Plugin::upgradeAllIfRequired();
		}

		return $return;
	}

	/**
	 * Taille de la base de données actuelle
	 * @return integer Taille en octets du fichier SQLite
	 */
	public function getDBSize($signed = false)
	{
		return filesize(DB_FILE) + ($signed ? 40 : 0);
	}

	/**
	 * Taille occupée par les fichiers dans la base de données
	 * @return integer Taille en octets
	 */
	public function getDBFilesSize()
	{
		$db = DB::getInstance();
		return (int) $db->firstColumn('SELECT SUM(size) FROM files;');
	}
}