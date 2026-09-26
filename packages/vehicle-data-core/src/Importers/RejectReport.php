<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final class RejectReport
{
    /** @var list<array{ref:string, rule:string, raw:array<string,mixed>}> */
    private array $items = [];

    /** @var array<string,int> */
    private array $byRule = [];

    /** @param array<string,mixed> $raw */
    public function add(string $ref, string $rule, array $raw): void
    {
        $this->byRule[$rule] = ($this->byRule[$rule] ?? 0) + 1;
        if (count($this->items) < 500) {
            $this->items[] = ['ref' => $ref, 'rule' => $rule, 'raw' => array_slice($raw, 0, 12, true)];
        }
    }

    public function count(): int
    {
        return array_sum($this->byRule);
    }

    /** @return array{summary: array<string,int>, samples: list<array{ref:string, rule:string, raw:array<string,mixed>}>} */
    public function toArray(): array
    {
        return ['summary' => $this->byRule, 'samples' => $this->items];
    }
}
