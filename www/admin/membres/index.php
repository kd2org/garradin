<?php
namespace Garradin;

require_once __DIR__ . '/../_inc.php';

if ($user['droits']['membres'] < Membres::DROIT_ACCES)
{
    throw new UserException("Vous n'avez pas le droit d'accéder à cette page.");
}

$cats = new Membres_Categories;
$membres_cats = $cats->listSimple();
$membres_cats_cachees = $cats->listHidden();

$cat = (int) utils::get('cat') ?: 0;
$page = (int) utils::get('p') ?: 1;

$search_query = trim(utils::get('search_query')) ?: '';

if ($search_query)
{
    if (is_numeric(trim($search_query))) {
        $search_field = 'id';
    }
    elseif (strpos($search_query, '@') !== false) {
        $search_field = 'email';
    }
    else {
        $search_field = 'nom';
    }

    $tpl->assign('liste', $membres->search($search_field, $search_query));
    $tpl->assign('total', -1);
    $tpl->assign('pagination_url', utils::getSelfUrl() . '?p=[ID]');
}
else
{
    if (!$cat)
    {
        $cat_id = array_diff(array_keys($membres_cats), array_keys($membres_cats_cachees));
    }
    else
    {
        $cat_id = (int) $cat;
    }

    $order = 'nom';
    $desc = false;

    if (utils::get('o'))
        $order = utils::get('o');

    if (isset($_GET['d']))
        $desc = true;

    $tpl->assign('order', $order);
    $tpl->assign('desc', $desc);

    $tpl->assign('liste', $membres->listByCategory($cat_id, $page, $order, $desc));
    $tpl->assign('total', $membres->countByCategory($cat_id));

    $tpl->assign('pagination_url', utils::getSelfUrl(true) . '?p=[ID]&amp;o=' . $order . ($desc ? '&amp;d' : ''));
}

$tpl->assign('membres_cats', $membres_cats);
$tpl->assign('membres_cats_cachees', $membres_cats_cachees);
$tpl->assign('current_cat', $cat);

$tpl->assign('page', $page);
$tpl->assign('bypage', Membres::ITEMS_PER_PAGE);

$tpl->assign('search_query', $search_query);

$tpl->display('admin/membres/index.tpl');

?>