<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use VehicleData\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property int $taxonomy_id
 * @property string $code
 * @property int $sort_order
 * @property string|null $parent_code
 */
final class TaxonomyTerm extends Model
{
    use HasPublicId;

    protected $table = 'vd_taxonomy_terms';

    protected $guarded = [];

    /** @return BelongsTo<Taxonomy, $this> */
    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }

    /** @return HasMany<TaxonomyLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(TaxonomyLabel::class, 'term_id');
    }

    public function label(string $locale): ?string
    {
        /** @var TaxonomyLabel|null $label */
        $label = $this->labels->firstWhere('locale', $locale);

        return $label?->label;
    }
}
