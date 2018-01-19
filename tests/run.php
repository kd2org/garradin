<?php

require __DIR__ . '/../src/include/init.php';

if (!empty($_SERVER['argv'][1]))
{
	require $_SERVER['argv'][1];
	exit;
}
else
{
	// Lister et exécuter tous les tests unitaires
	$dir = new RecursiveDirectoryIterator(__DIR__ . '/unit_tests');
	$iterator = new RecursiveIteratorIterator($dir);

	$files = new RegexIterator($iterator, '/^.*\.php$/i', RecursiveRegexIterator::GET_MATCH);
	$list = [];

	foreach ($files as $file)
	{
		$list[] = $file[0];
	}

	natcasesort($list);

	foreach ($list as $file)
	{
		require $file;
	}
}