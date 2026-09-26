<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vd_record_sources', function (Blueprint $t): void {
            $t->id();
            $t->string('record_type', 20);
            $t->unsignedBigInteger('record_id');
            $t->foreignId('source_id')->constrained('vd_sources');
            $t->string('source_ref', 120);
            $t->timestamp('retrieved_at');
            $t->char('checksum', 64);
            $t->timestamps();
            $t->unique(['record_type', 'record_id', 'source_id', 'source_ref'], 'vd_record_sources_unique');
            $t->index(['record_type', 'record_id']);
        });
        Schema::create('vd_import_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('source_id')->constrained('vd_sources');
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->string('status', 20); // running|succeeded|failed|aborted
            $t->unsignedInteger('rows_read')->default(0);
            $t->unsignedInteger('rows_written')->default(0);
            $t->unsignedInteger('rows_rejected')->default(0);
            $t->json('reject_report')->nullable();
            $t->char('file_checksum', 64)->nullable();
            $t->json('options')->nullable();
            $t->timestamps();
            $t->index(['source_id', 'started_at']);
        });
        Schema::create('vd_wmi', function (Blueprint $t): void {
            $t->id();
            $t->char('code', 3)->unique();
            $t->string('manufacturer_name', 120);
            $t->char('country_code', 2)->nullable();
            $t->string('vehicle_type', 60)->nullable();
            $t->foreignId('source_id')->constrained('vd_sources');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_wmi');
        Schema::dropIfExists('vd_import_runs');
        Schema::dropIfExists('vd_record_sources');
    }
};
