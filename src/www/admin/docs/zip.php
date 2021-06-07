<?php

namespace Garradin;

use Garradin\Files\Files;
use Garradin\Files\Transactions;
use Garradin\Files\Users;
use Garradin\Entities\Files\File;

require_once __DIR__ . '/_inc.php';

$path = trim(qg('p')) ?: File::CONTEXT_DOCUMENTS;

$name = preg_replace('/[^\p{L}_-]+/i', '_', $path);
$name = sprintf('%s - Fichiers - %s.zip', Config::getInstance()->get('nom_asso'), $name);
header('Content-type: application/zip');
header(sprintf('Content-Disposition: attachment; filename="%s"', $name));

Files::zip($path, $session);
