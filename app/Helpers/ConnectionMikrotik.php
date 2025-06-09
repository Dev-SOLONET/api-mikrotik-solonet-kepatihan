<?php

use RouterOS\Client;

function ConnectionMikrotik($host, $port)
{
    // Initiate client with config object
    return new Client([
        'host' => $host,
        'user' => config('routeros.user'),
        'pass' => config('routeros.pass'),
        'port' => intval($port),
    ]);
}