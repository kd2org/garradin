<?php

namespace Garradin\Entities\Files;

use KD2\Graphics\Image;
use KD2\DB\EntityManager as EM;

use Garradin\DB;
use Garradin\Entity;
use Garradin\UserException;
use Garradin\Membres\Session;
use Garradin\Static_Cache;
use Garradin\Utils;
use Garradin\Entities\Web\Page;

use Garradin\Files\Files;

use const Garradin\{WWW_URL, ENABLE_XSENDFILE};

class File extends Entity
{
	const TABLE = 'files';

	protected $id;
	protected $context;
	protected $context_ref;
	protected $name;
	protected $type;
	protected $image;
	protected $size;
	protected $hash;

	protected $created;
	protected $modified;

	protected $author_id;

	protected $_types = [
		'id'           => 'int',
		'context'      => 'string',
		'context_ref'  => '?int|string',
		'name'         => 'string',
		'type'         => '?string',
		'image'        => 'int',
		'size'         => 'int',
		'hash'         => 'string',
		'created'      => 'DateTime',
		'modified'     => 'DateTime',
		'author_id'    => '?int',
	];

	protected $_parent;

	/**
	 * Tailles de miniatures autorisées, pour ne pas avoir 500 fichiers générés avec 500 tailles différentes
	 * @var array
	 */
	const ALLOWED_THUMB_SIZES = [200, 500, 1200];

	const FILE_TYPE_HTML = 'text/vnd.paheko.web';
	const FILE_TYPE_ENCRYPTED = 'text/vnd.skriv.encrypted';
	const FILE_TYPE_SKRIV = 'text/vnd.skriv';

	const EDITOR_WEB = 'web';
	const EDITOR_ENCRYPTED = 'encrypted';
	const EDITOR_CODE = 'code';

	const CONTEXT_DOCUMENTS = 'documents';
	const CONTEXT_USER = 'user';
	const CONTEXT_TRANSACTION = 'transaction';
	const CONTEXT_CONFIG = 'config';
	const CONTEXT_WEB = 'web';
	const CONTEXT_SKELETON = 'skel';
	const CONTEXT_FILE = 'file';

	const CONTEXTS_NAMES = [
		self::CONTEXT_DOCUMENTS => 'Documents',
		self::CONTEXT_USER => 'Membre',
		self::CONTEXT_TRANSACTION => 'Écriture comptable',
		self::CONTEXT_CONFIG => 'Configuration',
		self::CONTEXT_WEB => 'Site web',
		self::CONTEXT_SKELETON => 'Squelettes',
		self::CONTEXT_FILE => 'Fichier',
	];

	const THUMB_CACHE_ID = 'file.thumb.%d.%d';

	public function __construct()
	{
		parent::__construct();
		$this->created = new \DateTime;
		$this->modified = new \DateTime;
	}

	public function selfCheck(): void
	{
		parent::selfCheck();
		$this->assert($this->image === 0 || $this->image === 1);

		// Check file path uniqueness
		if (isset($this->_modified['name'])) {
			$db = DB::getInstance();
			$clause = 'context = ? AND name = ? AND context_ref';
			$args = [$this->context, $this->name];

			if (null === $this->context_ref) {
				$clause .= ' IS NULL';
			}
			else {
				$clause .= ' = ?';
				$args[] = $this->context_ref;
			}

			$this->assert($this->exists() || !$db->test(self::TABLE, $clause, $args), 'Un fichier avec ce nom existe déjà');
			$this->assert(!$this->exists() || $db->test(self::TABLE, $clause . ' AND id != ?', $args + [$this->id()]), 'Un fichier avec ce nom existe déjà');
		}
	}

