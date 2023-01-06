<?php

return [
    'host' => env('ELASTIC_HOST'),
    'index_logs' => env('ELASTIC_LOG_INDEX'),
    'house_keeping_days' => env('ELASTIC_LOG_HOUSEKEEPING_DAYS', 30),
];