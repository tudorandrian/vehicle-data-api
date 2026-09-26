<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vd_api_clients', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 80);
            $t->string('owner', 120);
            $t->char('key_prefix', 8)->unique();
            $t->char('key_hash', 64)->unique();
            $t->json('allowed_origins');
            $t->unsignedSmallInteger('rate_per_minute')->default(60);
            $t->unsignedInteger('daily_quota')->default(10000);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vd_api_client_scopes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('client_id')->constrained('vd_api_clients')->cascadeOnDelete();
            $t->string('scope', 40);
            $t->unique(['client_id', 'scope']);
        });
        Schema::create('vd_api_requests', function (Blueprint $t): void {
            $t->id();
            $t->char('request_id', 36);
            $t->foreignId('client_id')->nullable()->constrained('vd_api_clients')->nullOnDelete();
            $t->string('route', 120);
            $t->string('method', 8);
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('duration_ms');
            $t->unsignedInteger('bytes');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['client_id', 'created_at']);
            $t->index('created_at');
        });
        Schema::create('vd_api_usage_daily', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained('vd_api_clients')->nullOnDelete();
            $t->date('date');
            $t->unsignedInteger('requests')->default(0);
            $t->unsignedInteger('errors')->default(0);
            $t->unsignedInteger('p95_ms')->default(0);
            $t->timestamps();
            $t->unique(['client_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vd_api_usage_daily');
        Schema::dropIfExists('vd_api_requests');
        Schema::dropIfExists('vd_api_client_scopes');
        Schema::dropIfExists('vd_api_clients');
    }
};
