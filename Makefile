# Makefile for building the project

app_name=facerecognition
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
build_tools_dir=$(CURDIR)/build/tools
sign_dir=$(build_dir)/sign
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
composer=$(shell which composer 2> /dev/null)


# Default rule

default: build


# Some utils rules

test-bin-deps:
	@echo "================================================================================"
	@echo "Checking binaries needed to build the application."
	@echo "Testing node, npm, and curl. If one is missing, install it with the tools of "
	@echo "your system."
	@echo "================================================================================"
	node -v
	npm -v
	curl -V

composer:
ifeq (,$(composer))
	@echo "No composer command available, downloading a copy from the web"
	mkdir -p $(build_tools_dir)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_dir)
	php $(build_tools_dir)/composer.phar install --prefer-dist
	php $(build_tools_dir)/composer.phar update --prefer-dist
else
	composer install --prefer-dist
	composer update --prefer-dist
endif


# Dependencies of the application

npm-deps:
	npm i

js/vendor/handlebars.js: npm-deps
	mkdir -p js/vendor
	cp node_modules/handlebars/dist/handlebars.js -f js/vendor/handlebars.js

js/vendor/lozad.js: npm-deps
	mkdir -p js/vendor
	cp node_modules/lozad/dist/lozad.js -f js/vendor/lozad.js

js/vendor/autocomplete.js:
	mkdir -p js/vendor
	wget https://raw.githubusercontent.com/realsuayip/autocomplete/master/dist/autocomplete.js -O js/vendor/autocomplete.js

js/vendor/egg.js:
	mkdir -p js/vendor
	wget https://raw.githubusercontent.com/mikeflynn/egg.js/master/egg.js -O js/vendor/egg.js

js/vendor/facerecognition-dialogs.js:
	cp src/dialogs.js -f js/facerecognition-dialogs.js

javascript-deps: js/vendor/handlebars.js js/vendor/autocomplete.js js/vendor/lozad.js js/vendor/egg.js js/vendor/facerecognition-dialogs.js

vendor-deps: composer javascript-deps


# L10N Rules

l10n-update-pot:
	php translationtool.phar create-pot-files

l10n-transifex-pull:
	tx pull -s -a

l10n-transifex-push:
	tx push -s -t

l10n-transifex-apply:
	php translationtool.phar convert-po-files

l10n-clean:
	rm -rf translationfiles
	rm -f translationtool.phar

l10n-deps:
	@echo "Checking transifex client."
	tx --version
	@echo "Downloading translationtool.phar"
	curl -L https://github.com/nextcloud/docker-ci/raw/master/translations/translationtool/translationtool.phar -o translationtool.phar

# Build Rules

build-dev:
	npm run dev

build-vue:
	npm run build

js-templates:
	node_modules/handlebars/bin/handlebars src/templates -f js/facerecognition-templates.js

build: test-bin-deps build-vue vendor-deps js-templates
	@echo ""
	@echo "Build done. You can enable the application in Nextcloud."

appstore:
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude='.*' \
	--exclude=build \
	--exclude=cli \
	--exclude=composer* \
	--exclude=translation* \
	--exclude=node_modules \
	--exclude=Makefile \
	--exclude=package*json \
	--exclude=phpunit*xml \
	--exclude=psalm.xml \
	--exclude=screenshots \
	--exclude=src \
	--exclude=tests \
	--exclude=webpack* \
	--exclude=js/*map \
	--exclude=js/templates \
	--include=js/vendor \
	--exclude=vendor \
	$(project_dir) $(sign_dir)
	@echo "Signing…"
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64

test: composer
	./vendor/bin/phpunit -c phpunit.xml

clean: l10n-clean
	rm -rf js/vendor
	rm -f js/*.map
	rm -rf build
	rm -rf vendor
	rm -rf node_modules
