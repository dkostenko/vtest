<?php

declare(strict_types=1);

namespace api;

use \db\User;

class Response
{
    public $errorCode;
    public $data;

    function __construct(int $errorCode, ?array $data)
    {
        $this->errorCode = $errorCode;
        $this->data = $data;
    }
}