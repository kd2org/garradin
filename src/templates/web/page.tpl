{include file="admin/_head.tpl" title=$page.title current="web"}

<nav class="tabs">
	{if $page.type == $page::TYPE_CATEGORY}
	<aside>
		{linkbutton shape="plus" label="Nouvelle page" href="new.php?type=%d&parent=%d"|args:$type_page,$page.path}
		{linkbutton shape="plus" label="Nouvelle catégorie" href="new.php?type=%d&parent=%d"|args:$type_category,$page.path}
	</aside>
	{else}
	<aside>
		{linkbutton href="?p=%s&toggle_type"|args:$page.path label="Transformer en catégorie" shape="reset"}
	</aside>
	{/if}
	<ul>
		<li><a href="{$admin_url}web/?p={$page.parent}">Retour à la liste</a></li>
		{if $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE)}
			<li><a href="{$admin_url}web/edit.php?p={$page.path}">Modifier</a></li>
		{/if}
		{if $page.status == $page::STATUS_ONLINE && !$config.site_disabled}
			<li><a href="{$page->url()}" target="_blank">Voir sur le site</a></li>
		{/if}
		{if $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE)}
			<li><a href="{$admin_url}web/delete.php?p={$page.path}">Supprimer</a></li>
		{/if}
	</ul>
</nav>

{if !empty($breadcrumbs)}
<nav class="breadcrumbs">
	<ul>
		<li><a href="{"!web/"|local_url}">Racine du site</a></li>
		{foreach from=$breadcrumbs key="id" item="title"}
			<li><a href="?p={$id}">{$title}</a></li>
		{/foreach}
	</ul>
</nav>
{/if}


{if !$content}
	<p class="block alert">Cette page est vide, cliquez sur « Modifier » pour commencer à rédiger son contenu.</p>
{else}
	{$content|raw}

	{if count($images) || count($files)}
	<div class="wikiFiles">
		<h3>Fichiers liés à cette page</h3>

		{if count($images)}
		<ul class="gallery">
			{foreach from=$images item="file"}
				<li>
					<figure>
						<a class="internal-image" href="{$file->url()}"><img src="{$file->thumb_url()}" alt="" title="{$file.name}" /></a>
					</figure>
				</li>
			{/foreach}
		</ul>
		{/if}

		{if count($files)}
		<ul class="files">
			{foreach from=$files item="file"}
				<li>
					<aside class="fichier" class="internal-file"><a href="{$file->url()}">{$file.name}</a>
					<small>({$file.mime}, {$file.size|size_in_bytes})</small></aside>
			   </li>
			{/foreach}
		</ul>
		{/if}
	</div>
	{/if}

	<p class="wikiFooter">
		Dernière modification le {$page.modified|date_long:true}
	</p>
{/if}


{include file="admin/_foot.tpl"}