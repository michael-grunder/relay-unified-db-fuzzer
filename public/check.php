<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mgrunder\RelayUnifiedDbFuzzer\Runner\RelayFactory;
use Mgrunder\RelayUnifiedDbFuzzer\Support\LoggerFactory;

header('Content-Type: application/json');

$logLevel = strtolower((string)($_GET['log-level'] ?? 'info'));
$redisHost = $_GET['redis-host'] ?? $_GET['host'] ?? 'localhost';
$redisPort = (int)($_GET['redis-port'] ?? $_GET['port'] ?? '6379');
$flush = filter_var($_GET['flush'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$logger = LoggerFactory::create('check-endpoint', $logLevel);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    $logger->warning('Invalid method', ['method' => $_SERVER['REQUEST_METHOD'] ?? null]);
    echo json_encode(['status' => 'error', 'message' => 'GET required']);
    exit;
}

try {
    $relay = (new RelayFactory(null, $redisHost, $redisPort))->create();
    $pong = $relay->ping();
    if ($flush) {
        $relay->flushdb();
    }

    $logger->info('Sanity check completed', [
        'redis_host' => $redisHost,
        'redis_port' => $redisPort,
        'flushed' => $flush,
    ]);

    echo json_encode([
        'status' => 'ok',
        'pong' => $pong,
        'flushed' => $flush,
    ]);
} catch (Throwable $e) {
    $prettyTrace = sprintf(
        "%s: %s in %s:%d\nStack trace:\n%s",
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    $logger->error('Sanity check error', ['exception' => $e]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'exception' => $e::class,
        'trace' => $prettyTrace,
    ]);
}
