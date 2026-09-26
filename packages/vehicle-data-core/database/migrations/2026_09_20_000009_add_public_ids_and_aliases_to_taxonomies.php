<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['vd_taxonomies', 'vd_taxonomy_terms'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->char('public_id', 26)->nullable()->after('id');
            });
            DB::table($table)->whereNull('public_id')->orderBy('id')->select('id')->lazyById(500)->each(function (object $row) use ($table): void {
                DB::table($table)->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
            });
            Schema::table($table, function (Blueprint $t): void {
                $t->char('public_id', 26)->nullable(false)->change();
                $t->unique('public_id');
            });
        }

        Schema::create('vd_taxonomy_aliases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('taxonomy_id')->constrained('vd_taxonomies')->cascadeOnDelete();
            $t->string('name', 40)->unique();
            $t->timestamps();
        });
        Schema::create('vd_taxonomy_term_aliases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('taxonomy_id')->constrained('vd_taxonomies')->cascadeOnDelete();
            $t->foreignId('term_id')->constrained('vd_taxonomy_terms')->cascadeOnDelete();
            $t->string('code', 40);
            $t->timestamps();
            $t->unique(['taxonomy_id', 'code']);
            $t->index('term_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_taxonomy_term_aliases');
        Schema::dropIfExists('vd_taxonomy_aliases');
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropUnique(['public_id']);
                $t->dropColumn('public_id');
            });
        }
    }
};
