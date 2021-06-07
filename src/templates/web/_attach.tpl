{include file="admin/_head.tpl" title="Inclure un fichier"}

{form_errors}

<form method="post" enctype="multipart/form-data" action="{$self_url}" id="f_upload">
	<fieldset>
		<legend>Téléverser un fichier</legend>
		<dl>
			{input type="file" name="file" label="Fichier" required=true}
		</dl>
		<p class="submit">
			{csrf_field key=$csrf_key}
			{button type="submit" name="upload" label="Envoyer le fichier" shape="upload" class="main" id="f_submit"}
		</p>
	</fieldset>
</form>

<form method="get" action="#" style="display: none;" id="insertImage">
	<fieldset>
		<h3>Insérer une image dans le texte</h3>
		<dl>
			<dd class="image"></dd>
			<dt>Légende <i>(facultatif)</i></dt>
			<dd class="caption">
				<input type="text" name="f_caption" size="50" />
			</dd>
			<dt>Alignement&nbsp;:</dt>
			<dd class="align">
				<input type="button" name="left" value="À gauche" />
				<input type="button" name="center" value="Au centre" />
				<input type="button" name="right" value="À droite" />
			</dd>
			<dd class="cancel">
				<input type="reset" value="Annuler" />
			</dd>
		</dl>
	</fieldset>
</form>

{if !empty($images)}
<ul class="gallery">
{foreach from=$images item="file"}
	<li>
		<figure>
			<a href="{$file->url()}" data-name="{$file.name}" data-insert="image" data-thumb="{$file->thumb_url()}"><img src="{$file->thumb_url()}" alt="" title="{$file.name}" /></a>
			<figcaption>
				<a href="{$file->url()}" data-name="{$file.name}" data-insert="image" data-thumb="{$file->thumb_url()}">{$file.name}</a>
			</figcaption>
			<form class="actions" method="post" action="{$self_url}">
				{linkbutton shape="download" label="Télécharger" href=$file->url() target="_blank"}
				{linkbutton shape="plus" label="Insérer" href=$file->url() data-name=$file.name data-insert="image" data-thumb=$file->thumb_url()}
				{csrf_field key=$csrf_key}
				<input type="hidden" name="delete" value="{$file.name}" />
				<noscript><input type="submit" value="Supprimer" /></noscript>
			</form>
		</figure>
	</li>
{/foreach}
</ul>
{/if}

{if !empty($files)}
<table class="list">
	<tbody>
	{foreach from=$files item="file"}
		<tr>
			<th>{$file.name}</th>
			<td>{$file.type}, {$file.size|size_in_bytes}</td>
			<td class="actions">
				<form class="actions" method="post" action="{$self_url}">
					{linkbutton shape="plus" label="Insérer" href=$file->url() data-name=$file.name data-insert="file"}
					{linkbutton shape="download" label="Télécharger" href=$file->url() target="_blank"}
					{csrf_field key=$csrf_key}
					<input type="hidden" name="delete" value="{$file.name}" />
					<noscript><input type="submit" value="Supprimer" /></noscript>
				</form>
			</td>
		</tr>
	{/foreach}
	</tbody>
</table>
{/if}

{include file="admin/_foot.tpl"}