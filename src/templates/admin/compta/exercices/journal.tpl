{include file="admin/_head.tpl" title="Journal général" current="compta/exercices" body_id="rapport"}

<div class="exercice">
    <h2>{$config.nom_asso}</h2>
    <p>Exercice comptable {if $exercice.cloture}clôturé{else}en cours{/if} du
        {$exercice.debut|date_fr:'d/m/Y'} au {$exercice.fin|date_fr:'d/m/Y'}, généré le {$cloture|date_fr:'d/m/Y'}</p>
</div>

<table class="list multi">
    <thead>
        <tr>
            <td>Date</td>
            <th>Intitulé</th>
            <td>Comptes</td>
            <td>Débit</td>
            <td>Crédit</td>
        </tr>
    </thead>
    <tbody>
    {foreach from=$journal item="ligne"}
        <tr>
            <td rowspan="2">{$ligne.date|date_fr:'d/m/Y'}</td>
            <th rowspan="2">{$ligne.libelle}</th>
            <td>{$ligne.compte_debit} - {$ligne.compte_debit|get_nom_compte}</td>
            <td>{$ligne.montant|escape|html_money}</td>
            <td></td>
        </tr>
        <tr>
            <td>{$ligne.compte_credit} - {$ligne.compte_credit|get_nom_compte}</td>
            <td></td>
            <td>{$ligne.montant|escape|html_money}</td>
        </tr>
    {/foreach}
    </tbody>
</table>

<p class="help">Toutes les opérations sont libellées en {$config.monnaie}.</p>

{include file="admin/_foot.tpl"}