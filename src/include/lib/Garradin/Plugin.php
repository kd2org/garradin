<?php

namespace Garradin;

use Garradin\Membres\Session;

class Plugin
{
	const PLUGIN_ID_SYNTAX = '[a-z]+(?:_[a-z]+)*';

	protected $id = null;
	protected $plugin = null;
	protected $config_changed = false;

	protected $mimes = [
		'css' => 'text/css',
		'gif' => 'image/gif',
		'htm' => 'text/html',
		'html' => 'text/html',
		'ico' => 'image/x-ico',
		'jpe' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'js' => 'application/x-javascript',
		'pdf' => 'application/pdf',
		'png' => 'image/png',
		'swf' => 'application/shockwave-flash',
		'xml' => 'text/xml',
		'svg' => 'image/svg+xml',
	];

	static public function getPath($id, $fail_with_exception = true)
	{
		if (file_exists(PLUGINS_ROOT . '/' . $id . '.tar.gz'))
		{
			return 'phar://' . PLUGINS_ROOT . '/' . $id . '.tar.gz';
		}
		elseif (is_dir(PLUGINS_ROOT . '/' . $id))
		{
			return PLUGINS_ROOT . '/' . $id;
		}

		if ($fail_with_exception)
		{
			throw new \LogicException(sprintf('Le plugin "%s" n\'existe pas dans le répertoire des plugins.', $id));
		}

		return false;
	}

	/**
	 * Construire un objet Plugin pour un plugin
	 * @param string $id Identifiant du plugin
	 * @throws UserException Si le plugin n'est pas installé (n'existe pas en DB)
	 */
	public function __construct($id)
	{
		$db = DB::getInstance();
		$this->plugin = $db->first('SELECT * FROM plugins WHERE id = ?;', $id);

		if (!$this->plugin)
		{
			throw new UserException(sprintf('Le plugin "%s" n\'existe pas ou n\'est pas installé correctement.', $id));
		}

		$this->plugin->config = json_decode($this->plugin->config);
		
		if (!is_object($this->plugin->config))
		{
			$this->plugin->config = new \stdClass;
		}

		// Juste pour vérifier que le fichier source du plugin existe bien
		self::getPath($id);

		$this->id = $id;
	}

	/**
	 * Enregistrer les changements dans la config
	 */
	public function __destruct()
	{
		if ($this->config_changed)
		{
			$db = DB::getInstance();
			$db->update('plugins', 
				['config' => json_encode($this->plugin->config)],
				'id = \'' . $this->id . '\'');
		}
	}

	/**
	 * Renvoie le chemin absolu vers l'archive du plugin
	 * @return string Chemin PHAR vers l'archive
	 */
	public function path()
	{
		return self::getPath($this->id);
	}

	/**
	 * Renvoie une entrée de la configuration ou la configuration complète
	 * @param  string $key Clé à rechercher, ou NULL si on désire toutes les entrées de la
	 * @return mixed       L'entrée demandée (mixed), ou l'intégralité de la config (array),
	 * ou NULL si l'entrée demandée n'existe pas.
	 */
	public function getConfig($key = null)
	{
		if (is_null($key))
		{
			return $this->plugin->config;
		}

		if (property_exists($this->plugin->config, $key))
		{
			return $this->plugin->config->$key;
		}

		return null;
	}

	/**
	 * Enregistre une entrée dans la configuration du plugin
	 * @param string $key   Clé à modifier
	 * @param mixed  $value Valeur à enregistrer, choisir NULL pour effacer cette clé de la configuration
	 * @return boolean 		TRUE si tout se passe bien
	 */
	public function setConfig($key, $value = null)
	{
		if (is_null($value))
		{
			unset($this->plugin->config->$key);
		}
		else
		{
			$this->plugin->config->$key = $value;
		}

		$this->config_changed = true;

		return true;
	}

	/**
	 * Remplace toute la config du plugin
	 * @param \stdClass $config Configuration complète du plugin
	 */
	public function setConfigAll(\stdClass $config)
	{
		$this->plugin->config = $config;
		$this->config_changed = true;
		return true;
	}

