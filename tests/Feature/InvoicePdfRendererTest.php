<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Money\Money;
use App\Domain\Payments\QrPaymentImage;
use App\Domain\Payments\SpdPayload;
use App\Domain\Pdf\InvoicePdfData;
use App\Domain\Pdf\InvoicePdfLine;
use App\Domain\Pdf\InvoicePdfRenderer;
use App\Enums\VatRegime;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Tests\TestCase;

final class InvoicePdfRendererTest extends TestCase
{
    public function test_vat_payer_invoice_with_logo_and_qr_renders_valid_pdf(): void
    {
        $data = $this->makeData(
            vatPayer: true,
            invoiceNumber: 'FV20260007',
            logoDataUri: $this->onePixelPngDataUri(),
            qrDataUri: QrPaymentImage::pngDataUri(SpdPayload::create(
                iban: 'CZ1801000000000123456789',
                amount: Money::fromMinor(423500),
                variableSymbol: '20260007',
                message: 'Faktura FV20260007',
                dueDate: CarbonImmutable::parse('2026-08-15'),
            )),
        );

        $pdf = new InvoicePdfRenderer()->render($data);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }

    public function test_non_vat_payer_invoice_without_qr_renders_valid_pdf(): void
    {
        $data = $this->makeData(vatPayer: false, invoiceNumber: 'FV20260008');

        $pdf = new InvoicePdfRenderer()->render($data);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }

    public function test_draft_without_number_renders_koncept_pdf(): void
    {
        $data = $this->makeData(vatPayer: true, invoiceNumber: null);

        $pdf = new InvoicePdfRenderer()->render($data);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }

