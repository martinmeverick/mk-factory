<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use RuntimeException;

/**
 * Porušení protokolu mezi rodičem a workerem souběžného testu.
 *
 * Je to chyba INFRASTRUKTURY testu, ne doménový výsledek — nesmí se proto
 * nikdy interpretovat jako „worker skončil s chybou“. Rodič ji propouští
 * ven, takže test spadne místo aby falešně prošel.
 */
final class ProtocolViolation extends RuntimeException
{
    public static function forUnknownMessage(string $line, string $context): self
    {
        return new self(sprintf(
            'Neznámý typ zprávy v protokolu bariéry (%s): %s',
            $context,
            var_export($line, true),
        ));
    }

    public static function forUnexpectedType(string $expected, string $actual, string $context): self
    {
        return new self(sprintf(
            'Očekávána zpráva %s (%s), přišla %s.',
            $expected,
            $context,
            $actual,
        ));
    }

    public static function forInvalidBase64(string $payload): self
    {
        return new self(sprintf(
            'Payload zprávy není platný base64 — výsledek workeru nelze přijmout: %s',
            var_export($payload, true),
        ));
    }

    public static function forClosedChannel(string $context): self
    {
        return new self(sprintf(
            'Kanál workeru se uzavřel bez zprávy (%s) — timeout nebo pád procesu.',
            $context,
        ));
    }

    public static function forRepeatedBarrier(int $call): self
    {
        return new self(sprintf(
            'Bariéra je jednorázová, ale worker ji zavolal %d×. Druhé volání by rodič '
            .'přečetl jako výsledek a test by mohl falešně projít.',
            $call,
        ));
    }

    public static function forMissingBarrier(): self
    {
        return new self(
            'Worker nezavolal $barrier() — bez bariéry není souběh deterministický.'
        );
    }

    public static function forWorker(int $index, string $message): self
    {
        return new self(sprintf('Worker %d porušil protokol bariéry: %s', $index, $message));
    }
}
