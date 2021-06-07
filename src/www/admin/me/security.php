<?php

namespace Garradin;

require_once __DIR__ . '/../_inc.php';

$confirm = false;

if (f('confirm'))
{
	$form->check('edit_me_security', [
		'passe'       => 'confirmed|min:6',
		'passe_check' => 'required',
	]);

	if (f('passe_check') && !$session->checkPassword(f('passe_check'), $user->passe))
	{
		$form->addError('Le mot de passe fourni ne correspond pas au mot de passe actuel. Merci de bien vouloir renseigner votre mot de passe courant pour confirmer les changements.');
	}
	elseif (f('otp_secret') && f('otp_secret') != 'disable' && !f('code')) {
		$form->addError('Le code OTP est obligatoire');
	}
	elseif (f('code') && !$session->checkOTP(f('otp_secret'), f('code')))
	{
		$form->addError('Le code TOTP entré n\'est pas valide.');
	}

	if (!$form->hasErrors())
	{
		try {
			$data = [
				'clef_pgp' => f('clef_pgp'),
			];

			if (f('passe') && !empty($config->get('champs_membres')->get('passe')->editable))
			{
				$data['passe'] = f('passe');
			}

			if (f('otp_secret') == 'disable')
			{
				$data['secret_otp'] = null;
			}
			elseif (f('otp_secret') !== null)
			{
				$data['secret_otp'] = f('otp_secret');
			}

			$session->editSecurity($data);
			Utils::redirect(ADMIN_URL . 'me/security.php?ok');
		}
		catch (UserException $e)
		{
			$form->addError($e->getMessage());
		}
	}

	$confirm = true;
}
elseif (f('save'))
{
	$form->check('edit_me_security', [
		'passe'       => 'confirmed|min:6',
	]);

	if (f('clef_pgp') && !$session->getPGPFingerprint(f('clef_pgp')))
	{
		$form->addError('Clé PGP invalide : impossible de récupérer l\'empreinte de la clé.');
	}
	
	if (!$form->hasErrors())
	{
		$confirm = true;
	}
}

$tpl->assign('confirm', $confirm);

if (f('otp') == 'generate')
{
	$tpl->assign('otp', $session->getNewOTPSecret());
}
elseif (f('otp') == 'disable')
{
	$tpl->assign('otp', 'disable');
}
elseif (f('otp_secret'))
{
	$tpl->assign('otp', $session->getOTPSecret(f('otp_secret')));
}
else
{
	$tpl->assign('otp', false);
}

$tpl->assign('pgp_disponible', \KD2\Security::canUseEncryption());

$fingerprint = '';

if ($user->clef_pgp)
{
	$fingerprint = $session->getPGPFingerprint($user->clef_pgp, true);
}

$tpl->assign('clef_pgp_fingerprint', $fingerprint);

$tpl->assign('passphrase', Utils::suggestPassword());
$tpl->assign('champs', $config->get('champs_membres')->getAll());

$tpl->assign('membre', $user);
$tpl->assign('ok', qg('ok') !== null);

$tpl->display('me/security.tpl');
