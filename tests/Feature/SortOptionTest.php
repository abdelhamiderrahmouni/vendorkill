<?php

declare(strict_types=1);

it('rejects an invalid sort mode for process', function () {
    $this->artisan('process --sort=weird')
        ->expectsOutput('--sort must be one of: default, name, size, modified.')
        ->assertExitCode(1);
});

it('rejects an invalid sort mode for cache', function () {
    $this->artisan('cache --sort=weird')
        ->expectsOutput('--sort must be one of: default, name, size, modified.')
        ->assertExitCode(1);
});
