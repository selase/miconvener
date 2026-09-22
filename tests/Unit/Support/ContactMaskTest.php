<?php

declare(strict_types=1);

use App\Support\ContactMask;

test('an address keeps two letters and its domain', function () {
    expect(ContactMask::email('ama.serwaa@stem.org'))->toBe('am••••••••@stem.org');
});

test('a very short address still hides something', function () {
    expect(ContactMask::email('a@stem.org'))->toBe('a•@stem.org');
});
