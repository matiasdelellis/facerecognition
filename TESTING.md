# Testing

## Running tests

All tests should be easily executed with simple:
```bash
make test
```
from root directory. However, you will not be able to execute integration tests out of the box, as they are modifying local instance. For those, you will have to explicitely export env variable:
```bash
export TRAVIS=1
```
to be able to run them. Again &ndash; make sure you are running test server and watch out as those tests can create havoc with your instance.

Once you have all requirements satisified and you want to run test over and over again, it all boils down to calling:
```bash
phpunit -c phpunit.xml
```
from root directory. You can target specific tests with `--filter` etc., but this is all *regular* PHP unit testing, just type `phpunit -h` for more details.

If you are missing PHPUnit, you can follow their [official guide](https://phpunit.de/getting-started/phpunit-7.html), but you should get binary with somwething like this:

```bash
wget -O phpunit https://phar.phpunit.de/phpunit-7.phar
chmod +x phpunit
./phpunit --version
```

## Writing tests

As by standard definition, unit tests are not modifying external resources and should not touch database. If possible, you should try testing with unit tests, and if it not &ndash; add yourself to integration tests.

Other than that, you are good to go &ndash; there are already some unit and integration tests in `tests/` directory, and you can always take a look at [Nextcloud unit testing official documentation](https://docs.nextcloud.com/server/14/developer_manual/core/unit-testing.html).