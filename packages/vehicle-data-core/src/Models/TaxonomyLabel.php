<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $term_id
 * @property string $locale
 * @property string $label
 */
final class TaxonomyLabel extends Model
{
    protected $table = 'vd_taxonomy_labels';

    protected $guarded = [];

    /** @return BelongsTo<TaxonomyTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }
}
