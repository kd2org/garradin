<?php

namespace Garradin;

use Garradin\Web\Web;
use Garradin\Entities\Web\Page;
use Garradin\Entities\Files\File;
use KD2\SimpleDiff;

require_once __DIR__ . '/_inc.php';

$session->requireAccess($session::SECTION_WEB, $session::ACCESS_WRITE);

$page = Web::get(qg('p'));

if (!$page) {
	throw new UserException('Page inconnue');
}

if (qg('new') !== null && empty($_POST)) {
	$page->set('status', Page::STATUS_ONLINE);
}

$csrf_key = 'web_edit_' . $page->id();

$editing_started = f('editing_started') ?: $page->modified->getTimestamp();

if (f('cancel')) {
	Utils::redirect(ADMIN_URL . 'web/?parent=' . $page->parent);
}

$show_diff = false;

$form->runIf('save', function () use ($page, $editing_started, &$show_diff) {
	if ($editing_started < $page->modified->getTimestamp()) {
		$show_diff = true;
		http_response_code(400);
		throw new UserException('La page a été modifiée par quelqu\'un d\'autre pendant que vous éditiez le contenu.');
	}

	$page->importForm();

	$page->save();

	if (qg('js') !== null) {
		die('{"success":true}');
	}

	Utils::redirect('!web/?p=' . $page->parent);
}, $csrf_key);

$parent = $page->parent ? [$page->parent => Web::get($page->parent)->title] : ['' => 'Racine du site'];
$encrypted = f('encrypted') || $page->format == Page::FORMAT_ENCRYPTED;

$old_content = f('content');
$new_content = $page->content;

$formats = $page::FORMATS_LIST;

$tpl->assign(compact('page', 'parent', 'editing_started', 'encrypted', 'csrf_key', 'old_content', 'new_content', 'show_diff', 'formats'));

$tpl->assign('custom_js', ['wiki_editor.js', 'wiki-encryption.js']);
$tpl->assign('custom_css', ['wiki.css']);

$tpl->display('web/edit.tpl');
