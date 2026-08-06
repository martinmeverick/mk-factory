<?php

declare(strict_types=1);

namespace Tests\Concurrency;

/**
 * Jednorázová bariéra předaná workeru souběžného testu.
 *
 * Worker ji MUSÍ zavolat právě jednou: co je před ní, je příprava (vlastní
 * DB spojení, načtení modelů), co je za ní, je kritická operace. Volání
 * navíc je porušení protokolu a okamžitě vyhodí výjimku — DŘÍV, než se
 * cokoli pošle rodiči, takže do kanálu nikdy neproteče druhé READY.
 *
 * Je `callable` přes __invoke(), takže worker píše prostě `$barrier();`.
 */
final class WorkerBarrier
{
    private int $calls = 0;

    /**
     * @param  resource  $socket
     */
    public function __construct(
        private $socket,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @throws ProtocolViolation
     */
    public function __invoke(): void
    {
        $this->calls++;

        if ($this->calls > 1) {
            throw ProtocolViolation::forRepeatedBarrier($this->calls);
        }

        self::writeQuietly($this->socket, BarrierProtocol::ready());
        stream_set_timeout($this->socket, $this->timeoutSeconds);

        // Rodič mohl kanál mezitím zavřít (jiný worker porušil protokol) —
        // fgets pak vrátí false a dekódování to ohlásí jako protokolovou
        // chybu, ne jako tichý průchod bariérou.
        BarrierProtocol::expect(@fgets($this->socket), BarrierProtocol::GO, 'worker čeká na uvolnění');
    }

    /**
     * Zápis, který nikdy nevyhodí. Rodič může kanál zavřít dřív, než
     * potomek doreportuje (rozbitá roura) — z warningu by se přes
     * Laravelův error handler stala ErrorException a potomek by začal
     * reportovat testy, což nesmí.
     *
     * @param  resource  $socket
     */
    public static function writeQuietly($socket, string $line): void
    {
        if (! is_resource($socket)) {
            return;
        }

        set_error_handler(static fn (): bool => true);

        try {
            @fwrite($socket, $line);
        } finally {
            restore_error_handler();
        }
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * Zápis syrové zprávy do kanálu. Existuje VÝHRADNĚ pro testy samotného
     * protokolu (poškozený base64, neznámý typ zprávy) — produkční workery
     * ji nepoužívají a použít nesmí.
     */
    public function writeRawForProtocolTest(string $line): void
    {
        self::writeQuietly($this->socket, $line);
    }
}
