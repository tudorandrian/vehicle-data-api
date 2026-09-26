<?php

declare(strict_types=1);

namespace VehicleData\Core\Auth;

use Illuminate\Support\Facades\Cache;
use VehicleData\Core\Contracts\ClientResolver;
use VehicleData\Core\Models\ApiClient;

final class DatabaseClientResolver implements ClientResolver
{
    /**
     * Cache stores are configured with serializable_classes=false (config/cache.php), so an
     * object put through Cache::remember() would come back as __PHP_Incomplete_Class on the
     * database store and blow up the ?ResolvedClient return type. Cache a plain array with
     * only the scalar/array fields ResolvedClient needs, and rebuild the object on every read.
     */
    public function resolve(string $bearer): ?ResolvedClient
    {
        if (! ApiKey::looksValid($bearer)) {
            return null;
        }
        $hash = ApiKey::hash($bearer);
        $cacheKey = 'client:'.$hash;

        // is_array(), not trusting the annotation: anything else cached under this key (or a
        // store returning a stale/foreign shape) falls through to a fresh lookup instead of
        // being handed to fromArray() on faith.
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            // A resolver can repopulate this entry after revoke/rotate has
            // cleared it. Cached metadata is never authority for key validity.
            // Use the primary connection so replica lag cannot undo revocation.
            $active = ApiClient::query()->useWritePdo()->where('key_hash', $hash)
                ->whereNull('disabled_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
            if (! $active) {
                Cache::forget($cacheKey);

                return null;
            }

            /** @var array{id:int,name:string,keyPrefix:string,scopes:list<string>,allowedOrigins:list<string>,ratePerMinute:int,dailyQuota:int} $cached */
            return self::fromArray($cached);
        }

        $client = ApiClient::query()->useWritePdo()->with('scopes')->where('key_prefix', ApiKey::prefix($bearer))->first();
        if ($client === null || ! hash_equals($client->key_hash, $hash)) {
            // Never cached: an unknown/malformed key must be re-checked (and IP-throttled)
            // on every attempt, never parked in the cache table as a "miss" entry.
            return null;
        }
        if ($client->disabled_at !== null || ($client->expires_at !== null && $client->expires_at->isPast())) {
            return null;
        }

        /** @var list<string> $scopes */
        $scopes = $client->scopes->pluck('scope')->values()->all();
        /** @var list<string> $allowedOrigins */
        $allowedOrigins = array_values((array) $client->allowed_origins);

        $data = [
            'id' => $client->id,
            'name' => $client->name,
            'keyPrefix' => $client->key_prefix,
            'scopes' => $scopes,
            'allowedOrigins' => $allowedOrigins,
            'ratePerMinute' => $client->rate_per_minute,
            'dailyQuota' => $client->daily_quota,
        ];

        // Cached for at most a minute, capped to the key's remaining lifetime so an expiring
        // key never outlives its own expires_at while sitting in the cache; revoke/rotate
        // forget the entry; the primary-database check above also rejects late
        // cache writes that raced with those commands.
        $ttl = 60;
        if ($client->expires_at !== null) {
            $secondsUntilExpiry = $client->expires_at->getTimestamp() - now()->getTimestamp();
            if ($secondsUntilExpiry <= 0) {
                return null;
            }
            $ttl = min(60, $secondsUntilExpiry);
        }
        Cache::put($cacheKey, $data, $ttl);

        return self::fromArray($data);
    }

    /** @param array{id:int,name:string,keyPrefix:string,scopes:list<string>,allowedOrigins:list<string>,ratePerMinute:int,dailyQuota:int} $data */
    private static function fromArray(array $data): ResolvedClient
    {
        return new ResolvedClient(
            id: $data['id'],
            name: $data['name'],
            keyPrefix: $data['keyPrefix'],
            scopes: $data['scopes'],
            allowedOrigins: $data['allowedOrigins'],
            ratePerMinute: $data['ratePerMinute'],
            dailyQuota: $data['dailyQuota'],
        );
    }
}
