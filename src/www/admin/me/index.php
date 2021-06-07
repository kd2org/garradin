<?php
namespace Garradin;

require_once __DIR__ . '/../_inc.php';

$data = $session->getUser();
$champs = Config::getInstance()->get('champs_membres')->getList();

$ok = qg('ok');

$tpl->assign(compact('champs', 'data', 'ok'));

$tpl->display('me/index.tpl');
