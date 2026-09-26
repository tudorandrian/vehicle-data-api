<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final readonly class ListParams
{
    /**
     * @param  list<string>  $fields
     * @param  array<string,mixed>  $filters
     */
    public function __construct(
        public int $page, public int $perPage, public ?string $sort, public bool $sortDesc,
        public array $fields, public ?CarbonImmutable $updatedSince, public array $filters, public string $lang, public ?string $q,
    ) {}

    /**
     * @param  list<string>  $sortable
     * @param  array<string,string>  $filterRules  (name => Laravel rule string)
     */
    public static function fromRequest(Request $request, array $sortable, array $filterRules, string $lang): self
    {
        $max = (int) config('core.per_page_max', 100);
        $sortValues = array_merge($sortable, array_map(static fn (string $s) => '-'.$s, $sortable));

        $data = Validator::make($request->query(), [
            'page' => 'sometimes|integer|min:1',
            'per_page' => "sometimes|integer|min:1|max:{$max}",
            'sort' => ['sometimes', 'string', 'in:'.implode(',', $sortValues)],
            'fields' => 'sometimes|string|regex:/^[a-z0-9_,.]+$/',
            'updated_since' => 'sometimes|date',
            'q' => 'sometimes|string|min:1|max:80',
            'lang' => 'sometimes|string',
            'format' => 'sometimes|in:json,csv',
        ] + $filterRules)->validate();

        $sort = $data['sort'] ?? null;
        $filters = array_intersect_key($data, $filterRules);

        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? config('core.per_page_default', 25)),
            sort: $sort === null ? null : ltrim($sort, '-'),
            sortDesc: $sort !== null && str_starts_with($sort, '-'),
            fields: isset($data['fields']) ? array_values(array_filter(explode(',', $data['fields']))) : [],
            updatedSince: isset($data['updated_since']) ? CarbonImmutable::parse($data['updated_since'])->utc() : null,
            filters: $filters,
            lang: $lang,
            q: $data['q'] ?? null,
        );
    }
}
