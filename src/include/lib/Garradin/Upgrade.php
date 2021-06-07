<?php

namespace Garradin;

use Garradin\Membres\Session;
use Garradin\Membres\Champs;

use Garradin\Files\Files;

class Upgrade
{
	const MIN_REQUIRED_VERSION = '0.9.8';

	static public function preCheck(): bool
	{
		$v = DB::getInstance()->version();

		if (version_compare($v, garradin_version(), '>='))
		{
			return false;
		}

		if (!$v || version_compare($v, self::MIN_REQUIRED_VERSION, '<'))
		{
			throw new UserException(sprintf("Votre version de Garradin est trop ancienne pour être mise à jour. Mettez à jour vers Garradin %s avant de faire la mise à jour vers cette version.", self::MIN_REQUIRED_VERSION));
		}

		Install::checkAndCreateDirectories();

		if (Static_Cache::exists('upgrade'))
		{
			$path = Static_Cache::getPath('upgrade');
			throw new UserException('Une mise à jour est déjà en cours.'
				. PHP_EOL . 'Si celle-ci a échouée et que vous voulez ré-essayer, supprimez le fichier suivant:'
				. PHP_EOL . $path);
		}

		// Voir si l'utilisateur est loggé, on le fait ici pour le cas où
		// il y aurait déjà eu des entêtes envoyés au navigateur plus bas
		$session = Session::getInstance();
		$session->start(true);
		$session->isLogged(true);
		return true;
	}

