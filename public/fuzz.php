<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
use Mgrunder\RelayUnifiedDbFuzzer\Runner\CommandExecutor;
use Mgrunder\RelayUnifiedDbFuzzer\Runner\RelayFactory;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input') ?: '';

try {
    $payload = PayloadEncoder::fromSerialized($body);
    $operations = $payload['operations'] ?? [];
    if (!is_array($operations)) {
        throw new RuntimeException('Missing operations');
    }

    $relay = (new RelayFactory())->create();
    $executor = new CommandExecutor($relay);
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }
        $executor->execute($operation);
    }

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
