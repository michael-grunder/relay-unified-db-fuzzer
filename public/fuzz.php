<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
use Mgrunder\RelayUnifiedDbFuzzer\Runner\CommandExecutor;
use Mgrunder\RelayUnifiedDbFuzzer\Runner\RelayFactory;
use Mgrunder\RelayUnifiedDbFuzzer\Support\LoggerFactory;

header('Content-Type: application/json');

$logLevel = strtolower(getenv('RELAY_FUZZ_LOG_LEVEL') ?: 'info');
$logger = LoggerFactory::create('fuzz-endpoint', $logLevel);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $logger->warning('Invalid method', ['method' => $_SERVER['REQUEST_METHOD'] ?? null]);
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

    $logger->info('Received fuzz payload', [
        'worker' => $payload['worker'] ?? null,
        'operations' => count($operations),
        'bytes' => strlen($body),
    ]);

    $relay = (new RelayFactory())->create();
    $executor = new CommandExecutor($relay, $logger);
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            $logger->warning('Skipping invalid operation payload', [
                'operation' => $operation,
            ]);
            continue;
        }
        $executor->execute($operation);
    }

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $logger->error('Fuzz endpoint error', ['exception' => $e]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
