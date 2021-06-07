<?php
namespace Garradin;

use Garradin\Entities\Files\File;
use Garradin\Files\Files;

require __DIR__ . '/../../_inc.php';

$file = Files::get(qg('p'));

if (!$file) {
	throw new UserException('Fichier inconnu');
}

if (!$file->checkWriteAccess($session)) {
	throw new UserException('Vous n\'avez pas le droit de modifier ce fichier.');
}

$editor = $file->editorType();
$csrf_key = 'edit_file_' . $file->pathHash();

$form->runIf('content', function () use ($file) {
	$file->setContent(f('content'));

	if (qg('js') !== null) {
		die('{"success":true}');
	}

}, $csrf_key, Utils::getSelfURI());

$tpl->assign('file', $file);

if (!$editor) {
	$tpl->assign('file', $file);
	$tpl->display('common/file_upload.tpl');
}
else {
	$content = $file->fetch();
	$tpl->assign(compact('csrf_key', 'content'));
	$tpl->display(sprintf('common/files/edit_%s.tpl', $editor));
}
