<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__error-page/{code}', fn (int $code) => abort($code, $code === 403 ? 'Your plan does not include paid tickets.' : ''));
});

test('a missing page renders the branded error page, not the Metronic one', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Error 404')
        ->assertDontSee('style.bundle.css', false)
        ->assertDontSee('plugins.bundle.js', false);
});

test('a forbidden page shows the reason it was refused', function () {
    $this->get('/__error-page/403')
        ->assertForbidden()
        ->assertSee('Access denied')
        ->assertSee('Your plan does not include paid tickets.');
});

test('every error page renders standalone, without the build manifest or Metronic assets', function (int $code) {
    $this->get("/__error-page/{$code}")
        ->assertStatus($code)
        ->assertSee("Error {$code}")
        ->assertDontSee('/build/', false)
        ->assertDontSee('style.bundle.css', false);
})->with([401, 403, 404, 419, 429, 500, 503]);
