<?php

declare(strict_types=1);

namespace Tests\Concurrency;

/**
 * Zprávy mezi rodičem a workery souběžného testu.
 *
 * Zprávy jsou TYPOVANÉ, ne poziční: dřívější protokol posílal holé „READY“
 * a holý base64 výsledek, takže druhé (chybné) READY rodič přečetl jako
 * výsledek, `base64_decode('READY', true)` vrátil false, přetypování na
 * string udělalo prázdný řetězec — a test falešně prošel jako úspěch.
 *
 * Typy:
 *   READY              worker dosáhl bariéry a čeká
 *   GO                 rodič uvolňuje workery do kritické sekce
 *   RESULT:<base64>    worker doběhl; payload = chybová hláška ('' = úspěch)
 *   ERROR:<base64>     porušení protokolu na straně workeru (chyba testu)
 */
final class BarrierProtocol
{
    public const string READY = 'READY';

    public const string GO = 'GO';

    public const string RESULT = 'RESULT';

    public const string ERROR = 'ERROR';

    public static function ready(): string
    {
        return self::READY."\n";
    }

    public static function go(): string
    {
        return self::GO."\n";
    }

    public static function result(string $error): string
    {
        return self::RESULT.':'.base64_encode($error)."\n";
    }

    public static function error(string $message): string
    {
        return self::ERROR.':'.base64_encode($message)."\n";
    }

    /**
     * @return array{type: string, payload: string}
     *
     * @throws ProtocolViolation
     */
    public static function decode(string|false|null $line, string $context): array
    {
        if ($line === false || $line === null) {
            throw ProtocolViolation::forClosedChannel($context);
        }

        $trimmed = trim($line);

        if ($trimmed === '') {
            throw ProtocolViolation::forClosedChannel($context);
        }

        if ($trimmed === self::READY || $trimmed === self::GO) {
            return ['type' => $trimmed, 'payload' => ''];
        }

        foreach ([self::RESULT, self::ERROR] as $type) {
            $prefix = $type.':';

            if (! str_starts_with($trimmed, $prefix)) {
                continue;
            }

            $encoded = substr($trimmed, strlen($prefix));

            if ($encoded === '') {
                return ['type' => $type, 'payload' => ''];
            }

            // Striktní režim: false znamená poškozený payload a NESMÍ se
            // tiše přetypovat na prázdný řetězec (dřívější falešný úspěch).
            $decoded = base64_decode($encoded, true);

            if ($decoded === false) {
                throw ProtocolViolation::forInvalidBase64($encoded);
            }

            return ['type' => $type, 'payload' => $decoded];
        }

        throw ProtocolViolation::forUnknownMessage($trimmed, $context);
    }

    /**
     * Přečte zprávu a vynutí očekávaný typ.
     *
     * @return array{type: string, payload: string}
     *
     * @throws ProtocolViolation
     */
    public static function expect(string|false|null $line, string $expectedType, string $context): array
    {
        $message = self::decode($line, $context);

        if ($message['type'] !== $expectedType) {
            throw ProtocolViolation::forUnexpectedType($expectedType, $message['type'], $context);
        }

        return $message;
    }
}
