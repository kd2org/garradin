<?php

namespace Garradin\Entities\Files;

use KD2\Graphics\Image;
use KD2\DB\EntityManager as EM;

use Garradin\DB;
use Garradin\Entity;
use Garradin\Plugin;
use Garradin\UserException;
use Garradin\ValidationException;
use Garradin\Membres\Session;
use Garradin\Static_Cache;
use Garradin\Utils;
use Garradin\Entities\Web\Page;
use Garradin\Web\Render\Render;

use Garradin\Files\Files;

use const Garradin\{WWW_URL, ENABLE_XSENDFILE};

/**
 * This is a virtual entity, it cannot be saved to a SQL table
 */
class File extends Entity
{
	const TABLE = 'files';

	protected $id;

	/**
	 * Parent directory of file
	 */
	protected $parent;

	/**
	 * File name
	 */
	protected $name;

	protected $path;
	protected $type = self::TYPE_FILE;
	protected $mime;
	protected $size;
	protected $modified;
	protected $image;

	protected $_types = [
		'id'           => '?int',
		'path'         => 'string',
		'parent'       => '?string',
		'name'         => 'string',
		'type'         => 'int',
		'mime'         => '?string',
		'size'         => '?int',
		'modified'     => 'DateTime',
		'image'        => 'int',
	];

	const TYPE_FILE = 1;
	const TYPE_DIRECTORY = 2;
	const TYPE_LINK = 3;

	/**
	 * Tailles de miniatures autorisées, pour ne pas avoir 500 fichiers générés avec 500 tailles différentes
	 * @var array
	 */
	const ALLOWED_THUMB_SIZES = [200, 500];

	const THUMB_CACHE_ID = 'file.thumb.%s.%d';

	const THUMB_SIZE_TINY = 200;
	const THUMB_SIZE_SMALL = 500;

	const CONTEXT_DOCUMENTS = 'documents';
	const CONTEXT_USER = 'user';
	const CONTEXT_TRANSACTION = 'transaction';
	const CONTEXT_CONFIG = 'config';
	const CONTEXT_WEB = 'web';
	const CONTEXT_SKELETON = 'skel';

	const CONTEXTS_NAMES = [
		self::CONTEXT_DOCUMENTS => 'Documents',
		self::CONTEXT_USER => 'Membre',
		self::CONTEXT_TRANSACTION => 'Écriture comptable',
		self::CONTEXT_CONFIG => 'Configuration',
		self::CONTEXT_WEB => 'Site web',
		self::CONTEXT_SKELETON => 'Squelettes',
	];

	const IMAGE_TYPES = [
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/webp',
	];

	const PREVIEW_TYPES = [
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
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/webp',
		'image/svg+xml',
		'text/plain',
		'text/html',
	];

	// https://book.hacktricks.xyz/pentesting-web/file-upload
	const FORBIDDEN_EXTENSIONS = '!^(?:cgi|exe|sh|bash|com|pif|jspx?|js[wxv]|action|do|php(?:s|\d+)?|pht|phtml?|shtml|phar|htaccess|inc|cfml?|cfc|dbm|swf|pl|perl|py|pyc|asp|so)$!i';

	static public function getColumns(): array
	{
		return array_keys((new self)->_types);
	}

	public function selfCheck(): void
	{
		$this->assert($this->type === self::TYPE_DIRECTORY || $this->type === self::TYPE_FILE, 'Unknown file type');
		$this->assert($this->type === self::TYPE_DIRECTORY || $this->size !== null, 'File size must be set');
		$this->assert($this->image === 0 || $this->image === 1, 'Unknown image value');
		$this->assert(trim($this->name) !== '', 'Le nom de fichier ne peut rester vide');
		$this->assert(strlen($this->path), 'Le chemin ne peut rester vide');
		$this->assert(strlen($this->parent) || null === $this->parent, 'Le chemin ne peut rester vide');
	}

	public function context(): string
	{
		return strtok($this->path, '/');
	}

	public function fullpath(): string
	{
		$path = Files::callStorage('getFullPath', $this);

		if (null === $path) {
			throw new \RuntimeException('File does not exist: ' . $this->path);
		}

		return $path;
	}

	public function canPreview(): bool
	{
		return in_array($this->mime, self::PREVIEW_TYPES);
	}

