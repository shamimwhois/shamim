<?php

use App\Enums\ProductLicense;

/*
|--------------------------------------------------------------------------
| ProductLicense
|--------------------------------------------------------------------------
|
| The licence is a promise a buyer reads before paying. These tests pin the
| two facts the storefront derives from it — whether the code is open source
| and whether commercial use is permitted — because getting either wrong is a
| legal problem, not a display problem.
|
*/

test('open-source licences are identified as such', function () {
    foreach ([ProductLicense::Mit, ProductLicense::Apache, ProductLicense::Gpl] as $license) {
        expect($license->isOpenSource())->toBeTrue("[{$license->value}] should be open source.");
    }

    foreach ([ProductLicense::Personal, ProductLicense::Commercial, ProductLicense::Extended] as $license) {
        expect($license->isOpenSource())->toBeFalse("[{$license->value}] is not open source.");
    }
});

test('only the personal licence forbids commercial use', function () {
    expect(ProductLicense::Personal->allowsCommercialUse())->toBeFalse();

    foreach ([ProductLicense::Mit, ProductLicense::Apache, ProductLicense::Gpl, ProductLicense::Commercial, ProductLicense::Extended] as $license) {
        expect($license->allowsCommercialUse())->toBeTrue("[{$license->value}] permits commercial use.");
    }
});

test('every licence has a short label and a full description', function () {
    foreach (ProductLicense::cases() as $license) {
        expect($license->label())->not->toBe('')
            ->and($license->description())->not->toBe('');
    }
});

test('licence labels are distinct', function () {
    $labels = array_map(fn (ProductLicense $license): string => $license->label(), ProductLicense::cases());

    expect(array_unique($labels))->toHaveCount(count($labels));
});

test('the options list exposes every case once', function () {
    $options = ProductLicense::options();

    expect($options)->toHaveCount(count(ProductLicense::cases()))
        ->and(array_column($options, 'value'))->toBe(array_map(
            fn (ProductLicense $license): string => $license->value,
            ProductLicense::cases(),
        ));
});
