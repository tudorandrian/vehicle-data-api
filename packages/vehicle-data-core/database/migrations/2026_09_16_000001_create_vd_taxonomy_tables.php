<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vd_taxonomies', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 40)->unique();
            $t->string('description', 255);
            $t->timestamps();
        });
        Schema::create('vd_taxonomy_terms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('taxonomy_id')->constrained('vd_taxonomies')->cascadeOnDelete();
            $t->string('code', 40);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->string('parent_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['taxonomy_id', 'code']);
        });
        Schema::create('vd_taxonomy_labels', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('term_id')->constrained('vd_taxonomy_terms')->cascadeOnDelete();
            $t->string('locale', 5);
            $t->string('label', 120)->collation('utf8mb4_romanian_ci');
            $t->timestamps();
            $t->unique(['term_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_taxonomy_labels');
        Schema::dropIfExists('vd_taxonomy_terms');
        Schema::dropIfExists('vd_taxonomies');
    }
};
