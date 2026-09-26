<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('generates a request id when none is sent', function (): void {
    $res = $this->getJson('/v1/health');
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('echoes a well-formed client request id and ignores a malformed one', function (): void {
    $this->getJson('/v1/health', ['X-Request-Id' => '3f1c2a0e-1111-4222-8333-444455556666'])
        ->assertHeader('X-Request-Id', '3f1c2a0e-1111-4222-8333-444455556666');
    $res = $this->getJson('/v1/health', ['X-Request-Id' => '<script>']);
    expect($res->headers->get('X-Request-Id'))->not->toBe('<script>');
});

it('does not regenerate the id on a matched v1 route, where RequestId runs both globally and via the core group', function (): void {
    Route::get('/v1/_request-id-echo', fn (Request $request) => ['seen_by_controller' => $request->attributes->get('request_id')])
        ->middleware('core');

    $sent = '3f1c2a0e-1111-4222-8333-444455556666';
    $res = $this->getJson('/v1/_request-id-echo', ['X-Request-Id' => $sent]);

    // Exactly one X-Request-Id header value on the response (no duplicate
    // header line from the middleware running twice), and it is the id both
    // the controller saw and the client sent — not a second, freshly
    // generated uuid from the second (route-group) middleware pass.
    expect($res->headers->all('X-Request-Id'))->toBe([$sent]);
    expect($res->json('seen_by_controller'))->toBe($sent);
});