	/**
	 * Renvoie une information ou toutes les informations sur le plugin
	 * @param  string $key Clé de l'info à retourner, ou NULL pour recevoir toutes les infos
	 * @return mixed       Info demandée ou tableau des infos.
	 */
	public function getInfos($key = null)
	{
		if (is_null($key))
		{
			return $this->plugin;
		}

		if (property_exists($this->plugin, $key))
		{
			return $this->plugin->$key;
		}

		return null;
	}

	/**
	 * Renvoie l'identifiant du plugin
	 * @return string Identifiant du plugin
	 */
	public function id()
	{
		return $this->id;
	}

	/**
	 * Inclure un fichier depuis le plugin (dynamique ou statique)
	 * @param  string $file Chemin du fichier à aller chercher : si c'est un .php il sera inclus,
	 * sinon il sera juste affiché
	 * @return void
	 * @throws UserException Si le fichier n'existe pas ou fait partie des fichiers qui ne peuvent
	 * être appelés que par des méthodes de Plugin.
	 * @throws \RuntimeException Si le chemin indiqué tente de sortir du contexte du PHAR
	 */
	public function call($file)
	{
		$file = preg_replace('!^[./]*!', '', $file);

		if (preg_match('!(?:\.\.|[/\\\\]\.|\.[/\\\\])!', $file))
		{
			throw new \RuntimeException('Chemin de fichier incorrect.');
		}

		$forbidden = ['install.php', 'garradin_plugin.ini', 'upgrade.php', 'uninstall.php'];

		if (in_array($file, $forbidden))
		{
			throw new UserException('Le fichier ' . $file . ' ne peut être appelé par cette méthode.');
		}

		if (!file_exists($this->path() . '/www/' . $file))
		{
			throw new UserException('Le fichier ' . $file . ' n\'existe pas dans le plugin ' . $this->id);
		}

		if (is_dir($this->path() . '/www/' . $file))
		{
			throw new UserException(sprintf('Sécurité : impossible de lister le répertoire "%s" du plugin "%s".', $file, $this->id));
		}

		if (substr($file, -4) === '.php')
		{
			// Créer l'environnement d'exécution du plugin
			$plugin = $this;
			global $tpl, $config, $session, $form;

			include $this->path() . '/www/' . $file;
		}
		else
		{
			// Récupération du type MIME à partir de l'extension
			$pos = strrpos($file, '.');
			$ext = substr($file, $pos+1);

			if (isset($this->mimes[$ext]))
			{
				$mime = $this->mimes[$ext];
			}
			else
			{
				$mime = 'text/plain';
			}

			header('Content-Type: ' .$mime);
			header('Content-Length: ' . filesize($this->path() . '/www/' . $file));

			readfile($this->path() . '/www/' . $file);
		}
	}

	/**
	 * Désinstaller le plugin
	 * @return boolean TRUE si la suppression a fonctionné
	 */
	public function uninstall()
	{
		if (file_exists($this->path() . '/uninstall.php'))
		{
			$plugin = $this;
			include $this->path() . '/uninstall.php';
		}

		$db = DB::getInstance();
		$db->delete('plugins_signaux', 'plugin = ?', $this->id);
		return $db->delete('plugins', 'id = ?', $this->id);
	}

	/**
	 * Renvoie TRUE si le plugin a besoin d'être mis à jour
	 * (si la version notée dans la DB est différente de la version notée dans garradin_plugin.ini)
	 * @return boolean TRUE si le plugin doit être mis à jour, FALSE sinon
	 */
	public function needUpgrade()
	{
		$infos = (object) parse_ini_file($this->path() . '/garradin_plugin.ini', false);

		if (version_compare($this->plugin->version, $infos->version, '!='))
			return true;

		return false;
	}

	/**
	 * Mettre à jour le plugin
	 * Appelle le fichier upgrade.php dans l'archive si celui-ci existe.
	 * @return boolean TRUE si tout a fonctionné
	 */
	public function upgrade()
	{
		if (file_exists($this->path() . '/upgrade.php'))
		{
			$plugin = $this;
			include $this->path() . '/upgrade.php';
		}

		$infos = (object) parse_ini_file($this->path() . '/garradin_plugin.ini', false);

		$data = [
			'nom'		=>	$infos->nom,
			'description'=>	$infos->description,
			'auteur'	=>	$infos->auteur,
			'url'		=>	$infos->url,
			'version'	=>	$infos->version,
			'menu'		=>	(int)(bool)$infos->menu,
		];

		if ($infos->menu && !empty($infos->menu_condition))
		{
			$data['menu_condition'] = trim($infos->menu_condition);
		}

		return DB::getInstance()->update('plugins', $data, 'id = :id', ['id' => $this->id]);
	}

