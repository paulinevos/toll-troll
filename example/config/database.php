<?php

// Deliberately ignore external DB credentials. Demo commands only touch this file.
return [
    'default' => 'sqlite',
    'connections' => ['sqlite' => [
        'driver' => 'sqlite',
        'database' => database_path('demo.sqlite'),
        'foreign_key_constraints' => true,
    ]],
];
