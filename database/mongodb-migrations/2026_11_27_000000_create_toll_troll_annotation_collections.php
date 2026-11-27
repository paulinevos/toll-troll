<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_troll_annotations', function (Blueprint $collection) {
            $collection->unique('fingerprint');
            $collection->index('dataset');
            $collection->index('policy_hash');
        });
        Schema::create('toll_troll_annotation_reviews', function (Blueprint $collection) {
            $collection->unique(['annotation_id', 'version']);
        });
        Schema::create('toll_troll_annotation_analyses', function (Blueprint $collection) {
            $collection->unique(['dataset', 'policy_hash']);
        });
        Schema::create('toll_troll_annotation_policy_revisions', function (Blueprint $collection) {
            $collection->unique(['dataset', 'policy_hash']);
            $collection->index(['dataset', 'policy_key']);
        });
        Schema::create('toll_troll_annotation_advice_dismissals', function (Blueprint $collection) {
            $collection->unique(['analysis_id', 'reviewer_id', 'suggestion_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_troll_annotation_advice_dismissals');
        Schema::dropIfExists('toll_troll_annotation_policy_revisions');
        Schema::dropIfExists('toll_troll_annotation_analyses');
        Schema::dropIfExists('toll_troll_annotation_reviews');
        Schema::dropIfExists('toll_troll_annotations');
    }
};
