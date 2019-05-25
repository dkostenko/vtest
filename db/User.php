<?php

declare(strict_types=1);

namespace db;

class User
{
    public $id;
    public $name;

    function __construct($row)
    {
        $this->id = $row[0];
        $this->name = $row[1];
    }
}
