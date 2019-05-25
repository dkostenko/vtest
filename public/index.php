<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tarantool\Client\Client;

function main() : void
{
    $dbClient = Client::fromOptions([
        'uri' => getenv('DB_ADDR_URI'),
        'username' => getenv('DB_USER_NAME'),
        'password' => getenv('DB_USER_PASSWORD'),
    ]);
    $dbm = new db\Manager($dbClient);
    $apim = new api\Manager($dbm);
    $apim->handle($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
}

main();