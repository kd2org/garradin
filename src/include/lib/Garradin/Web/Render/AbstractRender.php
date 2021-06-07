<?php

namespace Garradin\Web\Render;

use Garradin\Entities\Files\File;
use Garradin\Utils;

use const Garradin\{WWW_URL, ADMIN_URL};

abstract class AbstractRender
{
	protected $current_path;
	protected $context;
	protected $link_prefix;
	protected $link_suffix;

	protected $file;

	public function __construct(?File $file)
	{
		$this->file = $file;

		if ($file) {
			$this->isRelativeTo($file);
		}
	}

	abstract public function render(?string $content = null, array $options = []): string;

	protected function resolveAttachment(string $uri) {
		$prefix = $this->current_path;
		$pos = strpos($uri, '/');

		// "Image.jpg"
		if ($pos === false) {
			return WWW_URL . $prefix . '/' . $uri;
		}
		// "bla/Image.jpg" outside of web context
		elseif ($this->context !== File::CONTEXT_WEB && $pos !== 0) {
			return WWW_URL . $this->context . '/' . $uri;
		}
		// "bla/Image.jpg" in web context or absolute link, eg. "/transactions/2442/42.jpg"
		else {
			return WWW_URL . ltrim($uri, '/');
		}
	}

	protected function resolveLink(string $uri) {
		$first = substr($uri, 0, 1);
		if ($first == '/' || $first == '!') {
			return Utils::getLocalURL($uri);
		}

		if (strpos(Utils::basename($uri), '.') === false) {
			$uri .= $this->link_suffix;
		}

		return $this->link_prefix . $uri;
	}

	public function isRelativeTo(File $file) {
		$this->current_path = Utils::dirname($file->path);
		$this->context = strtok($this->current_path, '/');
		$this->link_suffix = '';

		if ($this->context === File::CONTEXT_WEB) {
			$this->link_prefix = WWW_URL;
			$this->current_path = Utils::basename(Utils::dirname($file->path));
		}
		else {
			$this->link_prefix = $options['prefix'] ?? sprintf(ADMIN_URL . 'common/files/preview.php?p=%s/', $this->context);
			$this->link_suffix = '.skriv';
		}
	}
}