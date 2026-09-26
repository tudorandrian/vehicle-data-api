<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use VehicleData\Core\Models\Source;

final class HealthController
{
    public function live(): JsonResponse
    {
        $response = new JsonResponse(['data' => [
            'status' => 'ok',
            'version' => (string) config('app.version', 'dev'),
            'time' => now()->toIso8601String(),
        ]], 200, ['Cache-Control' => 'no-store, private']);

        return $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ready(): JsonResponse
    {
        /** @var array{database: string, cache: string, queue_backlog: int, imports: array<string, mixed>} $checks */
        $checks = ['database' => 'ok', 'cache' => 'ok', 'queue_backlog' => 0, 'imports' => []];
        $status = 200;
        try {
            DB::select('SELECT 1');
            $checks['queue_backlog'] = (int) DB::table('jobs')->count();

            $imports = [];
            $rows = DB::table('vd_import_runs')->where('status', 'succeeded')
                ->selectRaw('source_id, MAX(finished_at) AS last')->groupBy('source_id')->get();
            foreach ($rows as $row) {
                $key = Source::query()->whereKey($row->source_id)->value('key');
                if (is_string($key)) {
                    $imports[$key] = $row->last;
                }
            }
            $checks['imports'] = $imports;
        } catch (Throwable) {
            $checks['database'] = 'fail';
            $status = 503;
        }
        try {
            Cache::put('health-probe', 1, 10);
            if (Cache::get('health-probe') !== 1) {
                $checks['cache'] = 'fail';
            }
        } catch (Throwable) {
            $checks['cache'] = 'fail';
        }

        if ($checks['cache'] === 'fail') {
            $status = 503;
        }

        $response = new JsonResponse(['data' => ['status' => $status === 200 ? 'ok' : 'degraded', 'checks' => $checks, 'time' => now()->toIso8601String()]], $status, ['Cache-Control' => 'no-store, private']);

        return $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
