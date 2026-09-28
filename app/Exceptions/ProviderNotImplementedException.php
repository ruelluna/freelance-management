<?php

namespace App\Exceptions;

use App\Enums\Provider;
use RuntimeException;

class ProviderNotImplementedException extends RuntimeException
{
    public function __construct(Provider $provider)
    {
        parent::__construct($provider->label().' is not implemented yet.');
    }
}
