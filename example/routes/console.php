<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;

Artisan::command('demo:seed', function () {
    if (! is_file(database_path('demo.sqlite'))) {
        touch(database_path('demo.sqlite'));
    }

    $this->call('migrate', ['--force' => true]);
    $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
})->purpose('Create the local demo database and idempotently seed synthetic annotations and advice');