	/**
	 * Associer un signal à un callback du plugin
	 * @param  string $signal   Nom du signal (par exemple boucle.agenda pour la boucle de type AGENDA)
	 * @param  mixed  $callback Callback, sous forme d'un nom de fonction ou de méthode statique
	 * @return boolean TRUE
	 */
	public function registerSignal($signal, $callback)
	{
		$callable_name = '';

		if (!is_callable($callback, true, $callable_name) || !is_string($callable_name))
		{
			throw new \LogicException('Le callback donné n\'est pas valide.');
		}

		// pour empêcher d'appeler des méthodes de Garradin après un import de base de données "hackée"
		if (strpos($callable_name, 'Garradin\\Plugin\\') !== 0)
		{
			throw new \LogicException('Le callback donné n\'utilise pas le namespace Garradin\\Plugin');
		}

		$db = DB::getInstance();

		// Signaux exclusifs, qui ne peuvent être attribués qu'à un seul plugin
		if (strpos($signal, 'boucle.') === 0)
		{
			$registered = $db->firstColumn('SELECT plugin FROM plugins_signaux WHERE signal = ? AND plugin != ?;', $signal, $this->id);

			if ($registered)
			{
				throw new \LogicException('Le signal ' . $signal . ' est exclusif et déjà associé au plugin "'.$registered.'"');
			}
		}

		$callable_name = str_replace('Garradin\\Plugin\\', '', $callable_name);

		$st = $db->prepare('INSERT OR REPLACE INTO plugins_signaux VALUES (:signal, :plugin, :callback);');
		$st->bindValue(':signal', $signal);
		$st->bindValue(':plugin', $this->id);
		$st->bindValue(':callback', $callable_name);
		return $st->execute();
	}

	/**
	 * Liste des plugins installés (en DB)
	 * @return array Liste des plugins triés par nom
	 */
	static public function listInstalled()
	{
		$db = DB::getInstance();
		$plugins = $db->getGrouped('SELECT id, * FROM plugins ORDER BY nom;');
		$system = explode(',', PLUGINS_SYSTEM);

		foreach ($plugins as &$row)
		{
			$row->system = in_array($row->id, $system);
			$row->disabled = !self::getPath($row->id, false);
		}

		return $plugins;
	}

	/**
	 * Checks if a plugin requires an upgrade and upgrade it
	 * This is run after an upgrade, a database restoration, or in the Plugins page
	 */
	static public function upgradeAllIfRequired(): void
	{
		// Mettre à jour les plugins si nécessaire
		foreach (self::listInstalled() as $id => $infos)
		{
			// Ne pas tenir compte des plugins dont le code n'est pas dispo
			if ($infos->disabled) {
				continue;
			}

			$plugin = new Plugin($id);

			if ($plugin->needUpgrade()) {
				$plugin->upgrade();
			}

			unset($plugin);
		}
	}

	/**
	 * Vérifie que les plugins système sont bien installés et sinon les réinstalle
	 * @return void
	 */
	static public function checkAndInstallSystemPlugins()
	{
		if (!PLUGINS_SYSTEM)
		{
			return true;
		}

		$system = explode(',', PLUGINS_SYSTEM);

		if (count($system) == 0)
		{
			return true;
		}

		$db = DB::getInstance();
		$installed = $db->getAssoc('SELECT id, id FROM plugins WHERE ' . $db->where('id', 'IN', $system));

		$missing = array_diff($system, (array) $installed);

		if (count($missing) == 0)
		{
			return true;
		}

		foreach ($missing as $plugin)
		{
			self::install($plugin);
		}

		return true;
	}

