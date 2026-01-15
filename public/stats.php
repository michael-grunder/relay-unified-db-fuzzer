<?php

header("Content-Type: application/json");

echo json_encode(\Relay\Relay::stats(), JSON_PRETTY_PRINT) . "\n";
