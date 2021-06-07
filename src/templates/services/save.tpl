{include file="admin/_head.tpl" title="Enregistrer un règlement" current="membres/services"}

{include file="services/_nav.tpl" current="save" fee=null service=null}

<form method="post" action="{$self_url}" data-focus="1">

	{if $user_id && $has_past_services}
	<nav class="tabs">
		<ul class="sub">
			<li{if $current_only} class="current"{/if}><a href="?user={$user_id}">Inscrire à une activité courante</a></li>
			<li{if !$current_only} class="current"{/if}><a href="?user={$user_id}&amp;past_services=1">Inscrire à une activité passée</a></li>
		</ul>
	</nav>
	{/if}

{form_errors}
	<fieldset>
		<legend>Inscrire un membre à une activité</legend>

{if !$user_id}
		<dl>
			{input type="list" name="user" required=1 label="Sélectionner un membre" default=$selected_user target="membres/selector.php"}
		</dl>
{else}
		<dl>
			<dt>Membre sélectionné</dt>
			<dd><h3>{$user_name}</h3><input type="hidden" name="id_user" value="{$user_id}" /></dd>
			<dt><label for="f_service_ID">Activité</label> <b>(obligatoire)</b></dt>

		{foreach from=$grouped_services item="service"}
			<dd class="radio-btn">
				{input type="radio" name="id_service" value=$service.id data-duration=$service.duration data-expiry=$service.expiry_date|date_short label=null}
				<label for="f_id_service_{$service.id}">
					<div>
						<h3>{$service.label}</h3>
						<p>
							{if $service.duration}
								{$service.duration} jours
							{elseif $service.start_date}
								du {$service.start_date|date_short} au {$service.end_date|date_short}
							{else}
								ponctuelle
							{/if}
						</p>
						{if $service.description}
						<p class="help">
							{$service.description|escape|nl2br}
						</p>
						{/if}
					</div>
				</label>
			</dd>
		{foreachelse}
			<dd><p class="error block">Aucune activité trouvée</p></dd>
		{/foreach}

		</dl>

		{foreach from=$grouped_services item="service"}
		<?php if (!count($service->fees)) { continue; } ?>
		<dl data-service="s{$service.id}">
			<dt><label for="f_fee">Tarif</label> <b>(obligatoire)</b></dt>
			{foreach from=$service.fees key="service_id" item="fee"}
			<dd class="radio-btn">
				{input type="radio" name="id_fee" value=$fee.id data-user-amount=$fee.user_amount data-account=$fee.id_account data-year=$fee.id_year label=null}
				<label for="f_id_fee_{$fee.id}">
					<div>
						<h3>{$fee.label}</h3>
						<p>
							{if !$fee.user_amount}
								prix libre ou gratuit
							{elseif $fee.user_amount && $fee.formula}
								<strong>{$fee.user_amount|raw|money_currency}</strong> (montant calculé)
							{elseif $fee.user_amount}
								<strong>{$fee.user_amount|raw|money_currency}</strong>
							{/if}
						</p>
						{if $fee.description}
						<p class="help">
							{$fee.description|escape|nl2br}
						</p>
						{/if}
					</div>
				</label>
			</dd>
			{/foreach}
		</dl>
		{/foreach}
	</fieldset>

	<fieldset>
		<legend>Détails</legend>
		<dl>
			{input type="date" name="date" required=1 default=$today label="Date d'inscription"}
			{input type="date" name="expiry_date" label="Date d'expiration de l'inscription"}
			{input type="checkbox" name="paid" value="1" default="1" label="Marquer cette inscription comme payée"}
			<dd class="help">Décocher cette case pour pouvoir suivre les règlements de personnes qui payent en plusieurs fois. Il sera possible de cocher cette case lorsque le solde aura été réglé.</dd>
		</dl>
	</fieldset>

	<fieldset class="accounting">
		<legend>{input type="checkbox" name="create_payment" value=1 default=1 label="Enregistrer en comptabilité"}</legend>

		<dl>
			{input type="money" name="amount" label="Montant réglé par le membre" fake_required=1 help="En cas de règlement en plusieurs fois il sera possible d'ajouter des règlements via la page de suivi des activités de ce membre."}
			{input type="list" target="acc/charts/accounts/selector.php?targets=%s"|args:$account_targets name="account" label="Compte de règlement" fake_required=1}
			{input type="text" name="reference" label="Numéro de pièce comptable" help="Numéro de facture, de note de frais, etc."}
			{input type="text" name="payment_reference" label="Référence de paiement" help="Numéro de chèque, numéro de transaction CB, etc."}
			{input type="textarea" name="notes" label="Remarques"}
		</dl>
{/if}
	</fieldset>

	<p class="submit">
		{csrf_field key=$csrf_key}
		{if $user_id}
			{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
		{else}
			{button type="submit" name="next" label="Continuer" shape="right" class="main"}
		{/if}
	</p>

</form>

{literal}
<script type="text/javascript">
function selectService(elm, first_load) {
	$('[data-service]').forEach((e) => {
		e.style.display = ('s' + elm.value == e.getAttribute('data-service')) ? 'block' : 'none';
	});

	let expiry = $('#f_expiry_date');

	if (!first_load || !expiry.value) {
		// Set the expiry date
		expiry.value = elm.dataset.expiry;
	}

	var first = document.querySelector('[data-service="s' + elm.value + '"] input[name=id_fee]');

	if (first) {
		first.checked = true;
		selectFee(first, first_load);
	}
}

function selectFee(elm, first_load) {
	var amount = parseInt(elm.getAttribute('data-user-amount'), 10);

	// Toggle accounting part of the form
	var accounting = elm.getAttribute('data-account') ? true : false;
	g.toggle('.accounting', accounting);

	if (accounting) {
		$('#f_create_payment_1').checked = true;
		let btn = $('#f_account_container').firstElementChild;
		btn.value = btn.value.replace(/&year=\d+/, '') + '&year=' + elm.getAttribute('data-year');
	}

	// Fill the amount paid by the user
	if (amount && !first_load) {
		$('#f_amount').value = g.formatMoney(amount);
	}
}

$('input[name=id_service]').forEach((e) => {
	e.onchange = () => { selectService(e); };
});

$('input[name=id_fee]').forEach((e) => {
	e.onchange = () => { selectFee(e); };
});

var selected = document.querySelector('input[name="id_service"]:checked') || document.querySelector('input[name="id_service"]');
selected.checked = true;

g.toggle('.accounting', false);
selectService(selected, true);

let checkbox = $('#f_create_payment_1');
checkbox.onchange = (e) => {
	g.toggle('.accounting dl', checkbox.checked);
	//$('#f_amount').required = checkbox.checked;
};

// Automatically increase expiry date when date is changed
let date_input = $('#f_date');
let expiry_input = $('#f_expiry_date');

date_input.onchange = (e) => {
	if (!selected.dataset.duration) {
		return;
	}

	let d = date_input.value.split('/').reverse();
	d = new Date(d[0], d[1]-1, d[2], 12);
	d.setDate(d.getDate() + parseInt(selected.dataset.duration, 10));
	expiry_input.value = d.toISOString().split('T')[0].split('-').reverse().join('/');
};
</script>
{/literal}

{include file="admin/_foot.tpl"}