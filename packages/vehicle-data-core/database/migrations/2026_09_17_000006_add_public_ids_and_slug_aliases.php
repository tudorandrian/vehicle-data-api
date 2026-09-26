<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['vd_manufacturers', 'vd_makes', 'vd_models'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->char('public_id', 26)->nullable()->after('id');
            });
            // Backfill one ULID per existing row (dev and test databases; production is empty at this point).
            DB::table($table)->whereNull('public_id')->orderBy('id')->select('id')->lazyById(500)->each(function (object $row) use ($table): void {
                DB::table($table)->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
            });
            Schema::table($table, function (Blueprint $t): void {
                $t->char('public_id', 26)->nullable(false)->change();
                $t->unique('public_id');
            });
        }
        Schema::create('vd_slug_aliases', function (Blueprint $t): void {
            $t->id();
            $t->string('record_type', 20);
            $t->unsignedBigInteger('record_id');
            $t->string('slug', 160)->unique();
            $t->timestamps();
            $t->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_slug_aliases');
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropUnique(['public_id']);
                $t->dropColumn('public_id');
            });
        }
    }
};
