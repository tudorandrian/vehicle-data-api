<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use VehicleData\Core\Models\Manufacturer;

it('returns a weak ETag and 304 on a matching If-None-Match', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'seat', 'name' => 'SEAT']);
    $first = $this->getJson('/v1/manufacturers/seat', bearer($key))->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->toMatch('/^W\/"[0-9a-f]{64}"$/');
    $this->getJson('/v1/manufacturers/seat', bearer($key) + ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag)->assertNoContent(304);
});

it('matches weakly: the strong form of the same tag, a tag list and * all revalidate', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'seat', 'name' => 'SEAT']);
    $etag = (string) $this->getJson('/v1/manufacturers/seat', bearer($key))->headers->get('ETag');
    $strong = substr($etag, 2); // "<hex>" without the W/ prefix
    $this->getJson('/v1/manufacturers/seat', bearer($key) + ['If-None-Match' => $strong])->assertStatus(304);
    $this->getJson('/v1/manufacturers/seat', bearer($key) + ['If-None-Match' => '"nope", '.$etag])->assertStatus(304);
    $this->getJson('/v1/manufacturers/seat', bearer($key) + ['If-None-Match' => '*'])->assertStatus(304);
    $this->getJson('/v1/manufacturers/seat', bearer($key) + ['If-None-Match' => '"nope"'])->assertOk();
});

it('answers HEAD with the ETag and a bodiless 304', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'seat', 'name' => 'SEAT']);
    $etag = (string) $this->getJson('/v1/manufacturers/seat', bearer($key))->headers->get('ETag');
    $head = $this->call('HEAD', '/v1/manufacturers/seat', [], [], [], $this->transformHeadersToServerVars(bearer($key)));
    $head->assertOk()->assertHeader('ETag', $etag);
    expect($head->getContent())->toBe('');
    $this->call('HEAD', '/v1/manufacturers/seat', [], [], [], $this->transformHeadersToServerVars(bearer($key) + ['If-None-Match' => $etag]))->assertStatus(304)->assertNoContent(304)->assertHeader('ETag', $etag);
});

it('never sets an ETag on errors, CSV or snapshots', function (): void {
    [, $key] = keyed(['catalogue:read', 'snapshot:read']);
    expect($this->getJson('/v1/manufacturers/none', bearer($key))->assertStatus(404)->headers->has('ETag'))->toBeFalse();
    expect($this->get('/v1/manufacturers?format=csv', bearer($key))->assertOk()->headers->has('ETag'))->toBeFalse();
    expect($this->get('/v1/snapshots/manufacturers', bearer($key))->assertOk()->headers->has('ETag'))->toBeFalse();
});

it('is stable across time and varies by language, with Vary including Accept-Language and Origin', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'cupra', 'name' => 'Cupra']);

    // Accept-Language is cleared explicitly so these two calls resolve to the default locale
    // ('ro') rather than Symfony's built-in "en-us,en;q=0.5" default - see the note in
    // TaxonomyEndpointsTest.
    Carbon::setTestNow('2026-01-01 00:00:00');
    $before = $this->getJson('/v1/manufacturers/cupra', bearer($key) + ['Accept-Language' => ''])->assertOk();

    Carbon::setTestNow('2026-01-01 00:00:01');
    $after = $this->getJson('/v1/manufacturers/cupra', bearer($key) + ['Accept-Language' => ''])->assertOk();
    Carbon::setTestNow();

    expect($before->headers->get('ETag'))->toBe($after->headers->get('ETag'));

    $enResponse = $this->getJson('/v1/manufacturers/cupra?lang=en', bearer($key) + ['Accept-Language' => ''])->assertOk();
    expect($enResponse->headers->get('ETag'))->not->toBe($after->headers->get('ETag'));

    // Vary may be sent as multiple header lines; join them to check both are present.
    $vary = implode(', ', $after->headers->all('Vary'));
    expect($vary)->toContain('Accept-Language')->toContain('Origin');
});