	/**
	 * Liste les plugins qui doivent être affichés dans le menu
	 * @return array Tableau associatif id => nom (ou un tableau vide si aucun plugin ne doit être affiché)
	 */
	static public function listMenu(Session $session)
	{
		self::checkAndInstallSystemPlugins();

		$db = DB::getInstance();
		$list = $db->getGrouped('SELECT id, nom, menu_condition FROM plugins WHERE menu = 1 ORDER BY nom;');

		// FIXME deprecated
		$fix_legacy = [
			'{Membres::DROIT_AUCUN}' => '{ACCESS_NONE}',
			'{Membres::DROIT_ACCES}' => '{ACCESS_READ}',
			'{Membres::DROIT_ECRITURE}' => '{ACCESS_WRITE}',
			'{Membres::DROIT_ADMIN}' => '{ACCESS_ADMIN}',
			'{$user.droit_compta}' => '{$user.perm_accounting}',
			'{$user.droit_membres}' => '{$user.perm_users}',
			'{$user.droit_config}' => '{$user.perm_config}',
			'{$user.droit_wiki}' => '{$user.perm_documents}',
		];

		$user = $session->getUser();
		$permissions = [
			'{ACCESS_NONE}'  => $session::ACCESS_NONE,
			'{ACCESS_READ}'  => $session::ACCESS_READ,
			'{ACCESS_WRITE}' => $session::ACCESS_WRITE,
			'{ACCESS_ADMIN}' => $session::ACCESS_ADMIN,
		];

		foreach ($list as $id => &$row)
		{
			if (!self::getPath($row->id, false))
			{
				// Ne pas lister les plugins dont le code a disparu
				unset($list[$id]);
				continue;
			}

			if (!$row->menu_condition)
			{
				$row = $row->nom;
				continue;
			}

			$new_condition = strtr($row->menu_condition, $fix_legacy);

			// FIXME: legacy
			if ($new_condition != $row->menu_condition) {
				$db->update('plugins', ['menu_condition' => $new_condition], 'id = :id', ['id' => $id]);
				$row->menu_condition = $new_condition;
			}

			$condition = strtr($row->menu_condition, $permissions);

			$condition = preg_replace_callback('/\{\$user\.(\w+)\}/', function ($m) use ($user, $db) {
				$prop = $m[1];
				if (!property_exists($user, $prop)) {
					return 'NULL';
				}

				if (substr($prop, 0, 5) == 'perm_') {
					return (int) $user->$prop;
				}

				return $db->quote($user->$prop);
			}, $condition);

			$query = 'SELECT 1 WHERE ' . $condition . ';';

			$res = $db->protectSelect(['membres' => []], $query);

			if (!$db->firstColumn($query))
			{
				unset($list[$id]);
				continue;
			}

			$row = $row->nom;
		}

		unset($row);

		return $list;
	}

	/**
	 * Liste les plugins téléchargés mais non installés
	 * @return array Liste des plugins téléchargés
	 */
	static public function listDownloaded()
	{
		$installed = self::listInstalled();

		$list = [];
		$dir = dir(PLUGINS_ROOT);

		while ($file = $dir->read())
		{
			if (substr($file, 0, 1) == '.')
				continue;

			if (preg_match('!^(' . self::PLUGIN_ID_SYNTAX . ')\.tar\.gz$!', $file, $match))
			{
				// Sélectionner les archives PHAR
				$file = $match[1];
			}
			elseif (is_dir(PLUGINS_ROOT . '/' . $file)
				&& preg_match('!^' . self::PLUGIN_ID_SYNTAX . '$!', $file)
				&& is_file(sprintf('%s/%s/garradin_plugin.ini', PLUGINS_ROOT, $file)))
			{
				// Rien à faire, le nom valide du plugin est déjà dans "$file"
			}
			else
			{
				// ignorer tout ce qui n'est pas un répertoire ou une archive PHAR valides
				continue;
			}

			if (array_key_exists($file, $installed))
			{
				// Ignorer les plugins déjà installés
				continue;
			}

			$list[$file] = (object) parse_ini_file(self::getPath($file) . '/garradin_plugin.ini', false);
		}

		$dir->close();

		return $list;
	}

