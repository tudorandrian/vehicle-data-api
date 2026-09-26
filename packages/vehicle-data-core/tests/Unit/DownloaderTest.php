<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use VehicleData\Core\Importers\Downloader;

it('downloads a file to storage and returns its path and sha256 checksum', function (): void {
    Http::fake(['https://example.org/data.csv' => Http::response("a,b\n1,2\n", 200)]);

    $result = Downloader::fetch('https://example.org/data.csv', 'fake-source', 'data.csv');

    expect($result['path'])->toEndWith(DIRECTORY_SEPARATOR.'data.csv')
        ->and(File::exists($result['path']))->toBeTrue()
        ->and($result['sha256'])->toBe(hash('sha256', "a,b\n1,2\n"));

    File::deleteDirectory(dirname($result['path'], 2));
});

it('sanitises a path-traversal filename down to its basename', function (): void {
    Http::fake(['https://example.org/data.csv' => Http::response('x', 200)]);

    $result = Downloader::fetch('https://example.org/data.csv', 'fake-source', '../../evil.csv');

    expect($result['path'])->toEndWith(DIRECTORY_SEPARATOR.'evil.csv')->and($result['path'])->not->toContain('..');

    File::deleteDirectory(dirname($result['path'], 2));
});

it('rejects a filename that is empty or resolves to . or ..', function (string $filename): void {
    expect(fn () => Downloader::fetch('https://example.org/data.csv', 'fake-source', $filename))
        ->toThrow(InvalidArgumentException::class);
})->with(['', '.', '..', '../']);
