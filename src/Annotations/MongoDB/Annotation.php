<?php

namespace Vos\TollTroll\Annotations\MongoDB;

use MongoDB\Laravel\Eloquent\DocumentModel;

class Annotation extends \Vos\TollTroll\Annotations\Annotation
{
    use DocumentModel;

    protected $keyType = 'string';

    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }
}
