<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use VehicleData\Core\Models\Manufacturer;

it('renders 404 as problem+json with a request id', function (): void {
    $res = $this->getJson('/v1/does-not-exist');
    $res->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
    $res->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'request_id'])
        ->assertJsonPath('status', 404)->assertJsonPath('instance', '/v1/does-not-exist');
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    expect($res->json('request_id'))->toBe($res->headers->get('X-Request-Id'));
});

it('renders 405 for a write verb', function (): void {
    $this->postJson('/v1/health')->assertStatus(405)->assertHeader('Content-Type', 'application/problem+json');
});

it('renders 413 when the declared body exceeds the cap', function (): void {
    $this->call('GET', '/v1/health', [], [], [], ['CONTENT_LENGTH' => '70000'])
        ->assertStatus(413)->assertHeader('Content-Type', 'application/problem+json');
});

it('never leaks a stack trace on 500', function (): void {
    Route::get('/v1/_boom', fn () => throw new RuntimeException('secret detail'))->middleware('core');
    $res = $this->getJson('/v1/_boom');
    $res->assertStatus(500)->assertJsonPath('title', 'Internal Server Error');
    expect($res->getContent())->not->toContain('secret detail')->not->toContain('trace');
});

it('does not leak the model class name on a 404 for a missing model', function (): void {
    Route::get('/v1/_missing-model', function (): never {
        throw (new ModelNotFoundException)->setModel(Manufacturer::class);
    })->middleware('core');
    $res = $this->getJson('/v1/_missing-model');
    $res->assertStatus(404)->assertJsonPath('type', '/problems/not-found');
    expect($res->getContent())->not->toContain('Manufacturer');
});
