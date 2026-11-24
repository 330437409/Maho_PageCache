<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('accepts the supported page cache modes', function () {
    $model = new Maho_PageCache_Model_System_Config_Backend_Mode();

    expect($model->isValidMode('proxy'))->toBeTrue()
        ->and($model->isValidMode('revalidating'))->toBeTrue()
        ->and($model->isValidMode('local'))->toBeTrue()
        ->and($model->isValidMode(''))->toBeFalse();
});
