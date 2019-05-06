<?php

require './vendor/autoload.php';
use Globalis\MysqlDataAnonymizer\Anonymizer;

$anonymizer = new Anonymizer();

// Describe `users` table.
$anonymizer->table('users', function ($table) {
    
    // Specify a primary key of the table. An array should be passed in for composite key.
    $table->primary('id');

    // Add a global filter to the queries.
    // Only string is accepted so you need to write down the comlete WHERE statement here.
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

    // Use `where` to leave some data untouched.
    // If you don't list a column here, it will be left untouched too.
    $table->column('email4')->where('ID != 1')->replaceWith(function ($generator) {
        return $generator->unique()->email;
    });

    // Use the values of current row to update a field
    // This is a position sensitive operation, so the value of field 'email4' here is the updated value.
    // So if you put this line before the previous one, the value of 'email4' here would be the valeu of 'email4' before update.
    $table->column('email5')->replaceByFields(function ($rowData, $generator) {
        return $rowData['email4'];
    });

    // Here we assume that there is a foreign key in the table 'class' on the column 'user_id'.
    // To make sure 'user_id' get updated when we update 'id', use function 'synchronizeColumn'.
    $table->column('id')->replaceWith(function ($generator) {
        return $generator->unique()->uuid;
    })->synchronizeColumn(['user_id', 'class']);
});

$anonymizer->run();

echo 'Anonymization has been completed!';
