<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A retired term code, reserved inside its taxonomy for its original term.
 *
 * @property int $taxonomy_id
 * @property int $term_id
 * @property string $code
 */
final class TaxonomyTermAlias extends Model
{
    protected $table = 'vd_taxonomy_term_aliases';

    protected $guarded = [];
}
