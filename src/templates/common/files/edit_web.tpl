{include file="admin/_head.tpl" title="Édition de fichier" custom_js=['wiki_editor.js']}

<form method="post" action="{$self_url}">
	<p class="textEditor">
		{input type="textarea" name="content" cols="70" rows="30" default=$content data-preview-url="!common/files/_preview.php?f=%s"|local_url|args:$file.parent data-fullscreen="1" data-attachments="0" data-savebtn="1" data-format=$file->renderFormat()}
	</p>

	<p class="submit">
		{csrf_field key=$csrf_key}
		{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
	</p>

</form>

{include file="admin/_foot.tpl"}
