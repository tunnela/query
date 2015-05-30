#Query
**Query, the easy to use MySQL query builder.**

## Easy Installation

### Install with [Composer][composer]

I you have not installed Composer yet, follow the [instructions][composer-install] on Composer website.

To install Query package with Composer, simply add the requirement to your composer.json file:

```json
{
  "require" : {
    "tunnela/query" : "dev-master"
  }
}
```

If this is the first time you use Composer, change to your PHP application directory and run the following command:

```shell
$ php composer.phar update
```

and require autoloader somewhere at the start of your PHP application:

```php
require 'vendor/autoload.php';
```

If this is is not the first time you use Composer, you probably already knew that you should run:

```shell
$ php composer.phar install
```

[composer]: https://getcomposer.org/
[composer-install]: https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx
