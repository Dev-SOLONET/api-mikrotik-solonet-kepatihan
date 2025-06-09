<?php

return [

    'host' => env('ROUTEROS_HOST', ''),
    'user' => env('ROUTEROS_USER', 'request'),
    'pass' => env('ROUTEROS_PASS', 'api227solonet'),
    'port' => intval(env('ROUTEROS_PORT', '')),

];