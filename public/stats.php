<?php

header("Content-Type: application/json");

$stats = \Relay\Relay::stats();
$stats['ini'] = [
    'relay.max_endpoiint_dbs' => ini_get('relay.max_endpoint_dbs'),
    'relay.max_db_writers' => ini_get('relay.max_db_writers'),
];

echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
