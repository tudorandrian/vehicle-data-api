<?php

declare(strict_types=1);

it('renders the self-hosted Scalar page with a strict CSP and no key by default', function (): void {
    $res = $this->get('/docs')->assertOk()
        ->assertSee('vendor/scalar/standalone.js', false)->assertSee('js/docs.js', false)->assertSee('/openapi.yaml', false)
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
    $csp = (string) $res->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")->toContain("script-src 'self';")->toContain("connect-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain("script-src 'self' 'unsafe-inline'")->not->toContain('http')->not->toContain('*')
        ->and($res->getContent())->not->toContain('vd_live_')->not->toContain('<script>');
});

it('passes the configured Try-it key to the page', function (): void {
    config(['core.docs_try_it_key' => 'vd_live_exampleexampleexampleexampleexample00']);
    $this->get('/docs')->assertOk()->assertSee('data-vd-try-it-key="vd_live_exampleexampleexampleexampleexample00"', false);
});

it('keeps the API CSP on /v1 while /docs gets its own', function (): void {
    $this->get('/v1/health')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});

it('ships the page initialiser and redirects the root to /docs', function (): void {
    expect(file_get_contents(public_path('js/docs.js')))->toContain('Scalar.createApiReference')->toContain('data-vd-try-it-key');
    $this->get('/')->assertRedirect('/docs');
});

it('serves /docs without session, cookie or CSRF middleware', function (): void {
    $res = $this->get('/docs')->assertOk();
    expect($res->headers->getCookies())->toBe([])
        ->and($res->headers->has('Set-Cookie'))->toBeFalse();
});
