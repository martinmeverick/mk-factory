<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Concurrency\BarrierProtocol;
use Tests\Concurrency\ProtocolViolation;

/**
 * RE-REVIEW, nález 8: dřívější protokol byl POZIČNÍ — rodič četl holé
 * „READY“ a holý base64 výsledek. Druhé (chybné) READY tak přečetl jako
 * výsledek, `(string) base64_decode('READY', true)` dalo prázdný řetězec
 * a test falešně prošel jako úspěch.
 *
 * Zprávy jsou proto typované a dekódování striktní.
 */
class BarrierProtocolDecodingTest extends TestCase
{
    public function test_ready_and_go_round_trip(): void
    {
        $this->assertSame(
            ['type' => BarrierProtocol::READY, 'payload' => ''],
            BarrierProtocol::decode(BarrierProtocol::ready(), 'test'),
        );

        $this->assertSame(
            ['type' => BarrierProtocol::GO, 'payload' => ''],
            BarrierProtocol::decode(BarrierProtocol::go(), 'test'),
        );
    }

    public function test_result_round_trips_multiline_utf8_payload(): void
    {
        $payload = "App\\Domain\\Invoicing\\InvalidStateTransition: Fakturu už\nnelze změnit — je uhrazená.";

        $this->assertSame(
            ['type' => BarrierProtocol::RESULT, 'payload' => $payload],
            BarrierProtocol::decode(BarrierProtocol::result($payload), 'test'),
        );
    }

    public function test_empty_result_means_success(): void
    {
        $this->assertSame(
            ['type' => BarrierProtocol::RESULT, 'payload' => ''],
            BarrierProtocol::decode(BarrierProtocol::result(''), 'test'),
        );
    }

    /**
     * Jádro nálezu: „READY“ na pozici výsledku se dřív tiše proměnilo
     * v prázdný (úspěšný) výsledek. Teď je to rozpoznaný typ zprávy —
     * a `expect()` na něm shodí test.
     */
    public function test_ready_is_never_mistaken_for_a_successful_result(): void
    {
        $message = BarrierProtocol::decode(BarrierProtocol::ready(), 'výsledek');

        $this->assertSame(BarrierProtocol::READY, $message['type'], 'READY nesmí být dekódováno jako výsledek.');

        $this->expectException(ProtocolViolation::class);

        BarrierProtocol::expect(BarrierProtocol::ready(), BarrierProtocol::RESULT, 'výsledek');
    }

    public function test_invalid_base64_payload_is_rejected(): void
    {
        foreach (['RESULT:!!!nope!!!', 'RESULT:====', 'ERROR:%%%'] as $line) {
            try {
                BarrierProtocol::decode($line."\n", 'výsledek');
                $this->fail("Poškozený payload {$line} musí být odmítnut.");
            } catch (ProtocolViolation $e) {
                $this->assertStringContainsString('base64', $e->getMessage());
            }
        }
    }

    public function test_unknown_message_type_is_rejected(): void
    {
        $this->expectException(ProtocolViolation::class);

        BarrierProtocol::decode("GARBAGE\n", 'výsledek');
    }

    public function test_closed_channel_is_rejected(): void
    {
        foreach ([false, null, "\n", ''] as $line) {
            try {
                BarrierProtocol::decode($line, 'výsledek');
                $this->fail('Uzavřený kanál musí být odmítnut: '.var_export($line, true));
            } catch (ProtocolViolation $e) {
                $this->assertStringContainsString('bez zprávy', $e->getMessage());
            }
        }
    }

    public function test_expect_returns_payload_for_the_matching_type(): void
    {
        $message = BarrierProtocol::expect(BarrierProtocol::result('chyba'), BarrierProtocol::RESULT, 'výsledek');

        $this->assertSame('chyba', $message['payload']);
    }
}
