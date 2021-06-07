# Garradin, le gestionnaire d'association libre et simple

<nav id="gnav">

* [Guides d'installation](/wiki/?name=Installation)
* [Documentation](/wiki/?name=Documentation)
* <a href="https://garradin.eu/" target="_blank">Essayer gratuitement</a>
* [Entraide](/wiki/?name=Entraide)

<ul id="download">
	<li><a href="$ROOT/wiki/?name=Changelog">Nouveautés</a></li>
	<li><a href="$ROOT/uvlist">Anciennes versions</a></li>
</ul>

</nav>

<p id="give"><a href="http://kd2.org/asso/soutien/" target="_blank">Soutenir Garradin en effectuant un don :-)</a></p>


<script type="text/javascript">
document.head.innerHTML += `<style type="text/css">
#give {
	text-align: center;
	padding: 1em;
}

#give a {
	display: inline-block;
	padding: .5em;
	padding-left: 70px;
	border-radius: .5em;
	font-size: 1.5em;
	background: #ffc url("https://kd2.org/asso/soutien/coins.png") no-repeat .5em .5em;
	border: 2px solid #990;
}

#gnav ul {
	display: flex;
	padding: 0;
	margin: 1em;
	margin-bottom: 1em;
	font-size: 1.2em;
	list-style: none;
	justify-content: center;
	align-items: center;
}

#gnav li {
	margin: 0;
	padding: 0;
	font-size: 1.2em;
	margin: .5em;
	text-align: center;
}

#gnav li a {
	height: 100%;
	padding: .5rem;
	background: #ddf;
	color: black;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: .5em;
	border: 2px solid #99f;
	text-decoration: none;
}

#gnav li strong, #gnav li em {
	height: 100%;
	padding: .5rem;
	display: block;
}

#gnav li a:hover {
	text-decoration: underline;
	opacity: 0.7;
}

#download li {
	font-size: 1em;
}

#download li a {
	border-color: #060;
	background: #dfd;
}
`;

function isNewerVersion (oldVer, newVer) {
	const oldParts = oldVer.split('.')
	const newParts = newVer.split('.')
	for (var i = 0; i < newParts.length; i++) {
		const a = ~~newParts[i] // parse int
		const b = ~~oldParts[i] // parse int
		if (a > b) return true
		if (a < b) return false
	}
	return false
}

fetch('/garradin/juvlist?'+(+(new Date))).then((r) => {
	r.json().then((list) => {
		let last;
		let selected;

		list.forEach((file) => {
			var v = file.name.match(/^garradin-(.*)\.tar\.bz2/);

			if (!v || v[1].match(/-(alpha|rc|beta)/)) {
				return;
			}

			if (!last || isNewerVersion(last, v[1])) {
				last = v[1];
				selected = file;
			}
		});

		let days = ((+new Date)/1000 - selected.mtime) / 3600 / 24;

		if (days < 31) {
			time = Math.ceil(days) + ' jours';
		}
		else if (days >= 31) {
			time = Math.round(days / 30.5) + ' mois';
		}

		document.querySelector('#download').innerHTML = `<li><strong>Dernière version : ${last}</strong></li>
			<li><em>il y a ${time}</em></li>
			<li><a href="$ROOT/uv/${selected.name}">Télécharger</a></li>` + document.querySelector('#download').innerHTML;
	});
});
</script>

## C'est quoi ?

<a href="$ROOT/raw/7bb068963b9f6301b27b81fe925caae9e86a229b?m=image/png" target="_blank" style="float: right; margin: 1em;"><img src="/garradin/raw/7bb068963b9f6301b27b81fe925caae9e86a229b?m=image/png" alt="Liste des membres" width="400" /></a>

Garradin est un logiciel de gestion d'association (loi 1901 / ASBL / etc.). Son but est de permettre :

*  la gestion des __adhérent⋅e⋅s__ : ajout, modification, suppression, possibilité de choisir les informations présentes sur les fiches adhérent, envoi de mails collectifs aux adhérent⋅e⋅s
*  la tenue de la __comptabilité__ : avoir une gestion comptable complète à même de satisfaire un expert-comptable tout en restant à la portée de celles et ceux qui ne savent pas ce qu'est la comptabilité à double entrée, permettre la production des rapports et bilans annuels et de suivre au jour le jour le budget de l'association
*  la gestion des __cotisations__ et __activités__ : suivi des cotisations à jour, inscriptions et paiement des activités, rappels automatiques par e-mail, etc.
*  le travail __collaboratif__ et __collectif__ : gestion fine des droits d'accès aux fonctions, échange de mails entre membres…
*  la __simplification administrative__ : prise de notes en réunion, archivage et partage de fichiers (afin d'éliminer le besoin d'archiver les documents papier), etc.
*  la publication d'un __site web__ pour l'association, simple mais suffisamment flexible pour pouvoir adapter le fonctionnement à la plupart des besoins
*  l'__autonomisation des adhérents__ : possibilité de mettre à jour leurs informations par eux-même, ou de s'inscrire seul depuis un ordinateur ou un smartphone
*  la possibilité d'adapter aux besoins spécifiques de chaque association via des __extensions__.

Tous ces objectifs ne sont pas encore réalisés, voir :

* [la liste des fonctionnalités disponibles](/wiki/?name=Fonctionnalités) pour ce qui est actuellement disponible ;
* [la feuille de route](/wiki/?name=Roadmap) pour la liste des fonctionnalités qu'il reste à implémenter.

Garradin est un logiciel libre disponible sous licence [AGPL v3](https://www.gnu.org/licenses/why-affero-gpl.fr.html).

Garradin signifie *argent* en *Wagiman*, un dialecte aborigène du nord de l'Australie.

## Documentation et entraide

*  D'abord lire la [documentation](/wiki/?name=Documentation) et notamment la [foire aux questions](/wiki/?name=FAQ)
*  La [liste de discussion d'entraide entre utilisateurs](https://admin.kd2.org/lists/aide@garradin.eu) est le meilleur moyen de vous faire aider :)
*  [Chat d'entraide en direct](https://kiwiirc.com/nextclient/#irc://irc.libera.chat/#garradin?nick=garradin%7C?), ou via IRC : salon `#garradin` sur `irc.libera.chat` (port `6697` avec TLS)

## Participer

Tout coup de main est le bienvenu, pas besoin d'avoir des connaissances techniques ! Nous avons un [guide de contribution](/wiki/?name=Contribuer) pour vous aider à voir comment vous pouvez participer à Garradin :)

### Développement

Garradin est un logiciel libre, développé en PHP, utilisant la base de données SQLite, et avec une interface utilisant HTML, CSS et un peu de Javascript.

Nous acceptons les contributions (plugins, patch, code, tickets, etc.) avec plaisir, consultez la [documentation développeur⋅euse](/wiki/?name=Documentation développeur) pour découvrir comment vous pouvez contribuer.
