{include file="admin/_head.tpl" title="Édition : %s"|args:$page.title current="web"}

{form_errors}

{if $show_diff}
	<h3>Modifications entre votre version et la nouvelle version</h3>
	{diff old=$old_content new=$new_content}
{/if}

<form method="post" action="{$self_url}" class="web-edit" data-focus="#f_content">

	<fieldset class="wikiMain">
		<legend>Informations générales</legend>
		<dl>
			{input type="text" name="title" source=$page required=true label="Titre"}
			{input type="text" name="uri" default=$page.uri required=true label="Adresse unique URI" help="Utilisée pour désigner l'adresse de la page sur le site. Ne peut comporter que des lettres, des chiffres, des tirets et des tirets bas." pattern="[A-Za-z0-9_-]+"}
			{input type="list" name="parent" label="Catégorie" default=$parent target="web/_selector.php?current=%s&parent=%s"|args:$page.path,$page.parent required=true}
			{input type="datetime" name="date" label="Date" required=true default=$page.published}
			<dt>Statut</dt>
			{input type="radio" name="status" value=$page::STATUS_ONLINE label="En ligne" source=$page}
			{input type="radio" name="status" value=$page::STATUS_DRAFT label="Brouillon" source=$page help="ne sera pas visible sur le site"}
			{input type="select" name="format" options=$formats source=$page label="Format"}
		</dl>
	</fieldset>

	<fieldset class="wikiEncrypt">
		<dl>
			<noscript>
			<dd>Nécessite JavaScript activé pour fonctionner !</dd>
			</noscript>
			<dd>Mot de passe : <i id="encryptPasswordDisplay" title="Chiffrement désactivé">désactivé</i></dd>
			<dd class="help">Le mot de passe n'est ni transmis ni enregistré,
				il n'est pas possible de retrouver le contenu si vous perdez le mot de passe.</dd>
		</dl>
	</fieldset>


	<fieldset class="wikiText">
		<div class="textEditor">
			{input type="textarea" name="content" cols="70" rows="35" default=$new_content data-attachments=1 data-savebtn=2 data-preview-url="!common/files/_preview.php?w=%s"|local_url|args:$page.path data-format="#f_format"}
		</div>
	</fieldset>

	<p class="submit">
		{csrf_field key=$csrf_key}
		<input type="hidden" name="editing_started" value="{$editing_started}" />
		{button type="submit" name="save" label="Enregistrer et fermer" shape="upload" class="main"}
	</p>

</form>

{include file="admin/_foot.tpl"}