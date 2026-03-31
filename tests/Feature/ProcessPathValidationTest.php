<?php

declare(strict_types=1);

it('fails when process path does not exist', function () {
    $missingPath = '/tmp/cnkill-path-that-does-not-exist';

    $this->artisan('process ' . $missingPath)
        ->expectsOutput('The provided path does not exist or is not a directory: ' . $missingPath)
        ->assertExitCode(1);
});
