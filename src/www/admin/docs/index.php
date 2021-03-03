<?php

namespace Garradin;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;

require_once __DIR__ . '/_inc.php';

$path = trim(qg('p')) ?: File::CONTEXT_DOCUMENTS;

$files = Files::list($path);

// We consider that the first file has the same rights as the others
if (count($files)) {
	$first = current($files);

	if (!$first->checkReadAccess($session)) {
		throw new UserException('Vous n\'avez pas accès à ce répertoire');
	}

	$can_delete = $first->checkDeleteAccess($session);
	$can_write = $first->checkWriteAccess($session);
}
else {
	$can_delete = $can_write = false;
}

$context = Files::getContext($path);

$tpl->assign(compact('path', 'files', 'can_write', 'can_delete', 'context'));

$tpl->display('docs/index.tpl');