{include file="admin/_head.tpl" title="Rappels envoyés à un membre" current="membres/services"}

<nav class="tabs">
	<ul>
		<li><a href="{$admin_url}membres/fiche.php?id={$user_id}">Fiche membre</a></li>
		<li class="current"><a href="{$self_url}">Liste des rappels envoyés</a></li>
	</ul>
</nav>

{include file="common/dynamic_list_head.tpl"}

	{foreach from=$list->iterate() item="row"}
		<tr>
			<th>{$row.label}</th>
			<td>{if $row.delay > 0}{$row.delay} jours après l'expiration{elseif $row.delay < 0}{$row.delay|abs} jours avant l'expiration{else}le jour de l'expiration{/if}</td>
			<td>{$row.date|date_short}</td>
			<td></td>
		</tr>
	{/foreach}

	</tbody>
</table>

{pagination url=$list->paginationURL() page=$list.page bypage=$list.per_page total=$list->count()}


{include file="admin/_foot.tpl"}