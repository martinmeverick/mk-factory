<?php

declare(strict_types=1);

namespace Tests\Concurrency;

/**
 * Doplňkový rendezvous mezi forkovanými workery UVNITŘ kritické sekce.
 *
 * `WorkerBarrier` je jednorázová a záměrně taková zůstává: pouští všechny
 * workery do kritické sekce najednou. Některé scénáře ale potřebují ještě
 * jeden signál v okamžiku, který nastane až uvnitř operace (například
 * „jsem přesně mezi kontrolou stavu a DELETE"). Kanálem NESMÍ být databáze
 * — worker v transakci by svůj signál commitem zveřejnil až příliš pozdě,
 * takže by se scénář neodehrál. Proto soubory: jsou vidět okamžitě a mimo
 * transakční izolaci.
 *
 * Adresář si test uklidí sám (`cleanup()`), nic po sadě nezůstává.
 */
final class ProcessHandshake
{
    private function __construct(
        private readonly string $directory,
    ) {}

    public static function create(): self
    {
        $directory = sys_get_temp_dir().'/mkf-handshake-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Nepodařilo se vytvořit adresář pro handshake: '.$directory);
        }

        return new self($directory);
    }

    public function signal(string $name): void
    {
        file_put_contents($this->path($name), '1');
    }

    public function isSignalled(string $name): bool
    {
        clearstatcache(true, $this->path($name));

        return is_file($this->path($name));
    }

    /**
     * Počká na signál nejdéle zadanou dobu. Vrací, zda signál dorazil —
     * čekání se NIKDY nemění na nekonečné: protistrana může legitimně
     * viset na databázovém zámku, který drží právě tenhle worker.
     */
    public function await(string $name, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($this->isSignalled($name)) {
                return true;
            }

            usleep(5_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function cleanup(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        foreach ((glob($this->directory.'/*') ?: []) as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.preg_replace('/[^a-z0-9_-]/i', '', $name);
    }
}
