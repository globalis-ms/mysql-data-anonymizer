# MySQL Data Anonymizer

MySQL Data Anonymizer is a PHP library that anonymize your data in the database.
Always use the production database to test your programs, but worry about leaking cutomer data?
MySQL Data Anonymizer is the right tool for you. This tool helps you replace all sensitive data with fake data.
Fake data is provided by a [fzaninotto/Faker](https://github.com/fzaninotto/Faker) generator by default, but you can also use your own generator.
To improve the performance, [AMP/MySQL](https://github.com/amphp/mysql) is used to create multiple MySQL connections concurrently.

MySQL Data Anonymizer requires PHP >= 7.2.

# Table of Contents

- [Configuration](#configuration)
- [Example code](#example-code)
- [Helpers and providers](#helpers-and-providers)



## Configuration

Rename the config-sample.php file to config.php and modify the configurations to suit your environment.
```php
<?php return array (
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'dbname',
    'DB_USER' => 'username',
    'DB_PASSWORD' => 'password',
    'NB_MAX_MYSQL_CLIENT' => 50,
    'NB_MAX_PROMISE_IN_LOOP' => 50,
    'DEFAULT_GENERATOR_LOCALE' => 'en_US'
);
```
NB_MAX_MYSQL_CLIENT is the max number of MySQL connections simultaneously when executing your scripts.
By default, MySQL supports at most 151 connections simultaneously, but you can modify your MySQL variable 'max_connections' to break this restriction.

NB_MAX_PROMISE_IN_LOOP is the max number of promises we keep in the promise table. Each promise represents the future result of an SQL query. The larger the number, the faster the execution will be. But you have to be careful that holding a large number of promises will consume too much memory and CPU resources. If your processor can't afford it, the run time will be at least 10 times longer than expected. <strong>If you don't know too much about the performance of your processor, just leave this variable to 50, or even 20 if you are not quite confident on it</strong>.

DEFAULT_GENERATOR_LOCALE influences the generated data's language and format by Faker's generator. You can find the full list of locales from [here](https://github.com/fzaninotto/Faker/tree/master/src/Faker/Provider)



## Example code

```php
<?php

require './vendor/autoload.php';
use Globalis\MysqlDataAnonymizer\Anonymizer;

$anonymizer = new Anonymizer();

// Describe `users` table.
$anonymizer->table('users', function ($table) {
    
    // Specify a primary key of the table. An array should be passed in for composite key.
    $table->primary('id');

    // Add a global filter to the queries.
    // Only string is accepted so you need to write down the complete WHERE statement here.
    $table->globalWhere('email4 != email5 AND id != 10');

    // Replace with static data.
    $table->column('email1')->replaceWith('john@example.com');

    // Use #row# template to get "email_0@example.com", "email_1@example.com", "email_2@example.com"
    $table->column('email2')->replaceWith('email_#row#@example.com');

    // To replace with dynamic data a $generator is needed.
    // By default, a fzaninotto/Faker generator will be used. 
    // Any generator object can be set like that - `$anonymizer->setGenerator($generator);`
    $table->column('email3')->replaceWith(function ($generator) {
        return $generator->email;
    });

    // Use `where` to leave some data untouched for a specific column.
    // If you don't list a column here, it will be left untouched too.
    $table->column('email4')->where('ID != 1')->replaceWith(function ($generator) {
        return $generator->unique()->email;
    });

    // Use the values of current row to update a field
    // This is a position sensitive operation, so the value of field 'email4' here is the updated value.
    // So if you put this line before the previous one, the value of 'email4' here would be the valeu of 'email4' before update.
    $table->column('email5')->replaceByFields(function ($rowData) {
        return strtolower($rowData['email4']);
    });

    // Here we assume that there is a foreign key in the table 'class' on the column 'user_id'.
    // To make sure 'user_id' get updated when we update 'id', use function 'synchronizeColumn'.
    $table->column('id')->replaceWith(function ($generator) {
        return $generator->unique()->uuid;
    })->synchronizeColumn(['user_id', 'class']);
});

$anonymizer->run();

echo 'Anonymization has been completed!';

```

For more fake data types and details about fake data generator, you can find what you want from [fzaninotto/Faker's Github page](https://github.com/fzaninotto/Faker)


## Helpers and providers

You can add your own helper and generator classes in src/helpers and src/providers. File names of helpers and providers need to keep these format : 'XXXHelper.php', 'XXXProvider.php', or they won't be loaded.

An example of customize helper:

```php
<?php

namespace Globalis\MysqlDataAnonymizer\Helpers; //Default namespace, should always use this one

class StrHelper //Class name needs to be the same as file name
{
    public static function toLower($string)
    {
        return strtolower($string);
    }
}
```

Then in your script, you can use it like this:
```php
<?php

require './vendor/autoload.php';
use Globalis\MysqlDataAnonymizer\Anonymizer;
use Globalis\MysqlDataAnonymizer\Helpers;

$anonymizer = new Anonymizer();

$anonymizer->table('users', function ($table) {
    
    $table->primary('id');
    $table->column('name')->replaceByFields(function ($rowData, $generator) {
        return Helpers\StrHelper::toLower(($rowData['name']));
    });
}
```

An example of customize provider:
```php
<?php

namespace Globalis\MysqlDataAnonymizer\Provider; //Default namespace, should always use this one

class EnumProvider extends \Faker\Provider\Base //Class name needs to be the same as file name, and provider classes need to extend \Faker\Provider\Base
{

    //This simple method returns a fruit randomly from the list
    public function fruit()
    {
        $enum = ['apple', 'orange', 'banana'];

        return $enum[rand(0, 2)];
    }
}
```

Then in your script, you can use it like this:
```php
<?php

require './vendor/autoload.php';
use Globalis\MysqlDataAnonymizer\Anonymizer;

$anonymizer = new Anonymizer();

$anonymizer->table('users', function ($table) {
    $table->primary('id');
    $table->column('favorite_fruit')->replaceWith(function ($generator) {
        return $generator->fruit;
    });
}
```