	public function delete(): bool
	{
		Files::callStorage('checkLock');

		// Delete linked files
		Files::deleteLinkedFiles(self::CONTEXT_FILE, $this->id());

		// Delete actual file content
		Files::callStorage('delete', $this);

		// Delete metadata
		$return = parent::delete();

		// clean up thumbnails
		foreach (self::ALLOWED_THUMB_SIZES as $size)
		{
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->id(), $size));
		}

		return $return;
	}

	public function save(): bool
	{
		// Force CSS mimetype
		if (substr($this->name, -4) == '.css') {
			$this->set('type', 'text/css');
		}
		elseif (substr($this->name, -3) == '.js') {
			$this->set('type', 'text/javascript');
		}

		$return = parent::save();

		// Store content in search table
		if ($return && substr($this->type, 0, 5) == 'text/') {
			$content = Files::callStorage('fetch', $this);

			if ($this->type == self::FILE_TYPE_HTML) {
				$content = strip_tags($content);
			}

			if ($this->type == self::FILE_TYPE_ENCRYPTED) {
				$content = 'Contenu chiffré';
			}

			DB::getInstance()->preparedQuery('INSERT OR REPLACE INTO files_search (id, content) VALUES (?, ?);', $this->id(), $content);
		}

		return $return;
	}

	public function store(string $source_path = null, $source_content = null): self
	{
		if ($source_path && !$source_content)
		{
			$this->set('hash', sha1_file($source_path));
			$this->set('size', filesize($source_path));
		}
		else
		{
			$this->set('hash', sha1($source_content));
			$this->set('size', strlen($source_content));
		}

		// Check that it's a real image
		if ($this->image) {
			try {
				if ($source_path && !$source_content) {
					$i = new Image($source_path);
				}
				else {
					$i = Image::createFromBlob($source_content);
				}

				// Recompress PNG files from base64, assuming they are coming
				// from JS canvas which doesn't know how to gzip (d'oh!)
				if ($i->format() == 'png' && null !== $source_content) {
					$source_content = $i->output('png', true);
					$this->set('hash', sha1($source_content));
					$this->set('size', strlen($source_content));
				}

				unset($i);
			}
			catch (\RuntimeException $e) {
				if (strstr($e->getMessage(), 'No suitable image library found')) {
					throw new \RuntimeException('Le serveur n\'a aucune bibliothèque de gestion d\'image installée, et ne peut donc pas accepter les images. Installez Imagick ou GD.');
				}

				throw new UserException('Fichier image invalide');
			}
		}

		Files::callStorage('checkLock');

		if (!Files::callStorage('store', $this, $source_path, $source_content)) {
			throw new UserException('Le fichier n\'a pas pu être enregistré.');
		}

		return $this;
	}

	static public function createAndStore(string $name, string $context, ?string $context_ref, string $source_path = null, string $source_content = null): self
	{
		$file = self::create($name, $context, $context_ref, $source_path, $source_content);

		$file->store($source_path, $source_content);
		$file->save();

		return $file;
	}

	static public function create(string $name, string $context, ?string $context_ref, string $source_path = null, string $source_content = null): self
	{
		if (isset($source_path, $source_content)) {
			throw new \InvalidArgumentException('Either source path or source content should be set but not both');
		}

		$finfo = \finfo_open(\FILEINFO_MIME_TYPE);
		$file = new self;
		$file->set('name', $name);
		$file->set('context', $context);
		$file->set('context_ref', $context_ref);

		$db = DB::getInstance();

		if ($source_path && !$source_content) {
			$file->set('type', finfo_file($finfo, $source_path));
		}
		else {
			$file->set('type', finfo_buffer($finfo, $source_content));
		}

		$file->set('image', preg_match('/^image\/(?:png|jpe?g|gif)$/', $file->type));

		return $file;
	}

	/**
	 * Upload de fichier à partir d'une chaîne en base64
	 * @param  string $name
	 * @param  string $content
	 * @return File
	 */
	static public function createFromBase64(string $name, string $context, ?string $context_ref, string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		return self::createAndStore($name, $context, $context_ref, null, $content);
	}

	public function storeFromBase64(string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		return $this->store(null, $content);
	}

	/**
	 * Upload du fichier par POST
	 */
	static public function upload(string $key, string $context, ?string $context_ref): self
	{
		if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
			throw new UserException('Aucun fichier reçu');
		}

		$file = $_FILES[$key];

		if (!empty($file['error'])) {
			throw new UserException(self::getErrorMessage($file['error']));
		}

		if (empty($file['size']) || empty($file['name'])) {
			throw new UserException('Fichier reçu invalide : vide ou sans nom de fichier.');
		}

		if (!is_uploaded_file($file['tmp_name'])) {
			throw new \RuntimeException('Le fichier n\'a pas été envoyé de manière conventionnelle.');
		}

		$name = preg_replace('/\s+/', '_', $file['name']);
		$name = preg_replace('/[^\d\w._-]/ui', '', $name);

		return self::createAndStore($name, $context, $context_ref, $file['tmp_name']);
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

	public function url($download = false): string
	{
		return self::getFileURL($this->id, $this->name, $this->hash, null, $download);
	}

	public function thumb_url(?int $size = null): string
	{
		$size = $size ?? min(self::ALLOWED_THUMB_SIZES);
		return self::getFileURL($this->id, $this->name, $this->hash, $size);
	}

	/**
	 * Renvoie l'URL vers un fichier
	 * @param  integer $id   Numéro du fichier
	 * @param  string  $name  Nom de fichier avec extension
	 * @param  integer $size Taille de la miniature désirée (pour les images)
	 * @return string        URL du fichier
	 */
	static public function getFileURL(int $id, string $name, string $hash, ?int $size = null, bool $download = false): string
	{
		$url = sprintf('%sf/%s/%s?', WWW_URL, base_convert((int)$id, 10, 36), $name);

		if ($size)
		{
			$url .= self::_findNearestThumbSize($size) . 'px&';
		}

		$url .= substr($hash, 0, 10);

		if ($download) {
			$url .= '&download';
		}

		return $url;
	}

	/**
	 * Renvoie la taille de miniature la plus proche de la taille demandée
	 * @param  integer $size Taille demandée
	 * @return integer       Taille possible
	 */
	static protected function _findNearestThumbSize($size)
	{
		$size = (int) $size;

		if (in_array($size, self::ALLOWED_THUMB_SIZES))
		{
			return $size;
		}

		foreach (self::ALLOWED_THUMB_SIZES as $s)
		{
			if ($s >= $size)
			{
				return $s;
			}
		}

		return max(self::ALLOWED_THUMB_SIZES);
	}

	public function listLinked(): array
	{
		return EM::getInstance(self::class)->all('SELECT * FROM files WHERE context = ? AND context_ref = ? ORDER BY name;',
			self::CONTEXT_FILE, $this->id());
	}

	/**
	 * Envoie le fichier au client HTTP
	 */
	public function serve(?Session $session = null, bool $download = false): void
	{
		if (!$this->checkReadAccess($session)) {
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Vous n\'avez pas accès à ce fichier.');
			return;
		}

		$path = Files::callStorage('getPath', $this);
		$content = null === $path ? Files::callStorage('fetch', $this) : null;

		$this->_serve($path, $content, $download);
	}

	/**
	 * Envoie une miniature à la taille indiquée au client HTTP
	 */
	public function serveThumbnail(?Session $session = null, ?int $width = null): void
	{
		if (!$this->checkReadAccess($session)) {
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Accès interdit');
			return;
		}

		if (!$this->image) {
			throw new UserException('Il n\'est pas possible de fournir une miniature pour un fichier qui n\'est pas une image.');
		}

		if (!$width) {
			$width = reset(self::ALLOWED_THUMB_SIZES);
		}

		if (!in_array($width, self::ALLOWED_THUMB_SIZES)) {
			throw new UserException('Cette taille de miniature n\'est pas autorisée.');
		}

		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->id(), $width);
		$destination = Static_Cache::getPath($cache_id);

		// La miniature n'existe pas dans le cache statique, on la crée
		if (!Static_Cache::exists($cache_id))
		{
			try {
				if ($path = Files::callStorage('getPath', $this)) {
					(new Image($path))->resize($width)->save($destination);
				}
				elseif ($content = Files::callStorage('fetch', $this)) {
					Image::createFromBlob($content)->resize($width)->save($destination);
				}
				else {
					throw new \RuntimeException('Unable to fetch file');
				}
			}
			catch (\RuntimeException $e) {
				throw new UserException('Impossible de créer la miniature');
			}
		}

		$this->_serve($destination, null);
	}

	/**
	 * Servir un fichier local en HTTP
	 * @param  string $path Chemin vers le fichier local
	 * @param  string $type Type MIME du fichier
	 * @param  string $name Nom du fichier avec extension
	 * @param  integer $size Taille du fichier en octets (facultatif)
	 * @return boolean TRUE en cas de succès
	 */
	protected function _serve(?string $path, ?string $content, bool $download = false): void
	{
		if ($this->isPublic()) {
			Utils::HTTPCache($this->hash, $this->created->getTimestamp());
		}
		else {
			// Disable browser cache
			header('Pragma: private');
			header('Expires: -1');
			header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
		}

		header(sprintf('Content-Type: %s', $this->type));
		header(sprintf('Content-Disposition: %s; filename="%s"', $download ? 'attachment' : 'inline', $this->name));

		// Utilisation de XSendFile si disponible
		if (null !== $path && ENABLE_XSENDFILE && isset($_SERVER['SERVER_SOFTWARE']))
		{
			if (stristr($_SERVER['SERVER_SOFTWARE'], 'apache')
				&& function_exists('apache_get_modules')
				&& in_array('mod_xsendfile', apache_get_modules()))
			{
				header('X-Sendfile: ' . $path);
				return;
			}
			else if (stristr($_SERVER['SERVER_SOFTWARE'], 'lighttpd'))
			{
				header('X-Sendfile: ' . $path);
				return;
			}
		}

		// Désactiver gzip
		if (function_exists('apache_setenv'))
		{
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		header(sprintf('Content-Length: %d', $this->size));

		if (@ob_get_length()) {
			@ob_clean();
		}

		flush();

		if (null !== $path) {
			readfile($path);
		}
		else {
			echo $content;
		}
	}

	public function fetch()
	{
		$this->updateIfNeeded();
		return Files::callStorage('fetch', $this);
	}

	public function render(array $options = [])
	{
		$type = $this->type;
		if ($type == self::FILE_TYPE_HTML) {
			return \Garradin\Web\Render\HTML::render($this, null, $options);
		}
		elseif ($type == self::FILE_TYPE_SKRIV) {
			return \Garradin\Web\Render\Skriv::render($this, null, $options);
		}
		elseif ($type == self::FILE_TYPE_ENCRYPTED) {
			return \Garradin\Web\Render\EncryptedSkriv::render($this, null, $options);
		}

		throw new \LogicException('Unknown render type: ' . $type);
	}

	public function checkReadAccess(?Session $session): bool
	{
		$context = $this->context;
		$ref = $this->context_ref;

		// If it's linked to a file, then we want to know what the parent file is linked to
		if ($context == self::CONTEXT_FILE) {
			return $this->parent()->checkReadAccess($session);
		}
		// Web pages and config files are always public
		else if ($context == self::CONTEXT_WEB || $context == self::CONTEXT_CONFIG || $context == self::CONTEXT_SKELETON) {
			return true;
		}

		if (null === $session) {
			return false;
		}

		if ($context == self::CONTEXT_TRANSACTION && $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)) {
			return true;
		}
		// The user can access his own profile files
		else if ($context == self::CONTEXT_USER && $ref == $session->getUser()->id) {
			return true;
		}
		// Only users able to manage users can see their profile files
		else if ($context == self::CONTEXT_USER && $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE)) {
			return true;
		}
		// Only users with right to access documents can read documents
		else if ($context == self::CONTEXT_DOCUMENTS && $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_READ)) {
			return true;
		}

		return false;
	}

	public function checkWriteAccess(?Session $session): bool
	{
		$context = $this->context;
		$ref = $this->context_ref;

		if (null === $session) {
			return false;
		}

		// If it's linked to a file, then we want to know what the parent file is linked to
		if ($context == self::CONTEXT_FILE) {
			return $this->parent()->checkWriteAccess($session);
		}

		switch ($context) {
			case self::CONTEXT_WEB:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);
			case self::CONTEXT_DOCUMENTS:
				// Only admins can delete files
				return $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_WRITE);
			case self::CONTEXT_CONFIG:
				return $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN);
			case self::CONTEXT_TRANSACTION:
				return $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE);
			case self::CONTEXT_SKELETON:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN);
			case self::CONTEXT_USER:
				return $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE);
		}

		return false;
	}

	public function checkDeleteAccess(?Session $session): bool
	{
		$context = $this->context;
		$ref = $this->context_ref;

		if (null === $session) {
			return false;
		}

		// If it's linked to a file, then we want to know what the parent file is linked to
		if ($context == self::CONTEXT_FILE) {
			return $this->parent()->checkDeleteAccess($session);
		}

		switch ($context) {
			case self::CONTEXT_WEB:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);
			case self::CONTEXT_DOCUMENTS:
				// Only admins can delete files
				return $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_ADMIN);
			case self::CONTEXT_CONFIG:
				return $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN);
			case self::CONTEXT_TRANSACTION:
				return $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_ADMIN);
			case self::CONTEXT_SKELETON:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN);
			case self::CONTEXT_USER:
				return $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE);
		}

		return false;
	}

	static public function getPath(string $context, ?string $ref, ?string $name = null): string
	{
		$path = $context;

		if ($ref) {
			$path .= '/' . $ref;
		}

		if ($name) {
			$path .= '/' . $name;
		}

		return $path;
	}

	public function path(): string
	{
		return self::getPath($this->context, $this->context_ref, $this->name);
	}

	public function updateIfNeeded(?\SplFileInfo $info = null): void
	{
		// Update metadata
		if ($info && $info->getMTime() <= $this->modified->getTimestamp()) {
			return;
		}
		elseif (($modified = Files::callStorage('modified', $this)) && $modified <= $this->modified->getTimestamp()) {
			return;
		}

		$this->set('modified', new \DateTime('@' . ($info ? $info->getMTime() : $modified)));
		$this->set('hash', Files::callStorage('hash', $this));
		$this->set('size', $info ? $info->getSize() : Files::callStorage('size', $this));
		$this->save();
	}

	/**
	 * Create a file in DB from an existing file in the local filesysteme
	 */
	static public function createFromExisting(string $path, string $root, ?\SplFileInfo $info = null): File
	{
		list($context, $ref, $name) = self::validatePath($path);
		$fullpath = $root . DIRECTORY_SEPARATOR . $path;

		$file = File::create($name, $context, $ref, $fullpath);

		$file->set('hash', sha1_file($fullpath));
		$file->set('size', $info ? $info->getSize() : filesize($fullpath));
		$file->set('modified', new \DateTime('@' . ($info ? $info->getMTime() : filemtime($fullpath))));
		$file->set('created', $file->get('modified'));

		$file->save();

		return $file;
	}

	public function checkContext(string $context, $ref): bool
	{
		return ($this->context === $context) && ($this->context_ref == $ref);
	}

	public function parent(): ?File
	{
		if (null === $this->_parent && $this->context == self::CONTEXT_FILE) {
			$this->_parent = Files::get((int) $this->context_ref);
		}

		return $this->_parent;
	}

	public function isPublic(): bool
	{
		if ($this->context == self::CONTEXT_FILE) {
			$context = $this->parent()->context;
		}
		else {
			$context = $this->context;
		}

		if ($context == self::CONTEXT_CONFIG || $context == self::CONTEXT_WEB) {
			return true;
		}

		return false;
	}

	public function getEditor(): ?string
	{
		if ($this->type == self::FILE_TYPE_SKRIV) {
			return self::EDITOR_WEB;
		}
		elseif ($this->type == self::FILE_TYPE_ENCRYPTED) {
			return self::EDITOR_ENCRYPTED;
		}
		elseif (substr($this->type, 0, 5) == 'text/') {
			return self::EDITOR_CODE;
		}

		return null;
	}

	public function canPreview(): bool
	{
		static $types = [
			'application/pdf',
			'audio/mpeg',
			'audio/ogg',
			'audio/wave',
			'audio/wav',
			'audio/x-wav',
			'audio/x-pn-wav',
			'audio/webm',
			'video/webm',
			'video/ogg',
			'application/ogg',
			'video/mp4',
			'text/plain',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			self::FILE_TYPE_SKRIV,
			self::FILE_TYPE_ENCRYPTED,
			self::FILE_TYPE_HTML,
		];

		return in_array($this->type, $types);
	}

	static public function validatePath(string $path): array
	{
		$path = explode('/', $path);

		if (count($path) < 2) {
			throw new ValidationException('Invalid file path');
		}

		if (!array_key_exists($path[0], self::CONTEXTS_NAMES)) {
			throw new ValidationException('Chemin invalide');
		}

		$context = array_shift($path);

		foreach ($path as $part) {
			if (!preg_match('!^[\w\d_-]+(?:\.[\w\d_-]+)*$!i', $part)) {
				throw new ValidationException('Chemin invalide');
			}
		}

		$name = array_pop($path);
		$ref = implode('/', $path);
		return [$context, $ref ?: null, $name];
	}
}
