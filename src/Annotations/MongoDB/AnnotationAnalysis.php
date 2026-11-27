<?php

namespace Vos\TollTroll\Annotations\MongoDB;

use MongoDB\Laravel\Eloquent\DocumentModel;

class AnnotationAnalysis extends \Vos\TollTroll\Annotations\AnnotationAnalysis
{
    use DocumentModel;

    protected $keyType = 'string';

    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }
}
