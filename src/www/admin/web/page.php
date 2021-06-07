<?php

namespace Garradin;

use Garradin\Web\Web;
use Garradin\Entities\Web\Page;

require_once __DIR__ . '/_inc.php';

$page = Web::get(qg('p'));

if (!$page) {
	throw new UserException('Page inconnue');
}

if (qg('toggle_type') !== null) {
	$page->toggleType();
	$page->save();
	Utils::redirect('!web/page.php?p=' . $page->path);
}

$membres = new Membres;

$tpl->assign('breadcrumbs', $page->getBreadcrumbs());

$images = $page->getImageGallery(true);
$files = $page->getAttachmentsGallery(true);

$content = $page->render(['prefix' => ADMIN_URL . 'web/page.php?p=']);

$type_page = Page::TYPE_PAGE;
$type_category = Page::TYPE_CATEGORY;

$tpl->assign(compact('page', 'images', 'files', 'content', 'type_page', 'type_category'));

$tpl->assign('custom_js', ['wiki_gallery.js']);
$tpl->assign('custom_css', ['wiki.css', '!web/css.php']);

$tpl->display('web/page.tpl');