	/**
	 * Liste des plugins officiels depuis le repository signé
	 * @return array Liste des plugins
	 */
	static public function listOfficial()
	{
		// La liste est stockée en cache une heure pour ne pas tuer le serveur distant
		if (Static_Cache::expired('plugins_list', 3600 * 24))
		{
			$url = parse_url(PLUGINS_URL);

			$context_options = [
				'ssl' => [
					'verify_peer'   => TRUE,
					'verify_depth'  => 5,
					'CN_match'      => $url['host'],
					'SNI_enabled'	=> true,
					'SNI_server_name'		=>	$url['host'],
					'disable_compression'	=>	true,
				]
			];

			$context = stream_context_create($context_options);

			try {
				$result = file_get_contents(PLUGINS_URL, false, $context);
			}
			catch (\Exception $e)
			{
				throw new UserException('Le téléchargement de la liste des plugins a échoué : ' . $e->getMessage());
			}

			Static_Cache::store('plugins_list', $result);
		}
		else
		{
			$result = Static_Cache::get('plugins_list');
		}

		$list = json_decode($result, true);
		return $list;
	}

	static public function fetchOfficialList()
	{
		return []; // FIXME
	}

	/**
	 * Vérifier le hash du plugin $id pour voir s'il correspond au hash du fichier téléchargés
	 * @param  string $id Identifiant du plugin
	 * @return boolean    TRUE si le hash correspond (intégrité OK), sinon FALSE
	 */
	static public function checkHash($id)
	{
		$list = self::fetchOfficialList();

		if (!array_key_exists($id, $list))
			return null;

		$hash = sha1_file(PLUGINS_ROOT . '/' . $id . '.tar.gz');

		return ($hash === $list[$id]['hash']);
	}

	/**
	 * Est-ce que le plugin est officiel ?
	 * @param  string  $id Identifiant du plugin
	 * @return boolean     TRUE si le plugin est officiel, FALSE sinon
	 */
	static public function isOfficial($id)
	{
		$list = self::fetchOfficialList();
		return array_key_exists($id, $list);
	}

	/**
	 * Télécharge un plugin depuis le repository officiel, et l'installe
	 * @param  string $id Identifiant du plugin
	 * @return boolean    TRUE si ça marche
	 * @throws \LogicException Si le plugin n'est pas dans la liste des plugins officiels
	 * @throws UserException Si le plugin est déjà installé ou que le téléchargement a échoué
	 * @throws \RuntimeException Si l'archive téléchargée est corrompue (intégrité du hash ne correspond pas)
	 */
	static public function download($id)
	{
		$list = self::fetchOfficialList();

		if (!array_key_exists($id, $list))
		{
			throw new \LogicException($id . ' n\'est pas un plugin officiel (absent de la liste)');
		}

		if (file_exists(PLUGINS_ROOT . '/' . $id . '.tar.gz'))
		{
			throw new UserException('Le plugin '.$id.' existe déjà.');
		}

		$url = parse_url(PLUGINS_URL);

		$context_options = [
			'ssl' => [
				'verify_peer'   => TRUE,
				'cafile'        => ROOT . '/include/data/cacert.pem',
				'verify_depth'  => 5,
				'CN_match'      => $url['host'],
				'SNI_enabled'	=> true,
				'SNI_server_name'		=>	$url['host'],
				'disable_compression'	=>	true,
			]
		];

		$context = stream_context_create($context_options);

		try {
			copy($list[$id]['phar'], PLUGINS_ROOT . '/' . $id . '.tar.gz', $context);
		}
		catch (\Exception $e)
		{
			throw new UserException('Le téléchargement du plugin '.$id.' a échoué : ' . $e->getMessage());
		}

		if (!self::checkHash($id))
		{
			Utils::safe_unlink(PLUGINS_ROOT . '/' . $id . '.tar.gz');
			throw new \RuntimeException('L\'archive du plugin '.$id.' est corrompue (le hash SHA1 ne correspond pas).');
		}

		self::install($id, true);

		return true;
	}

