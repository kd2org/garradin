<noscript>
	<div class="error">
		Vous dever activer javascript pour pouvoir déchiffrer cette page.
	</div>
</noscript>
<script type="text/javascript" src="{$admin_url}static/scripts/wiki-encryption.js"></script>
<div id="wikiEncryptedMessage">
	<p class="block alert">Cette page est chiffrée.
		<input type="button" onclick="return pleaseDecrypt();" value="Entrer le mot de passe" />
	</p>
</div>
<div class="web-content" style="display: none;" id="wikiEncryptedContent">
	{$content}
</div>