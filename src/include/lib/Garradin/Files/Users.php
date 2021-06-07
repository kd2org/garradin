<?php

namespace Garradin\Files;

use Garradin\Entities\Files\File;
use Garradin\DynamicList;
use Garradin\Config;

class Users
{
	const LIST_COLUMNS = [
		'number' => [
			'select' => 'm.numero',
			'label' => 'Numéro',
		],
		'identity' => [
			'select' => '',
			'label' => '',
		],
		'path' => [
		],
		'id' => [
			'label' => null,
			'select' => 'm.id',
		],
	];

	static public function list()
	{
		Files::syncVirtualTable(File::CONTEXT_USER);

		$config = Config::getInstance();
		$name_field = $config->get('champ_identite');
		$champs = $config->get('champs_membres');

		$columns = self::LIST_COLUMNS;
		$columns['identity']['select'] = 'm.' . $name_field;
		$columns['identity']['label'] = $champs->get($name_field)->title;

		$tables = sprintf('%s f INNER JOIN membres m ON m.id = f.name', Files::getVirtualTableName());

		$sum = 0;

		// Only fetch directories with an ID as the name
		$conditions = sprintf('f.parent = \'%s\' AND f.type = %d AND printf("%%d", f.name) = name', File::CONTEXT_USER, File::TYPE_DIRECTORY);

		$list = new DynamicList($columns, $tables, $conditions);
		$list->orderBy('number', false);
		$list->setCount('COUNT(DISTINCT m.id)');

		return $list;
	}
}