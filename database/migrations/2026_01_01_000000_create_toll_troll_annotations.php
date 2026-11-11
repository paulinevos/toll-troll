<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_troll_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fingerprint', 64)->unique();
            $table->string('dataset', 64)->index();
            $table->string('sample_key');
            $table->string('policy_hash', 64)->index();
            $table->text('context');
            $table->text('policy');
            $table->string('label', 16)->nullable();
            $table->text('note')->nullable();
            $table->string('reviewer_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('toll_troll_annotation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('annotation_id')->constrained('toll_troll_annotations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('label', 16);
            $table->text('note')->nullable();
            $table->string('reviewer_id');
            $table->timestamp('created_at');
            $table->unique(['annotation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_troll_annotation_reviews');
        Schema::dropIfExists('toll_troll_annotations');
    }
};
