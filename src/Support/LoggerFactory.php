<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Support;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(string $channel, string $level, ?string $stream = null): LoggerInterface
    {
        $logger = new Logger($channel);
        try {
            $monologLevel = Logger::toMonologLevel($level);
        } catch (\InvalidArgumentException $e) {
            $monologLevel = Logger::INFO;
            error_log(sprintf('Invalid log level "%s"; defaulting to info.', $level));
        }
        $handler = new StreamHandler($stream ?? 'php://stderr', $monologLevel);
        $formatter = new LineFormatter('[%extra.microtime% %extra.pid%] %message% %context%' . "\n", null, true, true);
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $extra = $record->extra;
            $extra['microtime'] = sprintf('%.6f', microtime(true));
            $extra['pid'] = getmypid();

            return $record->with(extra: $extra);
        });
        $logger->pushHandler(new WhatFailureGroupHandler([$handler]));

        return $logger;
    }
}
