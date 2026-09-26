<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;
use ReflectionClass;
use VehicleData\Core\Auth\ApiKey;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Models\ApiClient;

final class ClientCommand extends Command
{
    /** Last date whose midnight UTC still fits a MariaDB TIMESTAMP (max 2038-01-19 03:14:07 UTC). */
    private const MAX_EXPIRES = '2038-01-19';

    protected $signature = 'vehicle:client {action : create|rotate|revoke|list|show}
        {--id=} {--name=} {--owner=} {--scopes=catalogue:read} {--origins=} {--rate=60} {--quota=10000} {--expires=}';

    protected $description = 'Manage API clients and their bearer keys (keys are printed once and never stored in clear).';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'create' => $this->create(),
            'rotate' => $this->rotate(),
            'revoke' => $this->revoke(),
            'list' => $this->list(),
            'show' => $this->show(),
            default => $this->unknown(),
        };
    }

    private function unknown(): int
    {
        $this->error('Unknown action');

        return self::INVALID;
    }

    private function create(): int
    {
        $errors = [];
        $name = trim((string) $this->option('name'));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = 'name: required, at most 80 characters';
        }
        $owner = trim((string) $this->option('owner'));
        if ($owner === '' || mb_strlen($owner) > 120) {
            $errors[] = 'owner: required, at most 120 characters';
        }
        $rateOption = filter_var($this->option('rate'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($rateOption === false) {
            $errors[] = 'rate: integer between 1 and 65535';
        }
        $quotaOption = filter_var($this->option('quota'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($quotaOption === false) {
            $errors[] = 'quota: integer between 1 and 4294967295';
        }
        $origins = [];
        $badOrigins = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('origins')))) as $origin) {
            $lower = strtolower($origin);
            if (preg_match('#^(https?)://([a-z0-9.-]+)(?::(\d{1,5}))?$#', $lower, $matches) !== 1) {
                $badOrigins[] = $this->redactOrigin($origin);

                continue;
            }
            $scheme = $matches[1];
            $port = $matches[3] ?? null;
            if ($port !== null) {
                $portNumber = (int) $port;
                $defaultPort = $scheme === 'https' ? 443 : 80;
                if ($portNumber < 1 || $portNumber > 65535 || $portNumber === $defaultPort) {
                    $badOrigins[] = $this->redactOrigin($origin);

                    continue;
                }
            }
            $origins[] = $lower;
        }
        $origins = array_values(array_unique($origins));
        if ($badOrigins !== []) {
            $errors[] = 'origins: each must be http(s)://host[:port] with no path, and no default port ('.implode(', ', $badOrigins).')';
        }
        $expires = null;
        $expiresOption = $this->option('expires');
        if ($expiresOption !== null && $expiresOption !== '') {
            $expiresOption = (string) $expiresOption;
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $expiresOption);
            } catch (InvalidFormatException) {
                $parsed = null;
            }
            // createFromFormat() only throws for an unparseable string; a
            // calendar-invalid date such as 2027-02-30 parses without error
            // and silently rolls over to 2027-03-02, so require a round
            // trip back to the exact input to catch it.
            if ($parsed !== null && $parsed->format('Y-m-d') !== $expiresOption) {
                $parsed = null;
            }
            if ($parsed === null || ! $parsed->isFuture()) {
                $errors[] = 'expires: a date in the future (YYYY-MM-DD)';
            } elseif ($parsed->format('Y-m-d') > self::MAX_EXPIRES) {
                $errors[] = 'expires: must be on or before '.self::MAX_EXPIRES.' (MariaDB TIMESTAMP range)';
            } else {
                $expires = $parsed;
            }
        }
        $scopes = null;
        $scopesResult = $this->parseScopes((string) $this->option('scopes'));
        if (is_string($scopesResult)) {
            $errors[] = 'scopes: '.$scopesResult;
        } else {
            $scopes = $scopesResult;
        }
        if ($errors !== [] || $rateOption === false || $quotaOption === false || $scopes === null) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $rate = $rateOption;
        $quota = $quotaOption;
        $key = ApiKey::generate();
        $client = DB::transaction(function () use ($name, $owner, $key, $origins, $rate, $quota, $expires, $scopes): ApiClient {
            $client = ApiClient::query()->create([
                'name' => $name, 'owner' => $owner,
                'key_prefix' => ApiKey::prefix($key), 'key_hash' => ApiKey::hash($key),
                'allowed_origins' => $origins,
                'rate_per_minute' => $rate, 'daily_quota' => $quota,
                'expires_at' => $expires,
            ]);
            foreach ($scopes as $scope) {
                $client->scopes()->create(['scope' => $scope]);
            }

            return $client;
        });
        $this->info("Client #{$client->id} created. Key (shown once):");
        $this->line($key);

        return self::SUCCESS;
    }

    private function redactOrigin(string $origin): string
    {
        return (string) preg_replace('#^((?:[a-z][a-z0-9+.-]*://)?)[^@/]*@#i', '$1…@', $origin);
    }

    private function rotate(): int
    {
        $client = $this->requireClient();
        if ($client === null) {
            return self::INVALID;
        }

        $oldHash = $client->key_hash;
        Cache::forget('client:'.$oldHash);
        $key = ApiKey::generate();
        $client->forceFill(['key_prefix' => ApiKey::prefix($key), 'key_hash' => ApiKey::hash($key)])->save();
        Cache::forget('client:'.$oldHash);
        $this->info("Client #{$client->id} rotated. New key (shown once):");
        $this->line($key);

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $client = $this->requireClient();
        if ($client === null) {
            return self::INVALID;
        }

        Cache::forget('client:'.$client->key_hash);
        $client->forceFill(['disabled_at' => now()])->save();
        Cache::forget('client:'.$client->key_hash);
        $this->info('Revoked.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $this->table(['id', 'name', 'owner', 'prefix', 'rate/min', 'quota/day', 'last used', 'disabled'],
            ApiClient::query()->orderBy('id')->get()->map(fn (ApiClient $c) => [$c->id, $c->name, $c->owner, $c->key_prefix, $c->rate_per_minute, $c->daily_quota, $c->last_used_at?->toDateTimeString() ?? '-', $c->disabled_at ? 'yes' : 'no'])->all());

        return self::SUCCESS;
    }

    /** @throws JsonException */
    private function show(): int
    {
        $client = $this->requireClient(withScopes: true);
        if ($client === null) {
            return self::INVALID;
        }

        // toArray() already includes the eager-loaded 'scopes' relation as
        // an array of the raw ApiClientScope records; unset it before
        // re-adding the flat plucked scope list, otherwise the '+' merge
        // below would keep the relation's array (the left operand wins on a
        // shared key) and the plucked list would never actually appear.
        $data = $client->toArray();
        unset($data['scopes']);
        $data += ['scopes' => $client->scopes->pluck('scope')->values()->all()];

        $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function requireClient(bool $withScopes = false): ?ApiClient
    {
        $id = $this->option('id');
        if (! is_numeric($id) || (int) $id <= 0 || (string) (int) $id !== (string) $id) {
            $this->error('A valid --id is required.');

            return null;
        }

        $query = ApiClient::query();
        if ($withScopes) {
            $query->with('scopes');
        }

        $client = $query->find((int) $id);
        if ($client === null) {
            $this->error("No client found with id {$id}.");

            return null;
        }

        return $client;
    }

    /** @return list<string>|string list of scopes, or an error message on an unknown/empty scope list */
    private function parseScopes(string $raw): array|string
    {
        $scopes = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $valid = $this->validScopes();

        if ($scopes === []) {
            return 'at least one scope is required';
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope, $valid, true)) {
                return "unknown scope '{$scope}' (valid: ".implode(', ', $valid).')';
            }
        }

        return array_values(array_unique($scopes));
    }

    /** @return list<string> */
    private function validScopes(): array
    {
        $constants = (new ReflectionClass(ResolvedClient::class))->getConstants();

        $scopes = [];
        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'SCOPE_') && is_string($value)) {
                $scopes[] = $value;
            }
        }

        return $scopes;
    }
}
