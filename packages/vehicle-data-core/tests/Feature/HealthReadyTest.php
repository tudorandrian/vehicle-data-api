<?php

declare(strict_types=1);

it('reports database, cache, queue and imports for a keyed caller', function (): void {
    [, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk()
        ->assertJsonPath('data.status', 'ok')->assertJsonPath('data.checks.database', 'ok')->assertJsonPath('data.checks.cache', 'ok')
        ->assertJsonStructure(['data' => ['checks' => ['database', 'cache', 'queue_backlog', 'imports']]]);
});
