<?php
namespace Garradin;

use Garradin\Services\Services_User;

require_once __DIR__ . '/../_inc.php';

$tpl->assign('membre', $user);

$list = Services_User::perUserList($user->id);
$list->loadFromQueryString();

$tpl->assign(compact('list'));

$tpl->assign('services', Services_User::listDistinctForUser($user->id));

$tpl->display('me/services.tpl');
