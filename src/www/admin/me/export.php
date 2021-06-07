<?php
namespace Garradin;

use Garradin\Services\Services_User;
use Garradin\Files\Files;
use Garradin\Entities\Files\File;

use KD2\ZipWriter;

require_once __DIR__ . '/../_inc.php';

$data = $session->getUser();
$champs = Config::getInstance()->get('champs_membres');
$champs_list = $champs->getList();

$services_list = Services_User::perUserList($user->id);
$services_list->setPageSize(null);

$export_data = [
	'user' => $data,
	'services' => $services_list->asArray(),
];

$tpl->assign(compact('champs_list', 'data', 'services_list'));

$name = sprintf('%s - Donnees - %s.zip', Config::getInstance()->get('nom_asso'), $data->identite);
header('Content-type: application/zip');
header(sprintf('Content-Disposition: attachment; filename="%s"', $name));

$zip = new ZipWriter('php://output');
$zip->setCompression(0);


$zip->add('info.html', $tpl->fetch('me/export.tpl'));
$zip->add('info.json', json_encode($export_data));

foreach (Files::listForContext(File::CONTEXT_USER, $data->id) as $dir) {
	foreach (Files::list($dir->path) as $file) {
		$zip->add($file->path, null, $file->fullpath());
	}
}

$zip->close();