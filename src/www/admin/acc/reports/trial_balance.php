<?php

namespace Garradin;

use Garradin\Accounting\Reports;

require_once __DIR__ . '/_inc.php';

$tpl->assign('balance', Reports::getTrialBalance($criterias));

$tpl->display('acc/reports/trial_balance.tpl');