    public function test_used_goods_margin_invoice_renders_valid_pdf(): void
    {
        $data = $this->makeData(
            vatPayer: true,
            invoiceNumber: 'FV20260010',
            vatRegime: VatRegime::UsedGoodsMargin,
        );

        $pdf = new InvoicePdfRenderer()->render($data);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1024, strlen($pdf));
    }

    public function test_rendered_html_for_non_vat_payer_has_no_vat_recap(): void
    {
        $data = $this->makeData(vatPayer: false, invoiceNumber: 'FV20260009');

        $html = view('pdf.invoice', ['data' => $data])->render();

        $this->assertStringNotContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('DUZP', $html);
        $this->assertStringContainsString('Dodavatel není plátcem DPH.', $html);
        $this->assertStringContainsString('Celkem k úhradě', $html);
        $this->assertStringNotContainsString('zvláštní režim', $html);
    }

    public function test_rendered_html_for_vat_payer_has_vat_recap_and_czech_items(): void
    {
        $data = $this->makeData(vatPayer: true, invoiceNumber: 'FV20260007');

        $html = view('pdf.invoice', ['data' => $data])->render();

        $this->assertStringContainsString('FAKTURA č. FV20260007', $html);
        $this->assertStringContainsString('Rekapitulace DPH', $html);
        $this->assertStringContainsString('Instalace čpavkového chlazení, měření a regulace', $html);
        $this->assertStringContainsString('2,5', $html);   // 2.500 bez koncových nul
        $this->assertStringNotContainsString('2.500', $html);
        $this->assertStringNotContainsString('zvláštní režim', $html);
    }

    public function test_rendered_html_for_used_goods_margin_has_notice_and_no_vat_figures(): void
    {
        $data = $this->makeData(
            vatPayer: true,
            invoiceNumber: 'FV20260010',
            vatRegime: VatRegime::UsedGoodsMargin,
        );

        $html = view('pdf.invoice', ['data' => $data])->render();

        $this->assertStringContainsString('FAKTURA č. FV20260010', $html);
        $this->assertStringContainsString('Daňový doklad', $html);
        $this->assertStringContainsString('zvláštní režim - použité zboží', $html);
        $this->assertStringContainsString('DPH se nevyčísluje.', $html);
        $this->assertStringContainsString('DUZP', $html);
        $this->assertStringContainsString('DIČ: CZ12345678', $html);
        $this->assertStringContainsString('Celkem k úhradě', $html);
        $this->assertStringContainsString("3\u{A0}500,00\u{A0}Kč", $html);

        $this->assertStringNotContainsString('Rekapitulace DPH', $html);
        $this->assertStringNotContainsString('Dodavatel není plátcem DPH', $html);
        $this->assertStringNotContainsString('DPH&nbsp;%', $html);
        $this->assertStringNotContainsString('Základ', $html);
    }

    public function test_rendered_html_for_draft_shows_koncept_marking(): void
    {
        $data = $this->makeData(vatPayer: true, invoiceNumber: null);

        $html = view('pdf.invoice', ['data' => $data])->render();

        $this->assertStringContainsString('KONCEPT', $html);
        $this->assertStringContainsString('NEDAŇOVÝ DOKLAD', mb_strtoupper($html));
        $this->assertStringNotContainsString('FAKTURA č.', $html);
    }

    private function makeData(
        bool $vatPayer,
        ?string $invoiceNumber,
        ?string $logoDataUri = null,
        ?string $qrDataUri = null,
        VatRegime $vatRegime = VatRegime::Standard,
    ): InvoicePdfData {
        // Zvláštní režim: řádky bez běžné DPH, ceny jsou konečné.
        $withVat = $vatPayer && $vatRegime !== VatRegime::UsedGoodsMargin;
        $vatRate = $withVat ? '21.00' : null;

        $lineOne = new InvoicePdfLine(
            description: 'Instalace čpavkového chlazení, měření a regulace',
            quantity: '2.500',
            unit: 'hod',
            unitPrice: Money::fromMinor(120000),
            vatRate: $vatRate,
            lineSubtotal: Money::fromMinor(300000),
            lineVat: Money::fromMinor($withVat ? 63000 : 0),
            lineTotal: Money::fromMinor($withVat ? 363000 : 300000),
        );

        $lineTwo = new InvoicePdfLine(
            description: 'Doprava a příslušenství — žárovky, čidla',
            quantity: '1.000',
            unit: 'ks',
            unitPrice: Money::fromMinor(50000),
            vatRate: $vatRate,
            lineSubtotal: Money::fromMinor(50000),
            lineVat: Money::fromMinor($withVat ? 10500 : 0),
            lineTotal: Money::fromMinor($withVat ? 60500 : 50000),
        );

        $subtotal = Money::fromMinor(350000);
        $total = Money::fromMinor($withVat ? 423500 : 350000);

        return new InvoicePdfData(
            supplier: [
                'name' => 'U Jabka Demo s.r.o.',
                'ico' => '12345678',
                'dic' => $vatPayer ? 'CZ12345678' : null,
                'street' => 'Jablečná 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
                'email' => 'info@ujabka.test',
                'phone' => '+420 777 123 456',
                'website' => 'www.ujabka.test',
            ],
            customer: [
                'name' => 'Řeznictví U Švába a syn, a.s.',
                'ico' => '87654321',
                'dic' => 'CZ87654321',
                'street' => 'Údolní 99',
                'city' => 'Brno',
                'zip' => '602 00',
                'country' => 'CZ',
            ],
            invoiceNumber: $invoiceNumber,
            variableSymbol: $invoiceNumber === null ? null : '20260007',
            issueDate: new DateTimeImmutable('2026-08-01'),
            dueDate: new DateTimeImmutable('2026-08-15'),
            taxDate: $vatPayer ? new DateTimeImmutable('2026-08-01') : null,
            bankAccount: [
                'account_number' => '123456789',
                'bank_code' => '0100',
                'iban' => 'CZ1801000000000123456789',
                'bic' => 'KOMBCZPP',
            ],
            items: [$lineOne, $lineTwo],
            subtotal: $subtotal,
            vatBreakdown: $withVat
                ? [['rate' => '21.00', 'base' => $subtotal, 'vat' => Money::fromMinor(73500)]]
                : [],
            total: $total,
            vatPayer: $vatPayer,
            note: "Fakturujeme vám za provedené práce.\nDěkujeme za spolupráci.",
            footerText: 'U Jabka Demo s.r.o., zapsána v OR u Městského soudu v Praze, oddíl C.',
            logoDataUri: $logoDataUri,
            qrDataUri: $qrDataUri,
            vatRegime: $vatRegime,
        );
    }

    private function onePixelPngDataUri(): string
    {
        $image = imagecreatetruecolor(1, 1);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
