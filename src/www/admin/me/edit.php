<?php
namespace Garradin;

require_once __DIR__ . '/../_inc.php';

$csrf_key = 'edit_my_info';

$form->runIf('save', function () use ($session) {
	$data = [];
	$config = Config::getInstance();
	$champs = Config::getInstance()->get('champs_membres');

	foreach ($champs->getAll() as $key=>$c) {
		if (!empty($c->editable)) {
			$data[$key] = f($key);
		}
	}

	if (isset($data[$config->get('champ_identifiant')]) && !trim($data[$config->get('champ_identifiant')]) && $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)) {
		throw new UserException("Le champ identifiant ne peut être vide pour un administrateur, sinon vous ne pourriez plus vous connecter.");
	}

	$session->editUser($data);
}, $csrf_key, '!me/?ok');

$data = $session->getUser();
$champs = Config::getInstance()->get('champs_membres')->getAll();

$tpl->assign(compact('csrf_key', 'champs', 'data'));

$tpl->display('me/edit.tpl');
