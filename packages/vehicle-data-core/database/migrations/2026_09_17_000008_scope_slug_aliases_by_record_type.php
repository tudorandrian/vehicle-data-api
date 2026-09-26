<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vd_slug_aliases.slug was globally unique (2026_09_17_000006), but manufacturer and make slugs
 * collide by design (bmw, dacia, fiat, kia, opel, renault, toyota, volkswagen all exist as both
 * record types on the live stack): a slug retired by one type must not reserve it against another
 * (CatalogueIdentity::rename()/assertSlugFree()). This is additive, not an edit to 000006, which is
 * already applied on deployed databases (CONTRIBUTING: never edit a merged migration): it drops the
 * old global unique and adds one scoped to (record_type, slug).
 *
 * The explicit index name is well under MySQL/MariaDB's 64-character identifier limit even with
 * the auto-generated form, but is named explicitly so up() and down() reference the exact same
 * index regardless of what Laravel's naming convention would produce. The drop and the add are
 * two separate ALTER TABLE statements (two Schema::table() calls): MariaDB can fail a single
 * ALTER that both drops a unique index and adds a new one covering the same leading column in one
 * statement, depending on how it plans the index rebuild.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'vd_slug_aliases_slug_unique';

    private const NEW_UNIQUE = 'vd_slug_aliases_record_type_slug_unique';

    public function up(): void
    {
        Schema::table('vd_slug_aliases', function (Blueprint $t): void {
            $t->dropUnique(self::OLD_UNIQUE);
        });
        Schema::table('vd_slug_aliases', function (Blueprint $t): void {
            $t->unique(['record_type', 'slug'], self::NEW_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('vd_slug_aliases', function (Blueprint $t): void {
            $t->dropUnique(self::NEW_UNIQUE);
        });
        Schema::table('vd_slug_aliases', function (Blueprint $t): void {
            $t->unique('slug', self::OLD_UNIQUE);
        });
    }
};
