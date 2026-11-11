<?php

return [
    'path' => 'toll-troll/annotations',
    // Gate authorization and an authenticated user are always required as well.
    'middleware' => ['web'],
    // Null disables pruning; a positive integer expires snapshots by capture age.
    // The application must explicitly schedule model:prune for the Annotation model.
    'retention_days' => null,
];
