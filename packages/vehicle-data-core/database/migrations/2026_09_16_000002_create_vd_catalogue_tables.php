<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vd_sources', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 40)->unique();
            $t->string('name', 120);
            $t->string('licence_id', 40);
            $t->string('licence_name', 120);
            $t->string('licence_url', 255);
            $t->string('attribution', 255);
            $t->string('url', 255);
            $t->string('terms_url', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('vd_manufacturers', function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 120)->unique();
            $t->string('name', 120)->collation('utf8mb4_romanian_ci');
            $t->char('country_code', 2)->nullable();
            $t->unsignedSmallInteger('founded_year')->nullable();
            $t->string('parent_slug', 120)->nullable();
            $t->string('website', 255)->nullable();
            $t->string('wikidata_qid', 20)->unique();
            $t->string('logo_commons_file', 255)->nullable();
            $t->string('logo_licence', 60)->nullable();
            $t->timestamps();
            $t->index('name');
        });
        Schema::create('vd_makes', function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 120)->unique();
            $t->string('name', 120)->collation('utf8mb4_romanian_ci');
            $t->string('kind', 20)->default('car');
            $t->foreignId('manufacturer_id')->nullable()->constrained('vd_manufacturers')->nullOnDelete();
            $t->unsignedInteger('ro_fleet_count')->nullable();
            $t->unsignedSmallInteger('ro_fleet_year')->nullable();
            $t->timestamps();
            $t->index(['kind', 'name']);
        });
        DB::statement('ALTER TABLE vd_makes ADD CONSTRAINT chk_vd_makes_ro_fleet CHECK ((ro_fleet_count IS NULL) = (ro_fleet_year IS NULL))');
        Schema::create('vd_models', function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 160)->unique();
            $t->foreignId('make_id')->constrained('vd_makes')->cascadeOnDelete();
            $t->string('name', 120)->collation('utf8mb4_romanian_ci');
            $t->unsignedSmallInteger('first_year')->nullable();
            $t->unsignedSmallInteger('last_year')->nullable();
            $t->unsignedInteger('ro_fleet_count')->nullable();
            $t->unsignedSmallInteger('ro_fleet_year')->nullable();
            $t->timestamps();
            $t->index(['make_id', 'name']);
        });
        DB::statement('ALTER TABLE vd_models ADD CONSTRAINT chk_vd_models_ro_fleet CHECK ((ro_fleet_count IS NULL) = (ro_fleet_year IS NULL))');
        Schema::create('vd_variants', function (Blueprint $t): void {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('model_id')->constrained('vd_models')->cascadeOnDelete();
            $t->string('fuel_code', 40);
            $t->string('eu_category_code', 40);
            $t->string('euro_norm_code', 40)->nullable();
            $t->unsignedSmallInteger('engine_cc')->nullable();
            $t->unsignedSmallInteger('power_kw')->nullable();
            $t->unsignedSmallInteger('mass_kg')->nullable();
            $t->unsignedSmallInteger('co2_wltp')->nullable();
            $t->unsignedSmallInteger('year_from')->nullable();
            $t->unsignedSmallInteger('year_to')->nullable();
            $t->json('specifications')->nullable();
            $t->timestamps();
            $t->index(['model_id', 'fuel_code']);
            $t->index(['fuel_code', 'eu_category_code']);
            $t->index('power_kw');
            $t->index('engine_cc');
            $t->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_variants');
        Schema::dropIfExists('vd_models');
        Schema::dropIfExists('vd_makes');
        Schema::dropIfExists('vd_manufacturers');
        Schema::dropIfExists('vd_sources');
    }
};
