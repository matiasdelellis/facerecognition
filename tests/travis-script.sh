#!/bin/bash

../../occ face:setup
make test

# Create coverage report
wget https://scrutinizer-ci.com/ocular.phar
php ocular.phar code-coverage:upload --format=php-clover clover.xml