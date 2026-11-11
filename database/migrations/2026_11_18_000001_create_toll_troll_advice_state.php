<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_troll_annotation_policy_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 64);
            $table->string('policy_key', 64);
            $table->string('policy_hash', 64);
            $table->boolean('deployment_tracked')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->unique(['dataset', 'policy_hash'], 'toll_troll_annotation_revision_unique');
            $table->index(['dataset', 'policy_key'], 'toll_troll_annotation_policy_index');
        });

        Schema::create('toll_troll_annotation_advice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('toll_troll_annotation_analyses')->cascadeOnDelete();
            $table->string('reviewer_id');
            $table->string('suggestion_key', 64);
            $table->unique(['analysis_id', 'reviewer_id', 'suggestion_key'], 'toll_troll_advice_dismissal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_troll_annotation_advice_dismissals');
        Schema::dropIfExists('toll_troll_annotation_policy_revisions');
    }
};