	static public function upgrade()
	{
		$db = DB::getInstance();
		$v = $db->version();

		Static_Cache::store('upgrade', 'Mise à jour en cours.');

		// Créer une sauvegarde automatique
		$backup_name = (new Sauvegarde)->create(false, 'pre-upgrade-' . garradin_version());

		try {
			if (version_compare($v, '1.0.0-rc1', '<'))
			{
				$db->beginSchemaUpdate();
				$db->import(ROOT . '/include/data/1.0.0_migration.sql');
				$db->commitSchemaUpdate();

				// Import nouveau plan comptable
				$chart = new \Garradin\Entities\Accounting\Chart;
				$chart->label = 'Plan comptable associatif 2018';
				$chart->country = 'FR';
				$chart->code = 'PCA2018';
				$chart->save();
				$chart->accounts()->importCSV(ROOT . '/include/data/charts/fr_2018.csv');
			}


			if (version_compare($v, '1.0.0-rc10', '<'))
			{
				$db->beginSchemaUpdate();
				$db->import(ROOT . '/include/data/1.0.0-rc10_migration.sql');
				$db->commitSchemaUpdate();
			}

			if (version_compare($v, '1.0.0-beta1', '>=') && version_compare($v, '1.0.0-rc11', '<'))
			{
				// Missing trigger
				$db->beginSchemaUpdate();
				$db->import(ROOT . '/include/data/1.0.0_schema.sql');
				$db->commitSchemaUpdate();
			}

			if (version_compare($v, '1.0.0-rc14', '<'))
			{
				// Missing trigger
				$db->beginSchemaUpdate();
				$db->import(ROOT . '/include/data/1.0.0-rc14_migration.sql');
				$db->commitSchemaUpdate();
			}

			if (version_compare($v, '1.0.0-rc16', '<'))
			{
				// Missing trigger
				$db->beginSchemaUpdate();
				$db->import(ROOT . '/include/data/1.0.0-rc16_migration.sql');
				$db->commitSchemaUpdate();
			}

			if (version_compare($v, '1.0.1', '<'))
			{
				// Missing trigger
				$db->begin();
				$db->import(ROOT . '/include/data/1.0.1_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.0.3', '<'))
			{
				// Missing trigger
				$db->begin();
				$db->import(ROOT . '/include/data/1.0.3_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.0.6', '<'))
			{
				// Missing trigger
				$db->begin();
				$db->import(ROOT . '/include/data/1.0.6_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.0.7', '<'))
			{
				// Missing trigger
				$db->begin();
				$db->import(ROOT . '/include/data/1.0.7_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.1.0-beta1', '<'))
			{
				// Missing trigger
				$db->beginSchemaUpdate();

				$attachments = $db->getAssoc('SELECT f.id, w.uri || \'/\' || f.id || \'_\' || f.nom FROM fichiers f
					INNER JOIN fichiers_wiki_pages fw ON fw.fichier = f.id
					INNER JOIN wiki_pages w ON w.id = fw.id;');

				// Update Skriv content for attachments
				foreach ($db->iterate('SELECT r.rowid, r.contenu, p.uri FROM wiki_revisions r INNER JOIN wiki_pages p ON p.revision = r.revision AND p.id = r.id_page;') as $r) {
					$uri = $r->uri;
					$content = preg_replace_callback('!<<(image|fichier)\s*\|\s*(\d+)\s*(?:\|\s*(gauche|droite|centre))?\s*(?:\|\s*(.+)\s*)?>>!', function ($match) use ($attachments, $uri) {
						if (isset($attachments[$match[2]])) {
							$name = $attachments[$match[2]];

							if (dirname($name) == $uri) {
								$name = basename($name);
							}
							else {
								$name = '../' . $name;
							}
						}
						else {
							$name = '_ERREUR_fichier_inconnu_' . $match[2];
						}

						if (isset($match[3])) {
							$align = '|' . ($match[3] == 'centre' ? 'center' : ($match[3] == 'gauche' ? 'left' : 'right'));
						}
						else {
							$align = '';
						}

						$caption = isset($match[4]) ? '|' . $match[4] : '';

						return sprintf('<<%s|%s%s%s>>', $match[1] == 'fichier' ? 'file' : 'image', $name, $align, $caption);
					}, $r->contenu);

					$content = preg_replace_callback('!(image|fichier)://(\d+)!', function ($match) use ($attachments) {
						$name = $attachments[$match[2]] ?? '_ERREUR_fichier_inconnu_' . $match[2];
						return sprintf('#file:[%s]', $name);
					}, $content);

					if ($content != $r->contenu) {
						$db->update('wiki_revisions', ['contenu' => $content], 'rowid = :id', ['id' => $r->rowid]);
					}
				}

				$champs = new Champs($db->firstColumn('SELECT valeur FROM config WHERE cle = \'champs_membres\';'));
				$db->import(ROOT . '/include/data/1.1.0_migration.sql');

				// Rename membres table
				$champs->createTable($champs::TABLE  .'_tmp');

				$fields = $champs->getCopyFields();
				unset($fields['id_category']);
				$fields['id_categorie'] = 'id_category';
				$champs->copy($champs::TABLE, $champs::TABLE . '_tmp', $fields);

				$db->exec(sprintf('DROP TABLE IF EXISTS %s;', $champs::TABLE));
				$db->exec(sprintf('ALTER TABLE %s_tmp RENAME TO %1$s;', $champs::TABLE));

				$champs->createIndexes($champs::TABLE);

				$db->commitSchemaUpdate();

				// Migrate to a different storage
				if (FILE_STORAGE_BACKEND != 'SQLite') {
					Files::migrateStorage('SQLite', FILE_STORAGE_BACKEND, null, FILE_STORAGE_CONFIG);
					Files::truncateStorage('SQLite', null);
				}

				$pages = $db->iterate('SELECT * FROM web_pages;');

				foreach ($pages as $data) {
					$page = new \Garradin\Entities\Web\Page;
					$page->exists(true);
					$page->load((array) $data);
					$page->syncSearch();
				}
			}

			if (version_compare($v, '1.1.1', '<')) {
				// Reset admin_background if the file does not exist
				$bg = $db->firstColumn('SELECT value FROM config WHERE key = \'admin_background\';');

				if ($bg) {
					$file = Files::get($bg);

					if (!$file) {
						$db->exec('UPDATE config SET value = NULL WHERE key = \'admin_background\';');
					}
				}

				// Fix links of admin homepage
				$homepage = $db->firstColumn('SELECT value FROM config WHERE key = \'admin_homepage\';');

				if ($homepage) {
					$file = Files::get($homepage);

					if ($file) {
						$content = $file->fetch();
						$new_content = preg_replace_callback(';\[\[((?!\]\]).*)\]\];', function ($match) {
							$link = explode('|', $match[1]);
							if (count($link) == 2) {
								list($label, $link) = $link;
							}
							else {
								$label = $link = $link[0];
							}

							if (strpos(trim($link), '/') !== false) {
								return $match[0];
							}

							$link = sprintf('!web/page.php?p=%s', trim($link));
							return sprintf('[[%s|%s]]', $label, $link);
						}, $content);

						if ($new_content != $content) {
							Files::disableQuota();
							$file->setContent($new_content);
						}
					}
				}
			}

			if (version_compare($v, '1.1.3', '<')) {
				// Missing trigger
				$db->begin();
				$db->import(ROOT . '/include/data/1.1.3_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.1.4', '<')) {
				// Set config file names
				$config = Config::getInstance();

				$file = Files::get(Config::DEFAULT_FILES['admin_background']);
				$config->set('admin_background', $file ? Config::DEFAULT_FILES['admin_background'] : null);

				$file = Files::get(Config::DEFAULT_FILES['admin_homepage']);
				$config->set('admin_homepage', $file ? Config::DEFAULT_FILES['admin_homepage'] : null);

				$config->save();
			}

			if (version_compare($v, '1.1.7', '<')) {
				$db->begin();
				$db->import(ROOT . '/include/data/1.1.7_migration.sql');
				$db->commit();
			}

			if (version_compare($v, '1.1.8', '<')) {
				$db->begin();
				// Force sync to remove pages that don't exist anymore
				\Garradin\Web\Web::sync();

				$uris = [];
				$i = 1;

				$treat_duplicate_uris = function ($path) use (&$i, &$uris, &$treat_duplicate_uris) {
					// Rename duplicate URIs
					foreach (Files::callStorage('list', $path) as $f) {
						if ($f->type != $f::TYPE_DIRECTORY) {
							continue;
						}

						if (array_key_exists($f->name, $uris)) {
							$f->changeFileName($f->name . '_' . $i++);
						}

						$uris[$f->name] = $f->path;

						$treat_duplicate_uris($f->path);
					}
				};

				$treat_duplicate_uris(\Garradin\Entities\Files\File::CONTEXT_WEB);

				// Force sync to add renamed pages
				\Garradin\Web\Web::sync();

				// Add UNIQUE index
				$db->import(ROOT . '/include/data/1.1.8_migration.sql');

				$db->commit();
			}

			// Vérification de la cohérence des clés étrangères
			$db->foreignKeyCheck();

			// Delete local cached files
			Utils::resetCache(USER_TEMPLATES_CACHE_ROOT);
			Utils::resetCache(STATIC_CACHE_ROOT);

			$cache_version_file = SHARED_CACHE_ROOT . '/version';
			$cache_version = file_exists($cache_version_file) ? trim(file_get_contents($cache_version_file)) : null;

			// Only delete system cache when it's required
			if (garradin_version() !== $cache_version) {
				Utils::resetCache(SMARTYER_CACHE_ROOT);
			}

			file_put_contents($cache_version_file, garradin_version());
			$db->setVersion(garradin_version());

			// reset last version check
			$db->exec('UPDATE config SET value = NULL WHERE key = \'last_version_check\';');

			Static_Cache::remove('upgrade');

			// Réinstaller les plugins système si nécessaire
			Plugin::checkAndInstallSystemPlugins();

			Plugin::upgradeAllIfRequired();
		}
		catch (\Exception $e)
		{
			$s = new Sauvegarde;
			$s->restoreFromLocal($backup_name);
			$s->remove($backup_name);
			Static_Cache::remove('upgrade');
			throw $e;
		}


		$session = Session::getInstance();
		$user_is_logged = $session->isLogged(true);

		// Forcer à rafraîchir les données de la session si elle existe
		if ($user_is_logged)
		{
			$session->refresh();
		}
	}

	/**
	 * Move data from root to data/ subdirectory
	 * (migration from 1.0 to 1.1 version)
	 */
	static public function moveDataRoot(): void
	{
		Utils::safe_mkdir(ROOT . '/data');
		file_put_contents(ROOT . '/data/index.html', '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');

		rename(ROOT . '/cache', ROOT . '/data/cache');
		rename(ROOT . '/plugins', ROOT . '/data/plugins');

		$files = glob(ROOT . '/*.sqlite');

		foreach ($files as $file) {
			rename($file, ROOT . '/data/' . basename($file));
		}
	}
}