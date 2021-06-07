(function () {
	g.style('scripts/wiki_editor.css');

	function showSaved() {
		let c = document.createElement('p');
		c.className = 'block confirm';
		c.id = 'confirm_saved';
		c.innerText = 'Enregistré';
		c.style.right = '-10em';

		document.querySelector('#f_content').parentNode.appendChild(c);

		window.setTimeout(() => {
			c.style.right = '';
		}, 200);

		window.setTimeout(() => {
			c.style.opacity = 0;
		}, 3000);

		window.setTimeout(() => {
			c.remove();
		}, 5000);
	}

	g.script('scripts/lib/text_editor.min.js', function () {
		var t = new textEditor('f_content');
		t.parent = t.textarea.parentNode;

		var config = {
			fullscreen: t.textarea.getAttribute('data-fullscreen') == 1,
			attachments: t.textarea.getAttribute('data-attachments') == 1,
			savebtn: t.textarea.getAttribute('data-savebtn'),
			preview_url: t.textarea.getAttribute('data-preview-url'),
			format: t.textarea.getAttribute('data-format')
		};

		// Cancel Escape to close.value
		if (window.parent && window.parent.g.dialog) {
			// Always fullscreen in dialogs
			config.fullscreen = true;

			window.parent.g.dialog.preventClose = () => {
				if (t.textarea.value == t.textarea.defaultValue) {
					return false;
				}

				if (window.confirm('Sauvegarder avant de fermer ?')) {
					save();
				}

				return false;
			};
		}

		var toolbar = document.createElement('nav');
		toolbar.className = 'te';

		var toggleFullscreen = function (e)
		{
			t.parent.classList.toggle('fullscreen');
			t.fullscreen = true;
			return true;
		};

		if (config.fullscreen) {
			toggleFullscreen();
		}

		var openPreview = function ()
		{
			openIFrame('');
			var form = document.createElement('form');
			form.appendChild(t.textarea.cloneNode(true));
			form.firstChild.value = t.textarea.value;
			let f = document.createElement('input');
			f.type = 'hidden';
			f.name = 'format';
			f.value = config.format;
			form.appendChild(f);
			form.target = 'editorFrame';
			form.action = config.preview_url;
			form.style.display = 'none';
			form.method = 'post';
			document.body.appendChild(form);
			form.submit();
			return true;
		};

		var openSyntaxHelp = function ()
		{
			let url = config.format == 'markdown' ? '_syntax_markdown.html' : '_syntax_skriv.html';
			url = g.admin_url + 'web/' + url;

			openIFrame(url);
		};

		var openFileInsert = function ()
		{
			let args = new URLSearchParams(window.location.search);
			var wiki_id = args.get('p');
			g.openFrameDialog(g.admin_url + 'web/_attach.php?_dialog&p=' + wiki_id);
		};

		window.te_insertFile = function (file)
		{
			var tag = '<<file|'+file+'>>';

			t.insertAtPosition(t.getSelection().start, tag);

			g.closeDialog();
		};

		window.te_insertImage = function (file, position, caption)
		{
			var tag = '<<image|' + file;

			if (position)
				tag += '|' + position;

			if (caption)
				tag += '|' + caption;

			tag += '>>';

			t.insertAtPosition(t.getSelection().start, tag);

			g.closeDialog();
		};

		var EscapeEvent = function (e) {
			if (e.key == 'Escape') {
				closeIFrame();
				e.preventDefault();
				return false;
			}
		};

		var openIFrame = function(url)
		{
			if (t.iframe && t.iframe.src == t.base_url + url)
			{
				t.iframe.className = '';
				t.parent.className += ' iframe';
				return true;
			}
			else if (t.iframe)
			{
				t.parent.removeChild(t.iframe);
				t.iframe = null;
			}

			var w = t.textarea.offsetWidth,
				h = t.textarea.offsetHeight;

			var iframe = document.createElement('iframe');
			iframe.width = w;
			iframe.height = h;
			iframe.src = url;
			iframe.name = 'editorFrame';
			iframe.frameborder = '0';
			iframe.scrolling = 'yes';

			t.parent.appendChild(iframe);
			t.parent.className += ' iframe';
			t.iframe = iframe;

			document.addEventListener('keydown', EscapeEvent);
		};

		var closeIFrame = function ()
		{
			document.removeEventListener('keydown', EscapeEvent);

			if (!t.iframe) {
				return true;
			}
			t.parent.className = t.parent.className.replace(/ iframe$/, '');
			t.iframe.className = 'hidden';
		};


		var appendButton = function (name, title, action, altTitle)
		{
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.title = altTitle ? altTitle : title;
			if (title.length == 1) {
				btn.dataset.icon = title;
			}
			else {
				btn.innerText = title;
			}
			btn.className = 'icn-btn ' +name;
			btn.onclick = function () { action.call(); return false; };

			toolbar.appendChild(btn);
			return btn;
		};

		let applyHeader = () => {
			wrapTags(config.format == 'markdown' ? '## ' : '== ', '');
		};

		let applyBold = () => {
			wrapTags('**', '**');
		};

		let applyItalic = () => {
			if (config.format == 'markdown') {
				wrapTags("_", "_");
			}
			else {
				wrapTags("''", "''");
			}
		};

		var wrapTags = function (left, right)
		{
			t.wrapSelection(t.getSelection(), left, right);
			return true;
		};

		let insertURL = function () {
			let url = window.prompt('Adresse URL ?');

			if (!url) {
				return true;
			}

			if (config.format == 'markdown') {
				wrapTags("[", "](" + url + ')');
			}
			else {
				wrapTags("[[", "|" + url + ']]');
			}
		};

		let save = function () {
			const data = new URLSearchParams();

			for (const pair of new FormData(t.textarea.form)) {
				data.append(pair[0], pair[1]);
			}

			data.append('save', 1);

			fetch(t.textarea.form.action + '&js', {
				method: 'post',
				body: data,
			}).then((response) => {
				if (!response.ok) {
					throw response;
				}
				showSaved();
				t.textarea.defaultValue = t.textarea.value;
			}).catch(e => t.textarea.form.querySelector('[type=submit]').click() );
			return true;
		};

		let createToolbar = () => {
			appendButton('title', "Titre", applyHeader );
			appendButton('bold', 'Gras', applyBold );
			appendButton('italic', "Italique", applyItalic );
			appendButton('link', "Lien", insertURL);

			appendButton('ext preview', '👁', openPreview, 'Prévisualiser');
			appendButton('ext help', '❓', openSyntaxHelp, 'Aide sur la syntaxe');

			if (config.attachments) {
				appendButton('file', "📎 Fichiers", openFileInsert, 'Insérer fichier / image');
				t.shortcuts.push({ctrl: true, shift: true, key: 'i', callback: openFileInsert});
			}

			if (!config.fullscreen) {
				appendButton('ext fullscreen', 'Plein écran', toggleFullscreen, 'Plein écran');
			}

			if (config.savebtn == 1) {
				appendButton('ext save', 'Enregistrer', save, 'Enregistrer');
			}
			else if (config.savebtn == 2) {
				appendButton('ext save', '⇑', save, 'Enregistrer sans fermer');
			}

			appendButton('ext close', 'Fermer', closeIFrame);

			t.parent.insertBefore(toolbar, t.parent.firstChild);
		}

		let toggleFormat = (format) => {
			config.format = format;
			g.toggle('.wikiEncrypt', format == 'skriv/encrypted');
		};

		if (config.format.substr(0, 1) == '#') {
			let s = document.querySelector(config.format);
			s.onchange = () => {
				toggleFormat(s.value);
			};
			toggleFormat(s.value);
		}
		else {
			toggleFormat(config.format);
		}

		createToolbar();

		t.shortcuts.push({key: 'F11', callback: toggleFullscreen});
		t.shortcuts.push({ctrl: true, key: 'b', callback: applyBold });
		t.shortcuts.push({ctrl: true, key: 'g', callback: applyBold });
		t.shortcuts.push({ctrl: true, key: 'i', callback: applyItalic });
		t.shortcuts.push({ctrl: true, key: 't', callback: applyHeader });
		t.shortcuts.push({ctrl: true, key: 'l', callback: insertURL});
		t.shortcuts.push({ctrl: true, key: 's', callback: save});
		t.shortcuts.push({ctrl: true, shift: true, key: 'p', callback: openPreview});
		t.shortcuts.push({key: 'F1', callback: openSyntaxHelp});
	});
}());