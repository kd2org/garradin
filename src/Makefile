.PHONY: dev-server release deps publish check-dependencies test minify phpstan
KD2_FILE := https://fossil.kd2.org/kd2fw/uv/KD2-7.2.zip

deps:
	$(eval TMP_KD2=$(shell mktemp -d))
	#cd ${TMP_KD2}

	wget ${KD2_FILE} -O ${TMP_KD2}/kd2.zip

	rm -rf "include/lib/KD2"
	unzip "${TMP_KD2}/kd2.zip" -d include/lib

	rm -rf ${TMP_KD2}

	wget -O "include/lib/Parsedown.php" "https://raw.githubusercontent.com/erusev/parsedown/1.7.x/Parsedown.php"

	wget -O "include/lib/Parsedown.php" "https://raw.githubusercontent.com/erusev/parsedown/1.7.x/Parsedown.php"
	wget -O "include/lib/ParsedownExtra.php" "https://raw.githubusercontent.com/erusev/parsedown-extra/0.8.x/ParsedownExtra.php"

dev-server:
	php -S localhost:8082 -t www www/_route.php

test:
	find . -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

phpstan:
	phpstan.phar analyze -c ../tests/phpstan.neon include www

release: test minify
	$(eval VERSION=$(shell cat VERSION))
	rm -rf /tmp/garradin-build
	mkdir -p /tmp/garradin-build
	fossil zip ${VERSION} /tmp/garradin-build/src.zip --name garradin
	unzip -d /tmp/garradin-build /tmp/garradin-build/src.zip
	cd include/lib; \
		rsync --files-from=dependencies.list -r ./ /tmp/garradin-build/garradin/src/include/lib/
	mv www/admin/static/mini.css /tmp/garradin-build/garradin/src/www/admin/static/admin.css
	cd /tmp/garradin-build/garradin/src/www/admin/static; \
		rm -f styles/[0-9]*.css; \
		rm -f font/*.css font/*.json
	cd /tmp/garradin-build/garradin/src; \
		rm -f Makefile include/lib/KD2/data/countries.en.json
	cd /tmp/garradin-build/garradin/src/data; mkdir plugins && cd plugins; \
		wget https://fossil.kd2.org/garradin-plugins/uv/welcome.tar.gz
	mv /tmp/garradin-build/garradin/src /tmp/garradin-build/garradin-${VERSION}
	@#cd /tmp/garradin-build/; zip -r -9 garradin-${VERSION}.zip garradin-${VERSION};
	@#mv -f /tmp/garradin-build/garradin-${VERSION}.zip ./
	tar cjvfh garradin-${VERSION}.tar.bz2 -C /tmp/garradin-build garradin-${VERSION}

deb:
	cd ../debian; ./makedeb.sh

publish: release deb
	$(eval VERSION=$(shell cat VERSION))
	gpg --armor --detach-sign garradin-${VERSION}.tar.bz2
	fossil uv sync
	#fossil uv ls | fgrep -v 'garradin-0.8.5' | grep '^garradin-.*\.(tar\.bz2|deb)' | xargs fossil uv rm
	fossil uv add garradin-${VERSION}.tar.bz2
	fossil uv add garradin-${VERSION}.tar.bz2.asc
	cd ../debian && fossil uv add garradin-${VERSION}-*.deb
	fossil uv sync

check-dependencies:
	grep -hEo '^use \\?KD2\\[^; ]+|\\KD2\\[^\(:; ]+' -R include/lib/Garradin www | sed -r 's/^use \\?KD2\\|^\\KD2\\//' | sort | uniq

minify:
	cat `ls www/admin/static/styles/[0-9]*.css` | sed 's/\.\.\///' > www/admin/static/mini.css
	# Minify is only gaining 500 gzipped bytes (4kB uncompressed) but making things hard to read/hack
	#yui-compressor --nomunge www/admin/static/mini.css -o www/admin/static/mini.css
