{include file="admin/_head.tpl" current="config" custom_css=['config.css']}

{include file="admin/config/_menu.tpl" current="fiches_membres"}

{if isset($status) && $status == 'OK'}
    <p class="block confirm">
        La configuration a bien été enregistrée.
    </p>
{elseif isset($status) && $status == 'ADDED'}
    <p class="block alert">
        Le champ a été ajouté à la fin de la liste. Pour vérifier et sauvegarder les modifications de la fiche membre cliquer sur le bouton «&nbsp;Vérifier les changements&nbsp;» en base de page.
    </p>
{/if}

{form_errors}

{if $review}
    <p class="help">
        Voici ce à quoi ressemblera la nouvelle fiche de membre, vérifiez vos modifications avant d'enregistrer les changements.
    </p>
    <p class="block alert">
        Attention&nbsp;! Si vous avez supprimé un champ, les données liées à celui-ci seront supprimées de toutes les fiches de tous les membres.
    </p>
    <fieldset>
        <legend>Fiche membre exemple</legend>
        <dl>
            {foreach from=$champs item="champ" key="nom"}
                {if $nom == 'passe'}{continue}{/if}
                {html_champ_membre config=$champ name=$nom disabled=true}
                {if empty($champ.editable) || !empty($champ.private)}
                <dd>
                    {if !empty($champ.private)}
                        (Champ caché)
                    {elseif empty($champ.editable)}
                        (Non-modifiable par les membres)
                    {/if}
                </dd>
                {/if}
            {/foreach}
        </dl>
    </fieldset>

    <fieldset id="f_passe">
        <legend>Connexion</legend>
        <dl>
            <dt><label for="f_passe">Mot de passe</label>{if !empty($champs.passe.mandatory)} <b title="(Champ obligatoire)">obligatoire</b>{/if}</dt>
            <dd><input type="password" id="f_passe" disabled="disabled" /></dd>
            {if empty($champs.passe.editable) || !empty($champs.passe.private)}
            <dd>
                {if !empty($champs.passe.private)}
                    (Champ caché)
                {elseif empty($champs.passe.editable)}
                    (Non-modifiable par les membres)
                {/if}
            </dd>
            {/if}
        </dl>
    </fieldset>

    <form method="post" action="{$admin_url}config/membres.php">
        <p class="submit">
            {csrf_field key="config_membres"}
            <input type="hidden" name="champs" value="{$champs|escape:json|escape}" />
            <input type="submit" name="back" value="&larr; Retour à l'édition" class="minor" />
            <input type="submit" name="reset" value="Annuler les changements" class="minor" />
            {button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
        </p>
    </form>
{else}
    <p class="help">
        Cette page vous permet de personnaliser les fiches d'information des membres de l'association.<br />
        <strong>Attention :</strong> Les champs supprimés de la fiche seront effacés de toutes les fiches de tous les membres, et les données qu'ils contenaient seront perdues.
    </p>

    {if !empty($presets)}
    <form method="post" action="{$self_url}">
    <fieldset>
        <legend>Ajouter un champ pré-défini</legend>
        <p>
            <select name="preset" required="required">
                <option></option>
                {foreach from=$presets key="name" item="preset"}
                <option value="{$name}">{$name} &mdash; {$preset.title}</option>
                {/foreach}
            </select>
            {csrf_field key="config_membres"}
            {button type="submit" name="add" label="Ajouter ce champ à la fiche membre" shape="plus"}
        </p>
    </fieldset>
    </form>
    {/if}

<form method="post" action="{$self_url}">
    <fieldset>
        <legend>Ajouter un champ personnalisé</legend>
        <dl>
            <dt><label for="f_name">Nom unique</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd class="help">Ne peut comporter que des lettres minuscules et des tirets bas.</dd>
            <dd><input type="text" name="new" id="f_name" value="{form_field name=new}" required="required" /></dd>
            <dt><label for="f_title">Titre</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd><input type="text" name="new_title" id="f_title" value="{form_field name=new_title}" required="required" /></dd>
            <dt><label for="f_type">Type de champ</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd>
                <select name="new_type" id="f_type" required="required">
                    {foreach from=$types key="type" item="nom"}
                    <option value="{$type}" {form_field name=new_type selected=$type}>{$nom}</option>
                    {/foreach}
                </select>
            </dd>
        </dl>
        <p>
            {csrf_field key="config_membres"}
            {button type="submit" name="add" label="Ajouter ce champ à la fiche membre" shape="plus"}
            <input type="hidden" name="champs" value="{$champs|escape:json|escape}" />
        </p>
    </fieldset>
</form>

<form method="post" action="{$self_url}">
    <div id="orderFields">
        {foreach from=$champs item="champ" key="nom"}
        {if $nom == 'passe'}{continue}{/if}
        <fieldset id="f_{$nom}">
            <legend>{$nom}</legend>
            <dl>
                <dt><label>Type</label></dt>
                <dd><input type="hidden" name="champs[{$nom}][type]" value="{$champ.type}" />{$champ.type|get_type}</dd>
                <dt><label for="f_{$nom}_title">Titre</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
                <dd><input type="text" name="champs[{$nom}][title]" id="f_{$nom}_title" value="{form_field data=$champ name=title}" required="required" /></dd>
                <dt><label for="f_{$nom}_help">Aide</label></dt>
                <dd><input type="text" name="champs[{$nom}][help]" id="f_{$nom}_help" value="{form_field data=$champ name=help}" /></dd>

                <dt><input type="checkbox" name="champs[{$nom}][private]" value="1" {form_field data=$champ name=private checked="1"} id="f_{$nom}_private"/> <label for="f_{$nom}_private">Caché pour les membres</label></dt>
                <dd class="help">Si coché, ce champ ne sera pas visible par les membres dans leur espace personnel. Attention, il apparaîtra quand même sur l'export de données RGPD que le membre peut télécharger, et qui contiendra toutes les données concernant ce membre.</dd>
                <dt><input type="checkbox" name="champs[{$nom}][editable]" value="1" {form_field data=$champ name=editable checked="1"} id="f_{$nom}_editable" /> <label for="f_{$nom}_editable">Modifiable par les membres</label></dt>
                <dd class="help">Si coché, les membres pourront changer cette information depuis leur espace personnel.</dd>
                <dt><label><input type="checkbox" name="champs[{$nom}][mandatory]" value="1" {form_field data=$champ name=mandatory checked="1"} id="f_{$nom}_mandatory" /> <label for="f_{$nom}_mandatory">Champ obligatoire</label></dt>
                <dd class="help">Si coché, ce champ ne pourra rester vide.</dd>

                {if $champ.type == 'select' || $champ.type == 'multiple'}
                    <dt><label>Options disponibles</label></dt>
                    {if $champ.type == 'multiple'}
                        <dd class="help">Attention changer l'ordre des options peut avoir des effets indésirables.</dd>
                    {else}
                        <dd class="help">Attention renommer ou supprimer une option n'affecte pas ce qui a déjà été enregistré dans les fiches des membres.</dd>
                    {/if}
                    <dd>
                        <{if $champ.type == 'multiple'}ol{else}ul{/if} class="options">
                        {if !empty($champ.options)}
                            {foreach from=$champ.options key="key" item="opt"}
                                <li><input type="text" name="champs[{$nom}][options][]" value="{$opt}" /></li>
                            {/foreach}
                        {/if}
                        {if $champ.type == 'select' || empty($champ.options) || count($champ.options) < 32}
                            <li><input type="text" name="champs[{$nom}][options][]" value="" /></li>
                        {/if}
                    </dd>
                {/if}
                <dt><label for="f_{$nom}_list_row">Numéro de colonne dans la liste des membres</label></dt>
                <dd class="help">Laisser vide ou indiquer le chiffre zéro pour que ce champ n'apparaisse pas dans la liste des membres. Inscrire un chiffre entre 1 et 10 pour indiquer l'ordre d'affichage du champ dans le tableau de la liste des membres.</dd>
                <dd><input type="number" id="f_{$nom}_list_row" name="champs[{$nom}][list_row]" min="0" max="10" value="{form_field data=$champ name=list_row}" /></dd>
            </dl>
        </fieldset>
        {/foreach}
    </div>

    <fieldset id="f_passe">
        <legend>Mot de passe</legend>
        <dl>
            {input type="checkbox" name="champs[passe][private]" value="1" default=$champs.passe.private label="Caché pour les membres" help="Si coché, ce champ ne sera pas visible par les membres dans leur espace personnel"}
            {input type="checkbox" name="champs[passe][editable]" value="1" default=$champs.passe.editable label="Modifiable par les membres" help="Si coché, les membres pourront changer cette information depuis leur espace personnel"}
            {input type="checkbox" name="champs[passe][mandatory]" value="1" default=$champs.passe.mandatory label="Champ obligatoire" help="Si coché, ce champ ne pourra rester vide lors de la création d'un membre"}
        </dl>
    </fieldset>

    <p class="submit">
        {csrf_field key="config_membres"}
        {button type="submit" name="reset" label="Annuler les changements" shape="left"}
        {button type="submit" name="review" label="Vérifier les changements" shape="right" class="main"}
        <em class="help">(un récapitulatif sera présenté et une confirmation sera demandée)</em>
    </p>
</form>

<script type="text/javascript">
var champ_identifiant = "f_{$config.champ_identifiant|escape:'js'}";
var champ_identite = "f_{$config.champ_identite|escape:'js'}";

{literal}
(function () {
    if (!document.querySelector || !document.querySelectorAll)
    {
        return false;
    }

    var fields = document.querySelectorAll('#orderFields fieldset');

    for (i = 0; i < fields.length; i++)
    {
        var field = fields[i];
        field.querySelector('dl').classList.toggle('hidden');

        var legend = field.querySelector('legend');

        legend.onclick = function () {
            this.parentNode.querySelector('dl').classList.toggle('hidden');
        }

        legend.className = 'interactive';
        legend.title = 'Cliquer pour modifier ce champ';

        var actions = document.createElement('div');
        actions.className = 'actions';
        field.appendChild(actions);

        var up = document.createElement('a');
        up.className = 'icn up';
        up.innerHTML = '&uarr;';
        up.title = 'Déplacer vers le haut';
        up.onclick = function (e) {
            var field = this.parentNode.parentNode;
            var p = field.previousSibling;
            while (p.nodeType == 3) { p = p.previousSibling; }
            field.parentNode.insertBefore(field, p);
            return false;
        };
        actions.appendChild(up);

        var down = document.createElement('a');
        down.className = 'icn down';
        down.innerHTML = '&darr;';
        down.title = 'Déplacer vers le bas';
        down.onclick = function (e) {
            var field = this.parentNode.parentNode;
            var p = field.nextSibling;

            if (!p.nextSibling)
            {
                field.parentNode.appendChild(field);
            }
            else
            {
                while (p.nodeType == 3) { p = p.nextSibling; }
                p = p.nextSibling;
                while (p.nodeType == 3) { p = p.nextSibling; }
                field.parentNode.insertBefore(field, p);
            }
            return false;
        };
        actions.appendChild(down);

        var edit = document.createElement('a');
        edit.className = 'icn edit';
        edit.innerHTML = '&#x270e;';
        edit.title = 'Modifier ce champ';
        edit.onclick = function (e) {
            this.parentNode.parentNode.querySelector('dl').classList.toggle('hidden');
            return false;
        };
        actions.appendChild(edit);

        if (field.id != champ_identifiant && field.id != 'f_passe' && field.id != champ_identite && field.id != 'f_numero' && field.id != 'f_email')
        {
            var rem = document.createElement('a');
            rem.className = 'icn remove';
            rem.innerHTML = '✘';
            rem.title = 'Enlever ce champ de la fiche';
            rem.onclick = function (e) {
                if (!window.confirm('Êtes-vous sûr de supprimer ce champ des fiches de membre ?'))
                {
                    return false;
                }

                var field = this.parentNode.parentNode;
                this.parentNode.parentNode.querySelector('dl').classList.add('hidden');
                field.classList.toggle('removed');
                window.setTimeout(function () { field.parentNode.removeChild(field); }, 800);
                return false;
            };
            actions.appendChild(rem);
        }

        if (field.querySelector('.options'))
        {
            var options = field.querySelectorAll('.options li');
            var options_nb = options.length;

            if (options[0].parentNode.tagName.toLowerCase() == 'ul')
            {
                // champ select
                for (j = 0; j < options_nb; j++)
                {
                    var remove = document.createElement('input');
                    remove.type = 'button';
                    remove.className = 'icn';
                    remove.value = '-';
                    remove.title = 'Enlever cette option';
                    remove.onclick = function (e) {
                        var p = this.parentNode;
                        p.parentNode.removeChild(p);
                    };
                    options[j].appendChild(remove);
                }
            }

            var add = document.createElement('input');
            add.type = 'button';
            add.className = 'icn add';
            add.value = '+';
            add.title = 'Ajouter une option';
            add.onclick = function (e) {
                var p = this.parentNode.parentNode;
                var options = p.querySelectorAll('li');
                var new_option = this.parentNode.cloneNode(true);
                var btn = new_option.querySelector('input.add');
                new_option.getElementsByTagName('input')[0].value = '';

                if (options.length >= 30)
                {
                    new_option.removeChild(btn);
                }
                else
                {
                    btn.onclick = this.onclick;
                }

                p.appendChild(new_option);
                this.parentNode.removeChild(this);
            };

            options[options_nb - 1].appendChild(add);
        }
    }
}());
{/literal}
</script>
{/if}

{include file="admin/_foot.tpl"}