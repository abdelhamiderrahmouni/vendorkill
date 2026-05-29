<?php

declare(strict_types=1);

use App\Services\ConfigService;

it('includes rust target in built-in folder types', function () {
    $types = ConfigService::FOLDER_TYPES;

    expect($types)->toHaveKey('target');
    expect($types['target'])->toMatchArray([
        'label' => 'target  (Cargo build output)',
        'default' => true,
        'names' => ['target'],
        'paths' => [],
        'manifests' => ['Cargo.toml'],
        'lockfiles' => ['Cargo.toml', 'Cargo.lock'],
    ]);
});

it('can resolve target type via config service', function () {
    $service = new ConfigService();
    $allTypes = $service->getAllTypes();

    expect($allTypes)->toHaveKey('target');
    expect($allTypes['target']['label'])->toBe('target  (Cargo build output)');
});

it('accepts --rust flag on process command', function () {
    // Use a temp dir that has no target/ directories
    $tempDir = sys_get_temp_dir() . '/cnkill-rust-test-' . uniqid();
    mkdir($tempDir, 0777, true);

    $this->artisan('process ' . $tempDir . ' --rust')
        ->assertSuccessful();

    rmdir($tempDir);
});
