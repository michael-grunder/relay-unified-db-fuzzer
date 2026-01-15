<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class CrashSignals
{
    /**
     * @return list<int>
     */
    public static function list(): array
    {
        $signals = [];
        foreach (['SIGABRT', 'SIGBUS', 'SIGFPE', 'SIGILL', 'SIGSEGV', 'SIGTRAP'] as $name) {
            if (defined($name)) {
                $signals[] = constant($name);
            }
        }

        return $signals;
    }

    public static function isCrashSignal(?int $signal): bool
    {
        if ($signal === null) {
            return false;
        }

        return in_array($signal, self::list(), true);
    }
}
