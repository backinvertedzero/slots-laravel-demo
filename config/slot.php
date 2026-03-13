<?php

return [
    'cache_key' => env('CACHE_KEY_AVAILABILITY', 'slots_availability'),
    'cache_ttl' => env('CACHE_TTL', 15),
    'lock_timeout' => env('LOCK_TIMEOUT', 5),
];