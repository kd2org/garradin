{include file="admin/_head.tpl" title="Tarif : %s — Liste des membres inscrits"|args:$fee.label current="membres/services"}

{include file="services/_nav.tpl" current="index" current_service=$service service_page="index" current_fee=$fee fee_page=$type}

<dl class="cotisation">
	<dt>Nombre de membres trouvés</dt>
	<dd>
		{$list->count()}
		<em class="help">(N'apparaît ici que l'inscription la plus récente de chaque membre.)</em>
		{if $session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN)}
			{linkbutton href="%s&export=csv"|args:$self_url shape="export" label="Export CSV"}
			{linkbutton href="%s&export=ods"|args:$self_url shape="export" label="Export tableur"}
		{/if}
	</dd>
</dl>

<?php
$can_action = $session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN);
?>

{if $can_action}
	<form method="post" action="{"!membres/action.php"|local_url}">
{/if}

{include file="common/dynamic_list_head.tpl" check=$can_action}

	{foreach from=$list->iterate() item="row"}
		<tr>
			{if $can_action}
			<td class="check">{input type="checkbox" name="selected[]" value=$row.id_user}</td>
			{/if}
			<th><a href="../../membres/fiche.php?id={$row.id_user}">{$row.identity}</a></th>
			<td>{if $row.paid}<b class="confirm">Oui</b>{else}<b class="error">Non</b>{/if}</td>
			<td class="money">{$row.paid_amount|raw|money_currency}</td>
			<td>{$row.date|date_short}</td>
			<td class="actions">
				{linkbutton shape="user" label="Toutes les activités de ce membre" href="!services/user.php?id=%d"|args:$row.id_user}
				{linkbutton shape="alert" label="Rappels envoyés" href="!services/reminders/user.php?id=%d"|args:$row.id_user}
			</td>
		</tr>
	{/foreach}

	</tbody>

	{if $can_action}
		{include file="admin/membres/_list_actions.tpl" colspan=5 export=false}
	{/if}

</table>

{if $can_action}
</form>
{/if}

{pagination url=$list->paginationURL() page=$list.page bypage=$list.per_page total=$list->count()}


{include file="admin/_foot.tpl"}