<?php
namespace Garradin;

require_once __DIR__ . '/../_inc.php';

if ($user['droits']['compta'] < Membres::DROIT_ADMIN)
{
    throw new UserException("Vous n'avez pas le droit d'accéder à cette page.");
}

$banque = new Compta\Comptes_Bancaires;

$compte = $banque->get(Utils::get('id'));

if (!$compte)
{
    throw new UserException('Le compte demandé n\'existe pas.');
}

$error = false;

if (!empty($_POST['save']))
{
    if (!Utils::CSRF_check('compta_edit_banque_'.$compte['id']))
    {
        $error = 'Une erreur est survenue, merci de renvoyer le formulaire.';
    }
    else
    {
        try
        {
            $id = $banque->edit($compte['id'], [
                'libelle'       =>  Utils::post('libelle'),
                'banque'        =>  Utils::post('banque'),
                'iban'          =>  Utils::post('iban'),
                'bic'           =>  Utils::post('bic'),
            ]);

            Utils::redirect('/admin/compta/banques/');
        }
        catch (UserException $e)
        {
            $error = $e->getMessage();
        }
    }
}

$tpl->assign('error', $error);

$tpl->assign('compte', $compte);

$tpl->display('admin/compta/banques/modifier.tpl');

?>