	/**
	 * Installer un plugin
	 * @param  string  $id       Identifiant du plugin
	 * @param  boolean $official TRUE si le plugin est officiel
	 * @return boolean           TRUE si tout a fonctionné
	 */
	static public function install($id, $official = false)
	{
		$path = self::getPath($id);

		if (!file_exists($path . '/garradin_plugin.ini'))
		{
			throw new UserException(sprintf('Le plugin "%s" n\'est pas une extension Garradin : fichier garradin_plugin.ini manquant.', $id));
		}

		$infos = (object) parse_ini_file($path . '/garradin_plugin.ini', false);

		$required = ['nom', 'description', 'auteur', 'url', 'version', 'menu', 'config'];

		foreach ($required as $key)
		{
			if (!property_exists($infos, $key))
			{
				throw new \RuntimeException('Le fichier garradin_plugin.ini ne contient pas d\'entrée "'.$key.'".');
			}
		}

		if (!empty($infos->min_version) && !version_compare(garradin_version(), $infos->min_version, '>='))
		{
			throw new \RuntimeException('Le plugin '.$id.' nécessite Garradin version '.$infos->min_version.' ou supérieure.');
		}

		if (!empty($infos->max_version) && !version_compare(garradin_version(), $infos->max_version, '>'))
		{
			throw new \RuntimeException('Le plugin '.$id.' nécessite Garradin version '.$infos->max_version.' ou inférieure.');
		}

		if (!empty($infos->menu) && !file_exists($path . '/www/admin/index.php'))
		{
			throw new \RuntimeException('Le plugin '.$id.' ne comporte pas de fichier www/admin/index.php alors qu\'il demande à figurer au menu.');
		}

		$config = '';

		if ((bool)$infos->config)
		{
			if (!file_exists($path . '/config.json'))
			{
				throw new \RuntimeException('L\'archive '.$id.'.tar.gz ne comporte pas de fichier config.json 
					alors que le plugin nécessite le stockage d\'une configuration.');
			}

			if (!file_exists($path . '/www/admin/config.php'))
			{
				throw new \RuntimeException('L\'archive '.$id.'.tar.gz ne comporte pas de fichier www/admin/config.php 
					alors que le plugin nécessite le stockage d\'une configuration.');
			}

			$config = json_decode(file_get_contents($path . '/config.json'));

			if (is_null($config))
			{
				throw new \RuntimeException('config.json invalide. Code erreur JSON: ' . json_last_error());
			}

			$config = json_encode($config);
		}

		$data = [
			'id' 		=> 	$id,
			'officiel' 	=> 	(int)(bool)$official,
			'nom'		=>	$infos->nom,
			'description'=>	$infos->description,
			'auteur'	=>	$infos->auteur,
			'url'		=>	$infos->url,
			'version'	=>	$infos->version,
			'menu'		=>	(int)(bool)$infos->menu,
			'config'	=>	$config,
		];

		if ($infos->menu && !empty($infos->menu_condition))
		{
			$data['menu_condition'] = trim($infos->menu_condition);
		}

		$db = DB::getInstance();
		$db->begin();
		$db->insert('plugins', $data);

		if (file_exists($path . '/install.php'))
		{
			$plugin = new Plugin($id);
			require $plugin->path() . '/install.php';
		}

		$db->commit();

		return true;
	}

	/**
	 * Renvoie la version installée d'un plugin ou FALSE s'il n'est pas installé
	 * @param  string $id Identifiant du plugin
	 * @return mixed      Numéro de version du plugin ou FALSE
	 */
	static public function getInstalledVersion($id)
	{
		return DB::getInstance()->first('SELECT version FROM plugins WHERE id = ?;', $id);
	}

	/**
	 * Déclenche le signal donné auprès des plugins enregistrés
	 * @param  string $signal Nom du signal
	 * @param  array  $params Paramètres du callback (array ou null)
	 * @return NULL 		  NULL si aucun plugin n'a été appelé,
	 * TRUE si un plugin a été appelé et a arrêté l'exécution,
	 * FALSE si des plugins ont été appelés mais aucun n'a stopé l'exécution
	 */
	static public function fireSignal($signal, $params = null, &$callback_return = null)
	{
		$list = DB::getInstance()->get('SELECT * FROM plugins_signaux WHERE signal = ?;', $signal);

		if (!count($list)) {
			return null;
		}

		if (null === $params) {
			$params = [];
		}

		$system = explode(',', PLUGINS_SYSTEM);

		foreach ($list as $row)
		{
			$path = self::getPath($row->plugin, in_array($row->plugin, $system));

			// Ne pas appeler les plugins dont le code n'existe pas/plus,
			// SAUF si c'est un plugin système (auquel cas ça fera une erreur)
			if (!$path)
			{
				continue;
			}

			$params['plugin_root'] = $path;

			$return = call_user_func_array('Garradin\\Plugin\\' . $row->callback, [&$params, &$callback_return]);

			if (true === $return) {
				return true;
			}
		}

		return false;
	}
}