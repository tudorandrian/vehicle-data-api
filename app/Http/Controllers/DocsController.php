<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The Scalar API reference. Its Content-Security-Policy is set by the global
 * SecurityHeaders middleware (SecurityHeaders::docsHeaders()), which runs after
 * this controller and would overwrite anything set here.
 */
final class DocsController
{
    public function __invoke(): Response
    {
        return response()->view('docs', ['tryItKey' => (string) config('core.docs_try_it_key', '')]);
    }
}
