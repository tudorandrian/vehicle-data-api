<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use VehicleData\Core\Contracts\Enricher;

final class Enrichers
{
    /** @param iterable<Enricher> $enrichers */
    public function __construct(private readonly iterable $enrichers) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $protected
     * @return array<string,mixed>
     */
    public function apply(string $resource, Model $record, array $payload, EnrichmentContext $ctx, array $protected): array
    {
        foreach ($this->enrichers as $enricher) {
            if (! $enricher->supports($resource)) {
                continue;
            }
            $out = $enricher->enrich($record, $payload, $ctx);
            foreach ($protected as $key) {
                if (! array_key_exists($key, $out) || $out[$key] !== ($payload[$key] ?? null)) {
                    throw new LogicException(sprintf('%s may not change the protected key "%s" on %s.', $enricher::class, $key, $resource));
                }
            }
            $payload = $out;
        }

        return $payload;
    }
}
