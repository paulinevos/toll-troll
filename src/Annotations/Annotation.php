<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Annotation extends Model
{
    use HasUuids, MassPrunable;

    protected $table = 'toll_troll_annotations';

    protected $guarded = [];

    public function prunable(): Builder
    {
        $days = config('toll-troll-annotations.retention_days');

        if ($days === null) {
            return static::query()->whereRaw('1 = 0');
        }

        if (! is_int($days) || $days < 1) {
            throw new InvalidArgumentException('toll-troll-annotations.retention_days must be null or a positive integer.');
        }

        return static::query()->where('created_at', '<=', now()->subDays($days));
    }

    protected function casts(): array
    {
        return ['context' => 'encrypted:array', 'policy' => 'encrypted:array', 'note' => 'encrypted', 'version' => 'integer', 'reviewed_at' => 'datetime'];
    }
}
