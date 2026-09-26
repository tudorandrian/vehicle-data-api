<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A retired taxonomy name, permanently reserved for its original taxonomy.
 *
 * @property int $taxonomy_id
 * @property string $name
 */
final class TaxonomyAlias extends Model
{
    protected $table = 'vd_taxonomy_aliases';

    protected $guarded = [];
}
