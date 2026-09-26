<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stores the Wikidata QID of a manufacturer's parent, independent of import order:
        // ManufacturerWriter sets it on every write, and ManufacturerWriter::finish() (run
        // once per import, via the RunAware hook) resolves parent_slug from it afterwards
        // with a single UPDATE...JOIN, so a child imported before its parent still resolves.
        Schema::table('vd_manufacturers', function (Blueprint $t): void {
            $t->string('parent_qid', 20)->nullable()->after('wikidata_qid');
        });
    }

    public function down(): void
    {
        Schema::table('vd_manufacturers', function (Blueprint $t): void {
            $t->dropColumn('parent_qid');
        });
    }
};
