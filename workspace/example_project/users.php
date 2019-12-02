<?php

$table->primary('id');

$table->column('username')->replaceWithGenerator('userName', [], true);

$table->column('first_name')->replaceWithGenerator('firstName');

$table->column('last_name')->replaceWithGenerator('lastName');

$table->column('email')->replaceWithGenerator('email');

$table->column('language')->replaceWithGenerator('languageCode');

$table->column('timezone')->replaceWithGenerator('timezone');

$table->column('updated_at')->replaceWithGenerator('iso8601');

$table->column('created_at')->replaceByFields(function ($rowData, $generator) {
    return $generator->iso8601($rowData['updated_at']);
});

$table->column('id')->replaceWithGenerator('uuid', [], true)->synchronizeColumn(['user_id', 'users_roles']);

$table->doAfterUpdate(function ($rowDataBefore, $rowDataAfter, $generator) {
    $queries = [];
    $queries[] = "UPDATE users_roles SET user_id = '" . addslashes($rowDataAfter['id']) . "' WHERE user_id = '" . addslashes($rowDataBefore['id']) . "'";
    return $queries;
}, ['users_roles']);