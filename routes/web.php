<?php

declare(strict_types=1);

use App\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

// A static page for anonymous visitors: no session, cookies or CSRF token.
Route::get('/docs', DocsController::class)->withoutMiddleware('web')->name('docs');
Route::redirect('/', '/docs');
