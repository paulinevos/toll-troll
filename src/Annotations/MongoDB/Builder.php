<?php

namespace Vos\TollTroll\Annotations\MongoDB;

use MongoDB\Laravel\Eloquent\Builder as EloquentBuilder;

class Builder extends EloquentBuilder
{
    public function delete()
    {
        $connection = $this->getModel()->getConnection();
        $delete = function () use ($connection) {
            $ids = (clone $this)->pluck('id')->all();
            [$collection, $key] = $this->getModel() instanceof Annotation
                ? ['toll_troll_annotation_reviews', 'annotation_id']
                : ['toll_troll_annotation_advice_dismissals', 'analysis_id'];
            $connection->table($collection)->whereIn($key, $ids)->delete();

            // MongoDB ignores delete limits greater than one; constrain deletion to this batch.
            return (clone $this)->whereIn('id', $ids)->toBase()->delete();
        };

        return $connection->transactionLevel() > 0 ? $delete() : $connection->transaction($delete);
    }
}
