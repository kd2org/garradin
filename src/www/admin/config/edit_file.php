<?php
namespace Garradin;

use Garradin\Entities\Files\File;
use Garradin\Files\Files;

require __DIR__ . '/_inc.php';

$key = qg('k');

if (!isset(Config::DEFAULT_FILES[$key])) {
	throw new UserException('Fichier invalide');
}

$file_path = Config::DEFAULT_FILES[$key];

$file = Files::get($file_path);

if (!$file) {
	$file = File::create(Utils::dirname($file_path), Utils::basename($file_path), null, '');
	$content = '';
}
else {
	$content = $file->fetch();
}

$editor = $file->editorType();
$csrf_key = 'edit_file_' . $file->pathHash();

$form->runIf('save', function () use ($file, $key) {
	// For config files, make sure config value is updated
	$config = Config::getInstance();

	if (trim(f('content')) === '') {
		$file->delete();
		$config->set($key, null);
		$config->save();
	}
	else {
		$file->setContent(f('content'));
		$config->set($key, $file->path);
		$config->save();
	}

	if (qg('js') !== null) {
		die('{"success":true}');
	}

}, $csrf_key, Utils::getSelfURI());

$tpl->assign('file', $file);

$tpl->assign(compact('csrf_key', 'content'));
$tpl->display(sprintf('common/files/edit_%s.tpl', $editor));
