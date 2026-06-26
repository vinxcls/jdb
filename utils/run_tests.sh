#!/bin/bash
if [ ! -e test/src/jdb.php ]
then
    rm test/src/jdb.php
fi
./utils/build_package.sh --no-minify test/src/jdb.php src
if [ $? -ne 0 ]
then
    exit 1
fi
cd test
composer install
php vendor/bin/codecept clean
php vendor/bin/codecept build
php vendor/bin/codecept run unit $1 --coverage --coverage-html --debug
if [ $? -ne 0 ]
then
    exit 1
fi
php -d xdebug.mode=profile \
    -d xdebug.start_with_request=yes \
    -d xdebug.output_dir=/tmp \
    -d xdebug.profiler_output_name=cachegrind.out.%p \
    -d xdebug.use_compression=0  run_stress.php
cd -
