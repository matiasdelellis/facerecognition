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

default: deps

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

models/1/mmod_human_face_detector.dat:
	mkdir -p models/1
	wget https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2 -O models/1/mmod_human_face_detector.dat.bz2
	bzip2 -d models/1/mmod_human_face_detector.dat.bz2

models/1/dlib_face_recognition_resnet_model_v1.dat:
	mkdir -p models/1
	wget https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2 -O models/1/dlib_face_recognition_resnet_model_v1.dat.bz2
	bzip2 -d models/1/dlib_face_recognition_resnet_model_v1.dat.bz2

models/1/shape_predictor_5_face_landmarks.dat:
	mkdir -p models/1
	wget https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2 -O models/1/shape_predictor_5_face_landmarks.dat.bz2
	bzip2 -d models/1/shape_predictor_5_face_landmarks.dat.bz2

download_models: models/1/mmod_human_face_detector.dat models/1/dlib_face_recognition_resnet_model_v1.dat models/1/shape_predictor_5_face_landmarks.dat

js/handlebars.js:
	wget http://builds.handlebarsjs.com.s3.amazonaws.com/handlebars-v4.0.5.js -O js/handlebars.js

js/lozad.js:
	wget https://raw.githubusercontent.com/ApoorvSaxena/lozad.js/master/dist/lozad.js -O js/lozad.js

javascript_deps: js/handlebars.js js/lozad.js

js-templates:
	handlebars js/templates -f js/templates.js

l10n_deps: translationtool.phar

translationtool.phar:
	wget https://github.com/nextcloud/docker-ci/raw/master/translations/translationtool/translationtool.phar -O translationtool.phar

deps: download_models composer javascript_deps l10n_deps

update-pot:
	php translationtool.phar create-pot-files
	msgmerge -U translationfiles/es/facerecognition.po translationfiles/templates/facerecognition.pot

update-translations:
	php translationtool.phar convert-po-files

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

test: deps
	./vendor/bin/phpunit --coverage-clover clover.xml -c phpunit.xml

clean:
	rm -rf ./build
	rm -f js/handlebars.js js/lozad.js
	rm -f models/1/mmod_human_face_detector.dat
	rm -f models/1/dlib_face_recognition_resnet_model_v1.dat
	rm -f models/1/shape_predictor_5_face_landmarks.dat
	rm -r translationtool.phar