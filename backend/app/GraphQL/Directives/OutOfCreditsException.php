<?php

namespace App\GraphQL\Directives;

use GraphQL\Error\ClientAware;

class OutOfCreditsException extends \RuntimeException implements ClientAware
{
    public function __construct()
    {
        parent::__construct('Out of AI credits.');
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
