<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class Downloader
{
    /** @return array{path:string, sha256:string} */
    public static function fetch(string $url, string $sourceKey, string $filename): array
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new InvalidArgumentException("Invalid file name [{$filename}].");
        }

        $dir = storage_path('app/imports/'.$sourceKey.'/'.now()->toDateString());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$safeName;
        Http::withOptions(['sink' => $path])->timeout(600)->retry(3, 2000)->withUserAgent('vehicle-data-api/1.0 (+open-data importer)')->get($url)->throw();

        return ['path' => $path, 'sha256' => (string) hash_file('sha256', $path)];
    }
}
