<?php

namespace Database\Seeders;

use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Enums\MemberRole;
use App\Enums\ProjectStatus;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationSettings;
use App\Models\Project;
use App\Models\ReceivedInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data dle DATA_MODEL.md — pouze fiktivní údaje.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Demo Uživatel',
            'email' => 'demo@mkfactory.test',
            'password' => Hash::make('password'),
        ]);

        $organization = Organization::create([
            'name' => 'U Jabka Demo',
            'ico' => '12345678',
            'dic' => 'CZ12345678',
            'street' => 'Jablečná 1',
            'city' => 'Praha',
            'zip' => '110 00',
            'country' => 'CZ',
            'email' => 'info@ujabka-demo.test',
        ]);

        $current = app(CurrentOrganization::class);
        $current->set($organization);

        try {
            $this->seedOrganizationData($organization, $user);
        } finally {
            $current->set(null);
        }
    }

    private function seedOrganizationData(Organization $organization, User $user): void
    {
        OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MemberRole::Owner,
        ]);

        $bankAccount = BankAccount::create([
            'organization_id' => $organization->id,
            'name' => 'Hlavní účet',
            'account_number' => '123456789',
            'bank_code' => '0100',
            'iban' => 'CZ1801000000000123456789',
            'is_default' => true,
        ]);

        $series = InvoiceNumberSeries::create([
            'organization_id' => $organization->id,
            'name' => 'Faktury',
            'prefix' => 'FV',
            'year' => 2026,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        OrganizationSettings::create([
            'organization_id' => $organization->id,
            'vat_payer' => true,
            'default_due_days' => 14,
            'default_bank_account_id' => $bankAccount->id,
            'default_number_series_id' => $series->id,
            'invoice_footer_text' => 'Fyzická osoba zapsaná v živnostenském rejstříku.',
        ]);

        $customers = Contact::factory()
            ->count(2)
            ->customer()
            ->create(['organization_id' => $organization->id]);

        $suppliers = Contact::factory()
            ->count(2)
            ->supplier()
            ->create(['organization_id' => $organization->id]);

        $webProject = Project::create([
            'organization_id' => $organization->id,
            'name' => 'Web U Jabka',
            'code' => 'WEB',
            'contact_id' => $customers[0]->id,
            'status' => ProjectStatus::Active,
            'external_id' => 'ujabka-web',
        ]);

        Project::create([
            'organization_id' => $organization->id,
            'name' => 'Interní automatizace',
            'code' => 'AUTO',
            'status' => ProjectStatus::Active,
        ]);

        $this->seedIssuedInvoices($organization, $series, $bankAccount, $customers->all(), $webProject);
        $this->seedReceivedInvoices($organization, $suppliers->all(), $webProject);
    }

    /**
     * @param list<Contact> $customers
     */
    private function seedIssuedInvoices(
        Organization $organization,
        InvoiceNumberSeries $series,
        BankAccount $bankAccount,
        array $customers,
        Project $project,
    ): void {
        /** @var IssuedInvoiceLifecycle $lifecycle */
        $lifecycle = app(IssuedInvoiceLifecycle::class);

        // 1) Koncept.
        $this->createDraft($organization, $series, $bankAccount, $customers[0], $project, [
            ['Konzultace a analýza požadavků', '4.000', 'h', 150000, '21.00'],
            ['Vývoj webové aplikace', '10.000', 'h', 180000, '21.00'],
        ], note: 'Koncept — zatím nevystavovat.');

        // 2) Vystavená, ve splatnosti.
        $issued = $this->createDraft($organization, $series, $bankAccount, $customers[0], $project, [
            ['Vývoj webové aplikace — etapa 1', '20.000', 'h', 180000, '21.00'],
            ['Webhosting (roční paušál)', '1.000', 'ks', 240000, '21.00'],
        ]);
        $lifecycle->issue($issued, CarbonImmutable::today()->subDays(3));

        // 3) Vystavená po splatnosti (splatnost odvozená z data vystavení
        //    + 14 dní je v minulosti).
        $overdue = $this->createDraft($organization, $series, $bankAccount, $customers[1], null, [
            ['Servisní zásah', '3.500', 'h', 160000, '21.00'],
        ]);
        $lifecycle->issue($overdue, CarbonImmutable::today()->subDays(45));

        // 4) Uhrazená.
        $paid = $this->createDraft($organization, $series, $bankAccount, $customers[1], null, [
            ['Grafické práce', '8.000', 'h', 140000, '21.00'],
            ['Licence šablony', '1.000', 'ks', 90000, '12.00'],
        ]);
        $lifecycle->issue($paid, CarbonImmutable::today()->subDays(30));
        $lifecycle->markPaid($paid, CarbonImmutable::today()->subDays(20));
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int, 4: ?string}> $items
     */
    private function createDraft(
        Organization $organization,
        InvoiceNumberSeries $series,
        BankAccount $bankAccount,
        Contact $customer,
        ?Project $project,
        array $items,
        ?string $note = null,
    ): IssuedInvoice {
        $invoice = IssuedInvoice::create([
            'organization_id' => $organization->id,
            'number_series_id' => $series->id,
            'contact_id' => $customer->id,
            'project_id' => $project?->id,
            'bank_account_id' => $bankAccount->id,
            'issue_date' => today(),
            'currency' => 'CZK',
            'internal_note' => $note,
        ]);

        foreach ($items as $index => [$description, $quantity, $unit, $unitPriceMinor, $vatRate]) {
            IssuedInvoiceItem::create([
                'issued_invoice_id' => $invoice->id,
                'organization_id' => $organization->id,
                'position' => $index + 1,
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price_minor' => $unitPriceMinor,
                'vat_rate' => $vatRate,
            ]);
        }

        return $invoice;
    }

    /**
     * @param list<Contact> $suppliers
     */
    private function seedReceivedInvoices(Organization $organization, array $suppliers, Project $project): void
    {
        /** @var ReceivedInvoiceLifecycle $lifecycle */
        $lifecycle = app(ReceivedInvoiceLifecycle::class);

        // 1) Přijatá, čeká na schválení.
        ReceivedInvoice::create([
            'organization_id' => $organization->id,
            'contact_id' => $suppliers[0]->id,
            'project_id' => $project->id,
            'supplier_invoice_number' => '2026070123',
            'variable_symbol' => '2026070123',
            'issue_date' => today()->subDays(5),
            'received_date' => today()->subDays(2),
            'due_date' => today()->addDays(12),
            'total_minor' => 96800,
            'vat_minor' => 16800,
        ]);

        // 2) Schválená po splatnosti (bankovní účet dodavatele v poznámce).
        $approvedOverdue = ReceivedInvoice::create([
            'organization_id' => $organization->id,
            'contact_id' => $suppliers[1]->id,
            'supplier_invoice_number' => '2026050087',
            'variable_symbol' => '2026050087',
            'issue_date' => today()->subDays(40),
            'received_date' => today()->subDays(38),
            'due_date' => today()->subDays(26),
            'total_minor' => 242000,
            'vat_minor' => 42000,
            'note' => 'Bankovní účet dodavatele: IBAN CZ1403000000000987654321.',
        ]);
        $lifecycle->approve($approvedOverdue);

        // 3) Uhrazená.
        $paid = ReceivedInvoice::create([
            'organization_id' => $organization->id,
            'contact_id' => $suppliers[0]->id,
            'supplier_invoice_number' => '2026060142',
            'variable_symbol' => '2026060142',
            'issue_date' => today()->subDays(25),
            'received_date' => today()->subDays(24),
            'due_date' => today()->subDays(11),
            'total_minor' => 30250,
            'vat_minor' => 5250,
        ]);
        $lifecycle->approve($paid);
        $lifecycle->markPaid($paid, CarbonImmutable::today()->subDays(12));
    }
}
