<?php
namespace Garradin;

use Garradin\Entities\Services\Reminder;
use Garradin\Services\Reminders;
use Garradin\Services\Services;

require_once __DIR__ . '/../_inc.php';

$user_id = (int) qg('id');
$list = Reminders::listSentForUser($user_id);

$tpl->assign(compact('list', 'user_id'));

$tpl->display('services/reminders/user.tpl');
