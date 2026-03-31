<?php

declare(strict_types=1);

it('lists available commands', function () {
    $this->artisan('list')->assertExitCode(0);
});
