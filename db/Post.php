<?php

declare(strict_types=1);

namespace db;

class Post
{
    public $id;
    public $creator_id;
    public $created_at;
    public $text;

    function __construct($row)
    {
        $this->id = $row[0];
        $this->creator_id = $row[1];
        $this->created_at = $row[2];
        $this->text = $row[3];
    }
}
