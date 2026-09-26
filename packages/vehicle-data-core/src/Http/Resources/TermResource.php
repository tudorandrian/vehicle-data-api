<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use VehicleData\Core\Models\TaxonomyTerm;

final class TermResource
{
    /** @return array{id:string,code:string,label:string,sort_order:int,parent_code:string|null} */
    public static function make(TaxonomyTerm $term, string $lang): array
    {
        return ['id' => $term->public_id, 'code' => $term->code, 'label' => $term->label($lang) ?? $term->code, 'sort_order' => $term->sort_order, 'parent_code' => $term->parent_code];
    }
}
