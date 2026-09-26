<?php

declare(strict_types=1);

it('answers the framework health route', function (): void {
    $this->get('/up')->assertOk();
});
