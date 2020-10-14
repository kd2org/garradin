{include file="admin/_head.tpl" title="Gestion des plans comptables" current="acc/charts"}

<nav class="tabs">
	<ul>
		<li class="current"><a href="{$admin_url}acc/charts/">Plans comptables</a></li>
		{if $session->canAccess('compta', Membres::DROIT_ADMIN)}
		<li><a href="{$admin_url}acc/charts/import.php">Importer un plan comptable</a></li>
		{/if}
	</ul>
</nav>

{if $_GET.msg == 'OPEN'}
<p class="alert">
	Il n'existe aucun exercice ouvert.
	{if $session->canAccess('compta', Membres::DROIT_ADMIN)}
		Merci d'en <a href="{$admin_url}acc/years/new.php">créer un nouveau</a> pour pouvoir saisir des écritures.
	{/if}
</p>
{/if}

{if count($list)}
	<table class="list">
		<thead>
			<td>Pays</td>
			<th>Libellé</th>
			<td></td>
		</thead>
		<tbody>
			{foreach from=$list item="item"}
				<tr>
					<td>{$item.country|get_country_name}</td>
					<th><a href="{$admin_url}acc/charts/accounts/?id={$item.id}">{$item.label}</a> <em>{if $item.code}(officiel){else}(copie){/if}</em></th>
					<td class="actions">
						{linkbutton shape="star" label="Comptes favoris" href="acc/charts/accounts/?id=%d"|args:$item.id}
						{linkbutton shape="menu" label="Tous les comptes" href="acc/charts/accounts/all.php?id=%d"|args:$item.id}
						{if $session->canAccess('compta', Membres::DROIT_ADMIN)}
							{linkbutton shape="edit" label="Renommer" href="acc/charts/edit.php?id=%d"|args:$item.id}
							{linkbutton shape="export" label="Exporter en CSV" href="acc/charts/export.php?id=%d"|args:$item.id}
							{if empty($item.code)}
								{linkbutton shape="upload" label="Importer" href="acc/charts/import.php?id=%d"|args:$item.id}
								{linkbutton shape="delete" label="Supprimer" href="acc/charts/delete.php?id=%d"|args:$item.id}
							{else}
								{linkbutton shape="reset" label="Remettre à zéro" href="acc/charts/reset.php?id=%d"|args:$item.id}
							{/if}
						{/if}
					</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
{/if}

{if $session->canAccess('compta', Membres::DROIT_ADMIN)}
	<form method="post" action="{$self_url_no_qs}">
		<fieldset>
			<legend>Créer un nouveau plan comptable</legend>
			<dl>
				{input type="select_groups" name="plan" options=$charts_groupped label="Recopier depuis" required=1 default=$from}
				{input type="text" name="label" label="Libellé" required=1}
				{input type="select" name="country" label="Pays" required=1 options=$country_list default=$config.pays}
			</dl>
			<p class="submit">
				<input type="submit" value="Créer &rarr;" />
			</p>
		</fieldset>
	</form>
{/if}

{include file="admin/_foot.tpl"}