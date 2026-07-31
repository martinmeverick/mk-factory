<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\InvoiceNumberGenerator;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new InvoiceNumberGenerator();
    }

    public function test_sequence_increments_and_persists(): void
    {
        $series = InvoiceNumberSeries::factory()->create([
            'prefix' => 'FV',
            'year' => 2026,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        $this->assertSame('FV20260001', $this->generator->nextNumber($series));
        $this->assertSame('FV20260002', $this->generator->nextNumber($series));
        $this->assertSame('FV20260003', $this->generator->nextNumber($series));

        $this->assertSame(4, $series->fresh()->next_number);
    }

    public function test_format_tokens_including_yy_and_padded_number(): void
    {
        $series = InvoiceNumberSeries::factory()->create([
            'prefix' => 'FV',
            'year' => 2026,
            'number_format' => '{PREFIX}{YY}-{NUMBER:6}',
        ]);

        $this->assertSame('FV26-000001', $this->generator->nextNumber($series));

        $other = InvoiceNumberSeries::factory()->create([
            'prefix' => '',
            'year' => 2026,
            'next_number' => 42,
            'number_format' => '{YEAR}/{NUMBER:3}',
        ]);

        $this->assertSame('2026/042', $this->generator->nextNumber($other));
    }

    public function test_unique_index_prevents_duplicate_number_within_organization(): void
    {
        $organization = Organization::factory()->create();

        IssuedInvoice::factory()->issued()->create([
            'organization_id' => $organization->id,
            'invoice_number' => 'FV20260001',
        ]);

        $this->expectException(QueryException::class);

        IssuedInvoice::factory()->issued()->create([
            'organization_id' => $organization->id,
            'invoice_number' => 'FV20260001',
        ]);
    }

    public function test_series_are_independent_per_year(): void
    {
        $organization = Organization::factory()->create();

        $series2026 = InvoiceNumberSeries::factory()->create([
            'organization_id' => $organization->id,
            'year' => 2026,
        ]);
        $series2027 = InvoiceNumberSeries::factory()->create([
            'organization_id' => $organization->id,
            'year' => 2027,
        ]);

        $this->assertSame('FV20260001', $this->generator->nextNumber($series2026));
        $this->assertSame('FV20270001', $this->generator->nextNumber($series2027));
        $this->assertSame('FV20260002', $this->generator->nextNumber($series2026));
    }

    public function test_same_number_allowed_in_different_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $seriesA = InvoiceNumberSeries::factory()->create(['organization_id' => $orgA->id]);
        $seriesB = InvoiceNumberSeries::factory()->create(['organization_id' => $orgB->id]);

        // Obě řady začínají od 1 → stejné číslo v různých organizacích.
        $this->assertSame('FV20260001', $this->generator->nextNumber($seriesA));
        $this->assertSame('FV20260001', $this->generator->nextNumber($seriesB));

        IssuedInvoice::factory()->issued()->create([
            'organization_id' => $orgA->id,
            'invoice_number' => 'FV20260001',
        ]);
        IssuedInvoice::factory()->issued()->create([
            'organization_id' => $orgB->id,
            'invoice_number' => 'FV20260001',
        ]);

        $this->assertSame(2, IssuedInvoice::where('invoice_number', 'FV20260001')->count());
    }
}
