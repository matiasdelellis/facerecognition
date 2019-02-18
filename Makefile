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
	@echo "Checking binaries needed to build the application"
	@echo "Testing curl, wget and bzip2. If one is missing, install it with the tools of your system."
	curl -V
	wget -V
#	bzip2 -V # FIXME: bzip2 always return an error.
	@echo "Testing handlebars needed to compile the templates. If it fails install as:"
	@echo " # sudo npm install handlebars -g"
	handlebars -v

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

vendor/models/1/mmod_human_face_detector.dat:
	mkdir -p vendor/models/1
	wget https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2 -O vendor/models/1/mmod_human_face_detector.dat.bz2
	bzip2 -d vendor/models/1/mmod_human_face_detector.dat.bz2

vendor/models/1/dlib_face_recognition_resnet_model_v1.dat:
	mkdir -p vendor/models/1
	wget https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2 -O vendor/models/1/dlib_face_recognition_resnet_model_v1.dat.bz2
	bzip2 -d vendor/models/1/dlib_face_recognition_resnet_model_v1.dat.bz2

vendor/models/1/shape_predictor_5_face_landmarks.dat:
	mkdir -p vendor/models/1
	wget https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2 -O vendor/models/1/shape_predictor_5_face_landmarks.dat.bz2
	bzip2 -d vendor/models/1/shape_predictor_5_face_landmarks.dat.bz2

download_models: vendor/models/1/mmod_human_face_detector.dat vendor/models/1/dlib_face_recognition_resnet_model_v1.dat vendor/models/1/shape_predictor_5_face_landmarks.dat

vendor/js/handlebars.js:
	mkdir -p vendor/js
	wget http://builds.handlebarsjs.com.s3.amazonaws.com/handlebars-v4.0.5.js -O vendor/js/handlebars.js

vendor/js/lozad.js:
	mkdir -p vendor/js
	wget https://raw.githubusercontent.com/ApoorvSaxena/lozad.js/master/dist/lozad.js -O vendor/js/lozad.js

javascript_deps: vendor/js/handlebars.js vendor/js/lozad.js

vendor-deps: download_models composer javascript_deps


# L10N Rules

translationtool.phar:
	wget https://github.com/nextcloud/docker-ci/raw/master/translations/translationtool/translationtool.phar -O translationtool.phar

l10n-update-pot: translationtool.phar
	php translationtool.phar create-pot-files
	msgmerge -U translationfiles/es/facerecognition.po translationfiles/templates/facerecognition.pot

l10n-update-translations: translationtool.phar
	php translationtool.phar convert-po-files

l10n_deps: translationtool.phar


# Build Rules

js-templates:
	handlebars js/templates -f js/templates.js

build: test-bin-deps vendor-deps js-templates
	@echo "Build done. You can enable the application in Nextcloud."

appstore:
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=.git \
	--exclude=build \
	--exclude=.gitignore \
	--exclude=.travis.yml \
	--exclude=.scrutinizer.yml \
	--exclude=CONTRIBUTING.md \
	--exclude=composer.json \
	--exclude=composer.lock \
	--exclude=translationfiles \
	--exclude=translationtool.phar \
	--exclude=l10n/.tx \
	--exclude=l10n/no-php \
	--exclude=Makefile \
	--exclude=nbproject \
	--exclude=screenshots \
	--exclude=phpunit*xml \
	--exclude=tests \
	--exclude=vendor/bin \
	$(project_dir) $(sign_dir)
	@echo "Signing…"
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64

test: build
	./vendor/bin/phpunit --coverage-clover clover.xml -c phpunit.xml

clean:
	rm -rf ./build
	rm -f vendor/autoload.php
	rm -rf vendor/bin/
	rm -rf vendor/christophwurst/
	rm -rf vendor/composer/
	rm -rf vendor/doctrine/
	rm -rf vendor/js
	rm -f vendor/models/1/*
	rm -rf vendor/myclabs/
	rm -rf vendor/phar-io/
	rm -rf vendor/phpdocumentor/
	rm -rf vendor/phpspec/
	rm -rf vendor/phpunit/
	rm -rf vendor/sebastian/
	rm -rf vendor/symfony/
	rm -rf vendor/theseer/
	rm -rf vendor/webmozart/
	rm -f translationtool.phar