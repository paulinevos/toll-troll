<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Database\Eloquent\Model;

class AnnotationAnalysis extends Model
{
    protected $table = 'toll_troll_annotation_analyses';

    protected $guarded = [];

    public static function suggestionKey(array $suggestion): string
    {
        return hash('sha256', json_encode($suggestion, JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return ['suggestions' => 'encrypted:array', 'reviewed_count' => 'integer', 'disagreement_count' => 'integer'];
    }
}
