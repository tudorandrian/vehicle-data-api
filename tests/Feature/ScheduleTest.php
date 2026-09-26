<?php

declare(strict_types=1);

it('registers the single-cron schedule', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('queue:work --stop-when-empty --max-time=50')->expectsOutputToContain('vehicle:usage aggregate')
        ->expectsOutputToContain('vehicle:usage purge-ip')->expectsOutputToContain('vehicle:import wikidata')->expectsOutputToContain('vehicle:status');
});