	public function delete(): bool
	{
		Files::callStorage('checkLock');

		// Delete actual file content
		Files::callStorage('delete', $this);

		Plugin::fireSignal('files.delete', ['file' => $this]);

		// clean up thumbnails
		foreach (self::ALLOWED_THUMB_SIZES as $size)
		{
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $size));
		}

		if ($this->exists()) {
			return parent::delete();
		}

		return true;
	}

	public function move(string $target): bool
	{
		return $this->rename($target . '/' . $this->name);
	}

	public function changeFileName(string $new_name): bool
	{
		$new_name = self::filterName($new_name);
		return $this->rename(ltrim($this->parent . '/' . $new_name, '/'));
	}

	public function rename(string $new_path): bool
	{
		self::validatePath($new_path);
		self::validateFileName(Utils::basename($new_path));

		if ($new_path == $this->path || 0 === strpos($new_path . '/', $this->path . '/')) {
			throw new UserException('Impossible de renommer ou déplacer un fichier vers lui-même');
		}

		$return = Files::callStorage('move', $this, $new_path);

		Plugin::fireSignal('files.move', ['file' => $this, 'new_path' => $new_path]);

		return $return;
	}

	public function setContent(string $content): self
	{
		$this->set('modified', new \DateTime);
		$this->store(null, rtrim($content));
		$this->indexForSearch(null, $content);
		return $this;
	}

	public function store(?string $source_path, ?string $source_content, bool $index_search = true): self
	{
		if ($source_path && !$source_content)
		{
			$this->set('size', filesize($source_path));
		}
		else
		{
			$this->set('size', strlen($source_content));
		}

		Files::checkQuota($this->size);

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
					$this->set('size', strlen($source_content));
				}

				unset($i);
			}
			catch (\RuntimeException $e) {
				$this->set('image', 0);
			}
		}

		Files::callStorage('checkLock');

		// If a file of the same name already exists, define a new name
		if (Files::callStorage('exists', $this->path) && !$this->exists()) {
			$pos = strrpos($this->name, '.');
			$new_name = substr($this->name, 0, $pos) . '.' . substr(sha1(random_bytes(16)), 0, 10) . substr($this->name, $pos);
			$this->set('name', $new_name);
		}

		if (!$this->modified) {
			$this->set('modified', new \DateTime);
		}

		if (null !== $source_path) {
			$return = Files::callStorage('storePath', $this, $source_path);
		}
		else {
			$return = Files::callStorage('storeContent', $this, $source_content);
		}

		if (!$return) {
			throw new UserException('Le fichier n\'a pas pu être enregistré.');
		}

		Plugin::fireSignal('files.store', ['file' => $this]);

		if (!$index_search) {
			$this->indexForSearch($source_path, $source_content);
		}

		return $this;
	}

	public function indexForSearch(?string $source_path, ?string $source_content, ?string $title = null): void
	{
		// Store content in search table
		if (substr($this->mime, 0, 5) == 'text/') {
			$content = $source_content !== null ? $source_content : Files::callStorage('fetch', $this);

			if ($this->mime === 'text/html' || $this->mime == 'text/xml') {
				$content = strip_tags($content);
			}
		}
		else {
			$content = null;
		}

		// Only index valid UTF-8
		if (isset($content) && preg_match('//u', $content)) {
			// Truncate content at 150KB
			$content = substr(trim($content), 0, 150*1024);
		}
		else {
			$content = null;
		}

		$db = DB::getInstance();
		$db->preparedQuery('DELETE FROM files_search WHERE path = ?;', $this->path);
		$db->preparedQuery('INSERT INTO files_search (path, title, content) VALUES (?, ?, ?);', $this->path, $title ?? $this->name, $content);
	}

	static public function createAndStore(string $path, string $name, ?string $source_path, ?string $source_content): self
	{
		$file = self::create($path, $name, $source_path, $source_content);

		$file->store($source_path, $source_content);

		return $file;
	}

	static public function createDirectory(string $path, string $name, bool $create_parent = true): self
	{
		$name = self::filterName($name);

		$fullpath = trim($path . '/' . $name, '/');

		self::validatePath($fullpath);
		Files::checkQuota();

		if (Files::callStorage('exists', $fullpath)) {
			throw new ValidationException('Le nom de répertoire choisi existe déjà: ' . $fullpath);
		}

		if ($path !== '' && $create_parent) {
			self::ensureDirectoryExists($path);
		}

		$file = new self;
		$file->set('path', $fullpath);
		$file->set('name', $name);
		$file->set('parent', $path);
		$file->set('type', self::TYPE_DIRECTORY);
		$file->set('image', 0);
		$file->set('modified', new \DateTime);

		Files::callStorage('mkdir', $file);

		Plugin::fireSignal('files.mkdir', ['file' => $file]);

		return $file;
	}

	static public function ensureDirectoryExists(string $path): void
	{
		$db = DB::getInstance();
		$parts = explode('/', $path);
		$tree = '';

		foreach ($parts as $part) {
			$tree = trim($tree . '/' . $part, '/');
			$exists = $db->test(File::TABLE, 'type = ? AND path = ?', self::TYPE_DIRECTORY, $tree);

			if (!$exists) {
				try {
					self::createDirectory(Utils::dirname($tree), Utils::basename($tree), false);
				}
				catch (ValidationException $e) {
					// Ignore when directory already exists
				}
			}
		}
	}

	static public function create(string $path, string $name, ?string $source_path, ?string $source_content): self
	{
		if (!isset($source_path) && !isset($source_content)) {
			throw new \InvalidArgumentException('Either source path or source content should be set but not both');
		}

		self::validateFileName($name);
		self::ensureDirectoryExists($path);

		$finfo = \finfo_open(\FILEINFO_MIME_TYPE);
		$file = new self;
		$file->set('path', $path . '/' . $name);
		$file->set('parent', $path);
		$file->set('name', $name);

		if ($source_path && !$source_content) {
			$file->set('mime', finfo_file($finfo, $source_path));
			$file->set('size', filesize($source_path));
		}
		else {
			$file->set('mime', finfo_buffer($finfo, $source_content));
			$file->set('size', strlen($source_content));
		}

		$file->set('image', (int) in_array($file->mime, self::IMAGE_TYPES));

		// Force empty files as text/plain
		if ($file->mime == 'application/x-empty' && !$file->size) {
			$file->set('mime', 'text/plain');
		}

		return $file;
	}

	/**
	 * Create a file from an encoded base64 string
	 */
	static public function createFromBase64(string $path, string $name, string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		return self::createAndStore($path, $name, null, $content);
	}

	/**
	 * Modify a file from an encoded base64 string
	 */
	public function storeFromBase64(string $encoded_content): self
	{
		$content = base64_decode($encoded_content);
		$this->set('modified', new \DateTime);
		$this->store(null, $content);
		return $this;
	}

	/**
	 * Upload du fichier par POST
	 */
	static public function upload(string $path, string $key): self
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
		$name = self::filterName($name);

		return self::createAndStore($path, $name, $file['tmp_name'], null);
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
				return 'Le fichier excède la taille permise par la configuration.';
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

	public function url(bool $download = false): string
	{
		if ($this->context() == self::CONTEXT_WEB) {
			$path = Utils::basename(Utils::dirname($this->path)) . '/' . Utils::basename($this->path);
		}
		else {
			$path = $this->path;
		}


		$url = WWW_URL . $path;

		if ($download) {
			$url .= '?download';
		}

		return $url;
	}

	public function thumb_url(?int $size = null): string
	{
		$size = $size ? self::_findNearestThumbSize($size) : min(self::ALLOWED_THUMB_SIZES);
		return sprintf('%s?%dpx', $this->url(), $size);
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

		// Only simple files can be served, not directories
		if ($this->type != self::TYPE_FILE) {
			header('HTTP/1.1 404 Not Found', true, 404);
			throw new UserException('Page non trouvée');
		}

		$path = Files::callStorage('getFullPath', $this);
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

		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $width);
		$destination = Static_Cache::getPath($cache_id);

		// La miniature n'existe pas dans le cache statique, on la crée
		if (!Static_Cache::exists($cache_id))
		{
			try {
				if ($path = Files::callStorage('getFullPath', $this)) {
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
	 */
	protected function _serve(?string $path, ?string $content, bool $download = false): void
	{
		if ($this->isPublic()) {
			Utils::HTTPCache(md5($this->path . $this->size . $this->modified->getTimestamp()), $this->modified->getTimestamp());
		}
		else {
			// Disable browser cache
			header('Pragma: private');
			header('Expires: -1');
			header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
		}

		$type = $this->mime;

		// Force CSS mimetype
		if (substr($this->name, -4) == '.css') {
			$type = 'text/css';
		}
		elseif (substr($this->name, -3) == '.js') {
			$type = 'text/javascript';
		}

		if (substr($type, 0, 5) == 'text/') {
			$type .= ';charset=utf-8';
		}

		header(sprintf('Content-Type: %s', $type));
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

		header(sprintf('Content-Length: %d', $path ? filesize($path) : strlen($content)));

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
		return Files::callStorage('fetch', $this);
	}

	public function render(array $options = [])
	{
		$editor_type = $this->renderFormat();

		if ($editor_type == 'text') {
			return sprintf('<pre>%s</pre>', htmlspecialchars($this->fetch()));
		}
		elseif (!$editor_type) {
			throw new \LogicException('Cannot render file of this type');
		}
		else {
			return Render::render($editor_type, $this, $this->fetch(), $options);
		}
	}

	public function checkReadAccess(?Session $session): bool
	{
		// Web pages and config files are always public
		if ($this->isPublic()) {
			return true;
		}

		$context = $this->context();
		$ref = strtok(substr($this->path, strpos($this->path, '/')), '/');

		if (null === $session || !$session->isLogged()) {
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
		if (null === $session) {
			return false;
		}

		switch ($this->context()) {
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
		if (null === $session) {
			return false;
		}

		switch ($this->context()) {
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

	static public function checkCreateAccess(string $path, ?Session $session): bool
	{
		if (null === $session) {
			return false;
		}

		$context = strtok($path, '/');

		switch ($context) {
			case self::CONTEXT_WEB:
				return $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);
			case self::CONTEXT_DOCUMENTS:
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

	public function pathHash(): string
	{
		return sha1($this->path);
	}

	public function isPublic(): bool
	{
		$context = $this->context();

		if ($context == self::CONTEXT_SKELETON || $context == self::CONTEXT_CONFIG || $context == self::CONTEXT_WEB) {
			return true;
		}

		return false;
	}

	static public function filterName(string $name): string
	{
		return preg_replace('/[^\w\d\p{L}_. -]+/iu', '-', $name);
	}

	static public function validateFileName(string $name): void
	{
		if (substr($name[0], 0, 1) === '.') {
			throw new ValidationException('Le nom de fichier ne peut commencer par un point');
		}

		if (strpos($name, "\0") !== false) {
			throw new ValidationException('Nom de fichier invalide');
		}

		$extension = strtolower(substr($name, strrpos($name, '.')));

		if (preg_match(self::FORBIDDEN_EXTENSIONS, $extension)) {
			throw new ValidationException('Extension de fichier non autorisée, merci de renommer le fichier avant envoi.');
		}
	}

	static public function validatePath(string $path): array
	{
		$path = explode('/', $path);

		if (count($path) < 1) {
			throw new ValidationException('Chemin invalide');
		}

		if (!array_key_exists($path[0], self::CONTEXTS_NAMES)) {
			throw new ValidationException('Chemin invalide');
		}

		$context = array_shift($path);

		foreach ($path as $part) {
			if (substr($part, 0, 1) == '.') {
				throw new ValidationException('Chemin invalide');
			}
		}

		$name = array_pop($path);
		$ref = implode('/', $path);
		return [$context, $ref ?: null, $name];
	}

	public function renderFormat(): ?string
	{
		if (substr($this->name, -6) == '.skriv') {
			$format = Render::FORMAT_SKRIV;
		}
		elseif (substr($this->name, -3) == '.md') {
			$format = Render::FORMAT_MARKDOWN;
		}
		else if (substr($this->mime, 0, 5) == 'text/') {
			$format = 'text';
		}
		else {
			$format = null;
		}

		return $format;
	}

	public function editorType(): ?string
	{
		$format = $this->renderFormat();

		if ($format == 'text') {
			return 'code';
		}
		elseif ($format == Render::FORMAT_SKRIV || $format == Render::FORMAT_MARKDOWN) {
			return 'web';
		}

		return null;
	}
}
