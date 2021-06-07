{include file="admin/_head.tpl" title="Mes informations personnelles" current="me"}

<nav class="tabs">
	<ul>
		<li class="current"><a href="{$admin_url}me/">Mes informations personnelles</a></li>
		<li><a href="{$admin_url}me/security.php">Mot de passe et options de sécurité</a></li>
	</ul>
</nav>

{form_errors membre=1}

<form method="post" action="{$self_url}">

	<fieldset>
		<legend>Informations personnelles</legend>
		<dl>
			{foreach from=$champs item="champ" key="nom"}
			{if empty($champ.private) && $nom != 'passe'}
				{html_champ_membre config=$champ name=$nom data=$data user_mode=true}
			{/if}
			{/foreach}
		</dl>
	</fieldset>

	<fieldset>
		<legend>Changer mon mot de passe</legend>
		<p><a href="{$admin_url}me/security.php">Modifier mon mot de passe ou autres informations de sécurité.</a></p>
	</fieldset>

	<p class="submit">
		{csrf_field key=$csrf_key}
		{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
	</p>

</form>

{include file="admin/_foot.tpl"}