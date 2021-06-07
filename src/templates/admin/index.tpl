{include file="admin/_head.tpl" title="Bonjour %s !"|args:$user.identite current="home"}

{$banner|raw}

<nav class="tabs">
	<ul>
		<li><a href="{$admin_url}me/">Modifier mes informations personnelles</a></li>
		<li><a href="{$admin_url}me/services.php">Suivi de mes activités et cotisations</a></li>
	</ul>
</nav>

<aside class="describe">
	<h3>{$config.nom_asso}</h3>
	{if !empty($config.adresse_asso)}
	<p>
		{$config.adresse_asso|escape|nl2br}
	</p>
	{/if}
	{if !empty($config.telephone_asso)}
	<p>
		Tél. : <a href="tel:{$config.telephone_asso}">{$config.telephone_asso}</a>
	</p>
	{/if}
	{if !empty($config.email_asso)}
	<p>
		E-Mail : <a href="mailto:{$config.email_asso}">{$config.email_asso}</a>
	</p>
	{/if}
	{if !empty($config.site_asso)}
	<p>
		Web : <a href="{$config.site_asso}" target="_blank">{$config.site_asso}</a>
	</p>
	{/if}
</aside>

{if $homepage}
	<article class="web-content">
		{$homepage|raw}
	</article>
{/if}

{include file="admin/_foot.tpl"}