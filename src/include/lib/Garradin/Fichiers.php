<?php

namespace Garradin;

class Fichiers
{
	public $id;
	public $nom;
	public $type;
	public $image;
	public $datetime;
	public $hash;
	public $taille;
	public $id_contenu;

	const LIEN_COMPTA = 'compta_journal';
	const LIEN_WIKI = 'wiki_pages';
	const LIEN_MEMBRES = 'membres';

	const TAILLE_MINIATURE = 200;

	public function __construct($id)
	{
		$data = DB::getInstance()->simpleQuerySingle('SELECT fichiers.*, fc.hash, fc.taille,
			strftime(\'%s\', datetime) AS datetime
			FROM fichiers INNER JOIN fichiers_contenu AS fc ON fc.id = fichiers.id_contenu
			WHERE fichiers.id = ?;', true, (int)$id);

		if (!$data)
		{
			throw new \InvalidArgumentException('Ce fichier n\'existe pas.');
		}

		foreach ($data as $key=>$value)
		{
			$this->$key = $value;
		}
	}	

	public function getURL($thumbnail = false)
	{
		$url = WWW_URL . 'f/' . base_convert((int)$this->id, 10, 36) . '/';

		if ($thumbnail)
		{
			$url .= 't/';
		}

		return $url . $this->nom;
	}

	/**
	 * Lier un fichier à un contenu
	 * @param  string $type       Type de contenu (constantes LIEN_*)
	 * @param  integer $foreign_id ID du contenu lié
	 * @return boolean TRUE en cas de succès
	 */
	public function linkTo($type, $foreign_id)
	{
		$db = DB::getInstance();
		$check = [self::LIEN_MEMBRES, self::LIEN_WIKI, self::LIEN_COMPTA];

		if (!in_array($type, $check))
		{
			throw new \LogicException('Type de lien de fichier inconnu.');
		}

		unset($check[array_search($type, $check)]);
		
		foreach ($check as $check_type)
		{
			if ($db->simpleQuerySingle('SELECT 1 FROM fichiers_' . $check_type . ' WHERE fichier = ?;', false, (int)$this->id))
			{
				throw new \LogicException('Ce fichier est déjà lié à un autre contenu : ' . $check_type);
			}
		}

		return $db->simpleExec('INSERT OR IGNORE INTO fichiers_' . $type . ' (fichier, id) VALUES (?, ?);',
			(int)$this->id, (int)$foreign_id);
	}

	/**
	 * Vérifie que l'utilisateur a bien le droit d'accéder à ce fichier
	 * @param  mixed   $user Tableau contenant les infos sur l'utilisateur connecté, provenant de Membres::getLoggedUser, ou false
	 * @return boolean       TRUE si l'utilisateur a le droit d'accéder au fichier, sinon FALSE
	 */
	public function checkAccess($user = false)
	{
		// On regarde déjà si le fichier n'est pas lié au wiki
		$wiki = DB::getInstance()->simpleQuerySingle('SELECT wp.droit_lecture FROM fichiers_' . self::LIEN_WIKI . ' AS link
			INNER JOIN wiki_pages AS wp ON wp.id = link.id
			WHERE link.fichier = ? LIMIT 1;', false, (int)$this->id);

		// Page wiki publique, aucune vérification à faire, seul cas d'accès à un fichier en dehors de l'espace admin
		if ($wiki !== false && $wiki == Wiki::LECTURE_PUBLIC)
		{
			return true;
		}
			
		// Pas d'utilisateur connecté, pas d'accès aux fichiers de l'espace admin
		if (empty($user['droits']))
		{
			return false;
		}

		if ($wiki !== false)
		{
			// S'il n'a même pas droit à accéder au wiki c'est mort
			if ($user['droits']['wiki'] < Membres::DROIT_ACCES)
			{
				return false;
			}

			// On renvoie à l'objet Wiki pour savoir si l'utilisateur a le droit de lire ce fichier
			$_w = new Wiki;
			$_w->setRestrictionCategorie($user['id_categorie'], $user['droits']['wiki']);
			return $_w->canReadPage($wiki);
		}

		// On regarde maintenant si le fichier est lié à la compta
		$compta = DB::getInstance()->simpleQuerySingle('SELECT 1 
			FROM fichiers_' . self::LIEN_COMPTA . ' WHERE fichier = ? LIMIT 1;', false, (int)$this->id);

		if ($compta && $user['droits']['compta'] >= Membres::DROIT_ACCES)
		{
			// OK si accès à la compta
			return true;
		}

		// Enfin, si le fichier est lié à un membre
		$membre = DB::getInstance()->simpleQuerySingle('SELECT id 
			FROM fichiers_' . self::LIEN_MEMBRES . ' WHERE fichier = ? LIMIT 1;', false, (int)$this->id);

		if ($membre !== false)
		{
			// De manière évidente, l'utilisateur a le droit d'accéder aux fichiers liés à son profil
			if ((int)$membre == $user['id'])
			{
				return true;
			}

			// Pour voir les fichiers des membres il faut pouvoir les gérer
			if ($user['droits']['membres'] >= Membres::DROIT_ECRITURE)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Supprime le fichier
	 * @return boolean TRUE en cas de succès
	 */
	public function remove()
	{
		$db = DB::getInstance();
		$db->exec('BEGIN;');
		$db->simpleExec('DELETE FROM fichiers_compta_journal WHERE fichier = ?;', (int)$this->id);
		$db->simpleExec('DELETE FROM fichiers_wiki_pages WHERE fichier = ?;', (int)$this->id);
		$db->simpleExec('DELETE FROM fichiers_membres WHERE fichier = ?;', (int)$this->id);

		// Suppression du contenu s'il n'est pas utilisé par un autre fichier
		if (!($id_contenu = $db->simpleQuerySingle('SELECT id_contenu FROM fichiers AS f1 INNER JOIN fichiers AS f2 
			ON f1.id_contenu = f2.id_contenu AND f1.id != f2.id WHERE f2.id = ?;', false, (int)$this->id)))
		{
			$db->simpleExec('DELETE FROM fichiers_contenu WHERE id = ?;', (int)$id_contenu);
		}

		$db->simpleExec('DELETE FROM fichiers WHERE id = ?;', (int)$this->id);

		$cache_id = 'fichiers.' . $this->id_contenu;
		
		Static_Cache::remove($cache_id);
		Static_Cache::remove($cache_id . '.thumb');

		return $db->exec('END;');
	}

	protected function getFilePathFromCache()
	{
		// Le cache est géré par ID contenu, pas ID fichier, pour minimiser l'espace disque utilisé
		$cache_id = 'fichiers.' . $this->id_contenu;

		// Le fichier n'existe pas dans le cache statique, on l'enregistre
		if (!Static_Cache::exists($cache_id))
		{
			$blob = DB::getInstance()->openBlob('fichiers_contenu', 'contenu', (int)$this->id_contenu);
			Static_Cache::storeFromPointer($cache_id, $blob);
			fclose($blob);
		}

		return Static_Cache::getPath($cache_id);
	}

	/**
	 * Envoie le fichier au client HTTP
	 * @return void
	 */
	public function serve()
	{
		return $this->_serve($this->getFilePathFromCache(), $this->type, $this->nom, $this->taille);
	}

	/**
	 * Envoie une miniature à la taille indiquée au client HTTP
	 * @return void
	 */
	public function serveThumbnail()
	{
		if (!$this->image)
		{
			throw new \LogicException('Il n\'est pas possible de fournir une miniature pour un fichier qui n\'est pas une image.');
		}

		$cache_id = 'fichiers.' . $this->id_contenu . '.thumb';
		$path = Static_Cache::getPath($cache_id);

		// La miniature n'existe pas dans le cache statique, on la crée
		if (!Static_Cache::exists($cache_id))
		{
			$source = $this->getFilePathFromCache();
			\KD2\Image::resize($source, $path, self::TAILLE_MINIATURE);
		}

		return $this->_serve($path, $this->type);
	}

	/**
	 * Servir un fichier local en HTTP
	 * @param  string $path Chemin vers le fichier local
	 * @param  string $type Type MIME du fichier
	 * @param  string $name Nom du fichier avec extension
	 * @param  integer $size Taille du fichier en octets (facultatif)
	 * @return boolean TRUE en cas de succès
	 */
	protected function _serve($path, $type, $name = false, $size = null)
	{
		// Désactiver le cache
		header('Pragma: public');
		header('Expires: -1');
		header('Cache-Control: public, must-revalidate, post-check=0, pre-check=0');

		header('Content-Type: '.$type);

		if ($name)
		{
			header('Content-Disposition: attachment; filename="' . $name . '"');
		}
		
		// Utilisation de XSendFile si disponible
		if (ENABLE_XSENDFILE && isset($_SERVER['SERVER_SOFTWARE']))
		{
			if (stristr($_SERVER['SERVER_SOFTWARE'], 'apache') 
				&& function_exists('apache_get_modules') 
				&& in_array('mod_xsendfile', apache_get_modules()))
			{
				header('X-Sendfile: ' . $path);
				return true;
			}
			else if (stristr($_SERVER['SERVER_SOFTWARE'], 'lighttpd'))
			{
				header('X-Sendfile: ' . $path);
				return true;
			}
		}

		// Désactiver gzip
		if (function_exists('apache_setenv'))
		{
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		if ($size)
		{
			header('Content-Length: '. (int)$size);
		}

		ob_clean();
		flush();

		// Sinon on envoie le fichier à la mano
		return readfile($path);
	}

	/**
	 * Vérifie si le hash fourni n'est pas déjà stocké
	 * Utile pour par exemple reconnaître un ficher dont le contenu est déjà stocké, et éviter un nouvel upload
	 * @param  string $hash Hash SHA1
	 * @return boolean      TRUE si le hash est déjà présent dans fichiers_contenu, FALSE sinon
	 */
	static public function checkHash($hash)
	{
		return (boolean) DB::getInstance()->simpleQuerySingle(
			'SELECT 1 FROM fichiers_contenu WHERE hash = ?;', 
			false, 
			trim(strtolower($hash))
		);
	}

	/**
	 * Retourne un tableau de hash trouvés dans la DB parmi une liste de hash fournis
	 * @param  array  $list Liste de hash à vérifier
	 * @return array        Liste des hash trouvés
	 */
	static public function checkHashList($list)
	{
		$hash_list = '';
		$db = DB::getInstance();

		foreach ($list as $hash)
		{
			$hash_list .= '\'' . $db->escapeString($hash) . '\',';
		}

		$hash_list = substr($hash_list, 0, -1);

		return $db->queryFetchAssoc('SELECT hash, 1
			FROM fichiers_contenu WHERE hash IN (' . $hash_list . ');');
	}

	/**
	 * Récupération du message d'erreur
	 * @param  integer $error Code erreur du $_FILE
	 * @return string Message d'erreur
	 */
	static public function getErrorMessage($error)
	{
		switch ($error)
		{
			case UPLOAD_ERR_INI_SIZE:
				return 'Le fichier excède la taille permise par la configuration du serveur.';
			case UPLOAD_ERR_FORM_SIZE:
				return 'Le fichier excède la taille permise par le formulaire.';
			case UPLOAD_ERR_PARTIAL:
				return 'L\'envoi du fichier a été interrompu.';
			case UPLOAD_ERR_NO_FILE:
				return 'Aucun fichier n\'a été reçu.';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'Pas de répertoire temporaire pour stocker le fichier.';
			case UPLOAD_ERR_CANT_WRITE:
				return 'Impossible d\'écrire le fichier sur le disque du serveur.';
			case UPLOAD_ERR_EXTENSION:
				return 'Une extension du serveur a interrompu l\'envoi du fichier.';
			default:
				return 'Erreur inconnue: ' . $error;
		}
	}

	/**
	 * Upload du fichier par POST
	 * @param  array  $file  Caractéristiques du fichier envoyé
	 * @return object Un objet Fichiers en cas de succès
	 */
	static public function upload($file)
	{
		if (!empty($file['error']))
		{
			throw new UserException(self::getErrorMessage($file['error']));
		}

		if (empty($file['size']) || empty($file['name']))
		{
			throw new UserException('Fichier reçu invalide : vide ou sans nom de fichier.');
		}

		if (!is_uploaded_file($file['tmp_name']))
		{
			throw new \RuntimeException('Le fichier n\'a pas été envoyé de manière conventionnelle.');
		}

		$name = preg_replace('/[ ]/', '_', $file['name']);
		$name = preg_replace('/[^\d\w._-]/ui', '', $file['name']);

		$bytes = file_get_contents($file['tmp_name'], false, null, -1, 1024);
		$type = \KD2\FileInfo::guessMimeType($bytes);

		if (!$type)
		{
			$ext = substr($name, strrpos($name, '.')+1);
			$ext = strtolower($ext);

			$type = \KD2\FileInfo::getMimeTypeFromFileExtension($ext);
		}

		$is_image = preg_match('/^image\//', $type);

		$hash = sha1_file($file['tmp_name']);
		$size = filesize($file['tmp_name']);

		$db = DB::getInstance();
		$db->exec('BEGIN;');

		$db->simpleInsert('fichiers_contenu', [
			'hash'		=>	$hash,
			'taille'	=>	(int)$size,
			'contenu'	=>	[\SQLITE3_BLOB, file_get_contents($file['tmp_name'])],
		]);

		$id_contenu = $db->lastInsertRowID();

		$db->simpleInsert('fichiers', [
			'id_contenu'	=>	(int)$id_contenu,
			'nom'			=>	$name,
			'type'			=>	$type,
			'image'			=>	(int)$is_image,
		]);

		$db->exec('END;');

		return new Fichiers($db->lastInsertRowID());
	}

	/**
	 * Envoie un fichier déjà stocké
	 * @param  string $name Nom du fichier
	 * @param  string $hash Hash SHA1 du contenu du fichier
	 * @return object       Un objet Fichiers en cas de succès
	 */
	static public function uploadExistingHash($name, $hash)
	{
		$db = DB::getInstance();
		$name = preg_replace('/[^\d\w._-]/ui', '', $name);

		$file = $db->simpleQuerySingle('SELECT * FROM fichiers 
			INNER JOIN fichiers_contenu AS fc ON fc.id = fichiers.id_contenu AND fc.hash = ?;', true, trim($hash));

		if (!$file)
		{
			throw new UserException('Le fichier à copier n\'existe pas (aucun hash ne correspond à '.$hash.').');
		}

		$db->simpleInsert('fichiers', [
			'id_contenu'	=>	(int)$file['id_contenu'],
			'nom'			=>	$name,
			'type'			=>	$file['type'],
			'image'			=>	(int)$file['image'],
		]);

		return new Fichiers($db->lastInsertRowID());
	}

	static public function SkrivHTML($args, $content, $skriv)
	{
		if (empty($args['id']) && !empty($args[0]))
		{
			$args = ['id' => (int)$args[0]];
		}

		if (empty($args['id']))
		{
			return $skriv->parseError('/!\ Tag fichier : aucun numéro de fichier indiqué.');
		}

		$db = DB::getInstance();

		try {
			$file = new Fichiers($args['id']);
		}
		catch (\InvalidArgumentException $e)
		{
			return $skriv->parseError('/!\ Tag fichier : ' . $e->getMessage());
		}

		if ($file->image)
		{
			return '
			<figure class="image">
				<a href="'.$file->getURL().'"><img src="'.$file->getURL(true).'" 
					alt="'.htmlspecialchars($file->nom, ENT_QUOTES, 'UTF-8').'" /></a>
			</figure>';
		}
		else
		{
			return '<a href="'.$file->getURL().'">'.htmlspecialchars($file->nom, ENT_QUOTES, 'UTF-8').'</a>';
		}
	}
}