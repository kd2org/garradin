{include file="admin/_head.tpl" title="Choisir la page parent" current="web"}

<table class="tree-selector list">
	<tbody>
		<tr{if !$parent} class="focused"{/if}>
			<td><input type="button" value="Choisir" data-path="" data-label="Racine du site" /></td>
			<th><h3><a href="?current={$selected}">Racine du site</a></h3></th>
		</tr>
		<?php $last = 1; ?>
		{foreach from=$breadcrumbs item="_title" key="_path"}
		<tr{if $_path == $parent} class="focused"{/if}>
			<td><input type="button" value="Choisir" data-path="{$_path}" data-label="{$_title}" /></td>
			<th><?=str_repeat('<i>&nbsp;</i>', $iteration)?> <b class="icn">&rarr;</b> <a href="?parent={$_path}&amp;current={$selected}">{$_title}</a></th>
			<?php $last = $iteration; ?>
		</tr>
		{/foreach}
		{foreach from=$categories item="cat"}
		<tr{if $cat.path == $parent} class="focused"{/if}>
			<td><input type="button" value="Choisir" data-path="{$cat.path}" data-label="{$cat.title}" /></td>
			<th><?=str_repeat('<i>&nbsp;</i>', $last+1)?> <b class="icn">&rarr;</b> <a href="?parent={$cat.path}&amp;current={$selected}">{$cat.title}</a></th>
		</tr>
		{foreachelse}
		<tr>
			<td></td>
			<th><?=str_repeat('<i>&nbsp;</i>', $last+1)?> <b class="icn">&rarr;</b> <em>Pas de sous-catégorie…</em></th>
		</tr>
		{/foreach}
	</tbody>
</table>

{literal}
<script type="text/javascript">
var buttons = document.querySelectorAll('input');

buttons.forEach((e) => {
	e.onclick = () => {
		window.parent.g.inputListSelected(e.dataset.path, e.dataset.label);
	};
});
</script>
{/literal}

{include file="admin/_foot.tpl"}