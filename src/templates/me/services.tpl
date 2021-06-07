{include file="admin/_head.tpl" title="Mes activités & cotisations" current="me/services"}

<dl class="cotisation">
	<dt>Mes activités et cotisations</dt>
	{foreach from=$services item="service"}
	<dd>
		{$service.label}
		{if $service.status == -1 && $service.end_date} — terminée
		{elseif $service.status == -1} — <b class="error">en retard</b>
		{elseif $service.status == 1 && $service.end_date} — <b class="confirm">en cours</b>
		{elseif $service.status == 1} — <b class="confirm">à jour</b>{/if}
		{if $service.status.expiry_date} — expire le {$service.expiry_date|date_short}{/if}
		{if !$service.paid} — <b class="error">À payer&nbsp;!</b>{/if}
	</dd>
	{foreachelse}
	<dd>
		Vous n'êtes inscrit à aucune activité ou cotisation.
	</dd>
	{/foreach}
</dl>

{if $list->count()}

	<h2 class="ruler">Historique des inscriptions</h2>

	{include file="common/dynamic_list_head.tpl"}

		{foreach from=$list->iterate() item="row"}
			<tr>
				<th>{$row.label}</th>
				<td>{$row.date|date_short}</td>
				<td>{$row.expiry|date_short}</td>
				<td>{$row.fee}</td>
				<td>{if $row.paid}<b class="confirm">Oui</b>{else}<b class="error">Non</b>{/if}</td>
				<td>{$row.amount|raw|money_currency}</td>
				<td class="actions">
				</td>
			</tr>
		{/foreach}

		</tbody>
	</table>

	{pagination url=$list->paginationURL() page=$list.page bypage=$list.per_page total=$list->count()}
{/if}

{include file="admin/_foot.tpl"}