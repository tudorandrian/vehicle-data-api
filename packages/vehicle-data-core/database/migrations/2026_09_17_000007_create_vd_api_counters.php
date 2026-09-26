<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vd_api_counters', function (Blueprint $t): void {
            $t->string('scope', 60);
            $t->string('window', 24);
            $t->unsignedInteger('count');
            // dateTime, not timestamp: MariaDB gives the first TIMESTAMP column in a table an
            // implicit `ON UPDATE CURRENT_TIMESTAMP` unless explicit_defaults_for_timestamp is
            // set, which would silently reset expires_at to "now" on every upsert.
            $t->dateTime('expires_at');
            $t->primary(['scope', 'window']);
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_api_counters');
    }
};
