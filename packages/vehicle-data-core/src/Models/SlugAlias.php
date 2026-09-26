<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A retired slug of a manufacturer, make or model. Requests for it answer 301 to the
 * record's current slug (CatalogueLookup). record_type is the morph class of the record.
 *
 * @property int $id
 * @property string $record_type
 * @property int $record_id
 * @property string $slug
 */
final class SlugAlias extends Model
{
    protected $table = 'vd_slug_aliases';

    protected $guarded = [];
}
