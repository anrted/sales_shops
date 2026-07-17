<?php

namespace App\Exceptions;

use RuntimeException;

class ParseRunCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Parsing was cancelled by user.');
    }
}
