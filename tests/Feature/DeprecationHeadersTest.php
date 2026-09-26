<?php

declare(strict_types=1);

it('adds Deprecation, Sunset and Link headers for a configured prefix only', function (): void {
    config()->set('core.deprecations', ['/v1/health' => ['deprecation' => '@1767225600', 'sunset' => 'Sat, 01 Jan 2028 00:00:00 GMT', 'link' => 'https://example.org/docs#v2']]);
    $this->getJson('/v1/health')->assertHeader('Deprecation', '@1767225600')->assertHeader('Sunset', 'Sat, 01 Jan 2028 00:00:00 GMT')->assertHeader('Link', '<https://example.org/docs#v2>; rel="deprecation"');
    $this->get('/openapi.yaml')->assertHeaderMissing('Deprecation');
});
