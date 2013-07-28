{include file="admin/_head.tpl" title="Comptabilité" current="compta"}

<p class="alert">
    <strong>Attention !</strong>
    La comptabilité est une fonctionnalité en beta,
    il est déconseillé pour le moment de l'utiliser pour la
    comptabilité réelle de votre association.<br />
    Vous êtes cependant encouragé à la tester et à faire part
    de votre retour sur le site de <a href="http://dev.kd2.org/garradin/">Garradin</a>.
</p>

{if $user.droits.compta >= Garradin\Membres::DROIT_ADMIN}
<ul class="actions">
    <li><a href="{$www_url}admin/compta/import.php">Import / export</a></li>
</ul>
{/if}

<p>
    <img src="{$www_url}admin/compta/graph.php?g=recettes_depenses" />
    <img src="{$www_url}admin/compta/graph.php?g=banques_caisses" />
    <img src="{$www_url}admin/compta/graph.php?g=dettes" />
</p>

{include file="admin/_foot.tpl"}