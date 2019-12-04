#!/bin/bash

ls -alR /home/travis/build/matiasdelellis/server/data-autotest
../../occ face:setup
ls -alR /home/travis/build/matiasdelellis/server/data-autotest
make test
ls -alR /home/travis/build/matiasdelellis/server/data-autotest

# Create coverage report
wget https://scrutinizer-ci.com/ocular.phar
php ocular.phar code-coverage:upload --format=php-clover clover.xml