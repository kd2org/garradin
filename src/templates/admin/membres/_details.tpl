<?php
assert(isset($data, $champs, $show_message_button));
$user_files_path = (new Membres)->getAttachementsDirectory($data->id);
?>

<dl class="describe">
	{foreach from=$champs key="c" item="c_config"}
	<?php
	// Skip private fields from "my info" page
	if ($mode == 'user' && $c_config->private) {
		continue;
	}

	// Skip files from export
	if ($mode == 'export' && $c_config->type == 'file') {
		continue;
	}

	$value = $data->$c ?? null;
	?>
	<dt>{$c_config.title}</dt>
	<dd>
		{if $c_config.type == 'checkbox'}
			{if $value}Oui{else}Non{/if}
		{elseif $c_config.type == 'file'}
			<?php
			$edit = ($c_config->editable || $mode == 'edit');
			?>
			{include file="common/files/_context_list.tpl" limit=1 path="%s/%s"|args:$user_files_path,$c}
		{elseif empty($value)}
			<em>(Non renseigné)</em>
		{elseif $c == $c_config.champ_identite}
			<strong>{$value}</strong>
		{elseif $c_config.type == 'email'}
			<a href="mailto:{$value|escape:'url'}">{$value}</a>
			{if $c == 'email' && $show_message_button}
				{linkbutton href="!membres/message.php?id=%d"|args:$data.id label="Envoyer un message" shape="mail"}
			{/if}
		{elseif $c_config.type == 'tel'}
			<a href="tel:{$value}">{$value|format_tel}</a>
		{elseif $c_config.type == 'country'}
			{$value|get_country_name}
		{elseif $c_config.type == 'date'}
			{$value|date_short}
		{elseif $c_config.type == 'datetime'}
			{$value|date}
		{elseif $c_config.type == 'password'}
			*******
		{elseif $c_config.type == 'multiple'}
			<ul>
			{foreach from=$c_config.options key="b" item="name"}
				{if $value & (0x01 << $b)}
					<li>{$name}</li>
				{/if}
			{/foreach}
			</ul>
		{else}
			{$value|escape|rtrim|nl2br}
		{/if}
	</dd>
	{/foreach}
</dl>
