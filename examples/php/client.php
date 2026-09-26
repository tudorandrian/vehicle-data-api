<?php

declare(strict_types=1);

// A compact client: bearer key, problem parsing, weak-ETag revalidation. Run: VD_API_KEY=… php examples/php/client.php

final class ApiProblem extends RuntimeException
{
    /** @param array<string, mixed> $problem */
    public function __construct(public readonly array $problem)
    {
        parent::__construct(sprintf('%s (%d) on %s, request %s', $problem['type'], $problem['status'], $problem['instance'], $problem['request_id']));
    }
}

final class VehicleDataClient
{
    /** @var array<string, array{etag: string, body: array<string, mixed>}> */
    private array $cache = [];

    public int $lastStatus = 0;

    public function __construct(private readonly string $base, private readonly string $key) {}

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        $headers = ['Authorization: Bearer '.$this->key, 'Accept: application/json'];
        if (isset($this->cache[$path])) {
            $headers[] = 'If-None-Match: '.$this->cache[$path]['etag'];
        }
        $ch = curl_init($this->base.$path);
        // Redirects (a retired slug answers 301) are followed only on the base URL's scheme, and
        // libcurl never forwards this Authorization header to another host, port or scheme
        // (CURLOPT_UNRESTRICTED_AUTH stays off).
        $scheme = parse_url($this->base, PHP_URL_SCHEME) === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_REDIR_PROTOCOLS => $scheme, CURLOPT_UNRESTRICTED_AUTH => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatus = $status;
        if ($status === 304) {
            return $this->cache[$path]['body'];
        }
        $body = json_decode(substr($raw, $headerSize), true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
            throw new ApiProblem($body);
        }
        if (preg_match('/^ETag: (.+)$/mi', substr($raw, 0, $headerSize), $m) === 1) {
            $this->cache[$path] = ['etag' => trim($m[1]), 'body' => $body];
        }

        return $body;
    }
}

$key = getenv('VD_API_KEY') ?: throw new RuntimeException('set VD_API_KEY');
$api = new VehicleDataClient(getenv('VD_BASE_URL') ?: 'http://localhost:8087', $key);

$tax = $api->get('/v1/taxonomies');
echo '1. ', implode(' ', array_map(fn (array $t): string => "{$t['name']}={$t['id']}", $tax['data'])), "\n";
$dacia = $api->get('/v1/makes/dacia');
echo '2. ', $dacia['data']['id'], ' ', implode(',', array_column($dacia['sources'], 'key')), "\n";
echo '3. by id → ', $api->get('/v1/makes/'.$dacia['data']['id'])['data']['slug'], "\n";
$models = $api->get('/v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3');
echo '4. ', implode(' ', array_map(fn (array $m): string => $m['slug'].':'.($m['ro_fleet']['count'] ?? '-'), $models['data'])), "\n";
$diesel = $api->get('/v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d');
echo '5. ', implode(' ', array_map(fn (array $v): string => "{$v['id']} {$v['engine_cc']}cc {$v['power_kw']}kW {$v['co2_wltp']}g {$v['euro_norm']['code']}", $diesel['data'])), "\n";
$n1 = $api->get('/v1/models/fiat-ducato/variants?eu_category=n1');
echo '6. ', implode(' ', array_map(fn (array $v): string => "{$v['id']} {$v['eu_category']['code']}", $n1['data'])), "\n";
$nat = $api->get('/v1/taxonomies/national_category?lang=en');
echo '7. ', implode(' ', array_map(fn (array $t): string => "{$t['code']}={$t['label']}", array_slice($nat['data']['terms'], 0, 3))), "\n";
$vin = $api->get('/v1/vin/WVWZZZ3CZWE000001');
echo '8. ', $vin['data']['wmi'], ' ', $vin['data']['manufacturer']['name'], "\n";

// Step 9 deliberately triggers the RFC 9457 problem response; catching ApiProblem here
// means the walkthrough is not aborted by it.
try {
    $api->get('/v1/makes?per_page=500');
    throw new RuntimeException('expected the 422 problem, got success');
} catch (ApiProblem $e) {
    $p = $e->problem;
    echo '9. ', $p['type'], ' ', $p['status'], ' ', $p['errors'][0]['field'], "\n";
    if ((int) $p['status'] !== 422) {
        throw new RuntimeException("expected 422, got {$p['status']}");
    }
}

// Step 10: the weak ETag must answer 304 with no body.
$api->get('/v1/makes/dacia');
if ($api->lastStatus !== 304) {
    throw new RuntimeException("expected 304 on revalidation, got {$api->lastStatus}");
}
echo "10. revalidated → 304 (cached body reused)\n";

echo "OK: 10 calls, last id {$dacia['data']['id']}\n";
