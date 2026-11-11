<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_troll_annotation_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 64);
            $table->string('policy_hash', 64);
            $table->string('fingerprint', 64);
            $table->unsignedInteger('reviewed_count');
            $table->unsignedInteger('disagreement_count');
            $table->text('suggestions');
            $table->timestamps();
            $table->unique(['dataset', 'policy_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_troll_annotation_analyses');
    }
};
