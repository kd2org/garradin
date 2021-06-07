<?php

namespace Garradin;

use Garradin\Web\Web;
use Garradin\Entities\Web\Page;

require_once __DIR__ . '/_inc.php';

$current_path = qg('p') ?: '';
$cat = null;

if ($current_path) {
	$cat = Web::get($current_path);

	if (!$cat) {
		throw new UserException('Catégorie inconnue');
	}
}
else {
	foreach (Web::sync() as $error) {
		$form->addError($error);
	}
}

$order_date = qg('order_title') === null;

$categories = Web::listCategories($cat ? $cat->path : '');
$pages = Web::listPages($cat ? $cat->path : '', $order_date);
$title = $cat ? sprintf('Gestion du site web : %s', $cat->title) : 'Gestion du site web';
$type_page = Page::TYPE_PAGE;
$type_category = Page::TYPE_CATEGORY;
$breadcrumbs = $cat ? $cat->getBreadcrumbs() : [];

$parent = $cat ? $cat->parent : null;

$tpl->assign(compact('categories', 'pages', 'title', 'current_path', 'parent', 'type_page', 'type_category', 'order_date', 'breadcrumbs', 'cat'));

$tpl->display('web/index.tpl');
