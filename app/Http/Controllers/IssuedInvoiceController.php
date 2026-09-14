<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Invoicing\InvalidInvoiceReference;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\InvoiceNotIssuable;
use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyOverflow;
use App\Domain\Money\UsedGoodsMargin;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Enums\VatRegime;
use App\Http\Requests\IssuedInvoiceRequest;
use App\Http\Requests\PaymentRequest;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IssuedInvoiceController extends Controller
{
    public function __construct(
        private readonly IssuedInvoiceLifecycle $lifecycle,
        private readonly InvoiceTotalsCalculator $calculator,
    ) {}

    public function index(Request $request): View
    {
        $filter = $request->query('stav');

        $invoices = IssuedInvoice::query()
            ->with('contact')
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter && $filter !== 'overdue', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('invoices.index', ['invoices' => $invoices, 'filter' => $filter]);
    }

    public function create(): View
    {
        $organization = app(CurrentOrganization::class)->getOrFail();
        $settings = $organization->settings;

        return view('invoices.create', array_merge($this->formOptions(), [
            'invoice' => null,
            'defaults' => [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($settings?->default_due_days ?? 14)->toDateString(),
                'number_series_id' => $settings?->default_number_series_id,
                'bank_account_id' => $settings?->default_bank_account_id,
                'note' => $settings?->invoice_default_note,
            ],
            'vatPayer' => (bool) $settings?->vat_payer,
        ]));
    }

    public function store(IssuedInvoiceRequest $request): RedirectResponse
    {
        $header = $this->headerData($request);
        $items = $this->itemRows($request->validated('items'), $request->vatRegime());

        try {
            // Nový kontakt fyzické osoby vzniká ve STEJNÉ transakci jako
            // koncept: selhání kdekoli (včetně MoneyOverflow při přepočtu)
            // odvalí obojí — žádný osiřelý kontakt.
            $invoice = DB::transaction(function () use ($request, $header, $items) {
                if ($request->isManualRecipient()) {
                    $header['contact_id'] = $this->createPersonContact($request)->id;
                }

                $invoice = IssuedInvoice::create($header);

                foreach ($items as $position => $item) {
                    $invoice->items()->create($item + [
                        'organization_id' => $invoice->organization_id,
                        'position' => $position + 1,
                    ]);
                }

                $this->calculator->recalculate($invoice);

                return $invoice;
            });
        } catch (MoneyOverflow $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Koncept faktury byl vytvořen.');
    }

    public function show(IssuedInvoice $invoice): View
    {
        $invoice->load(['items', 'contact', 'project', 'bankAccount', 'numberSeries', 'payments']);

        return view('invoices.show', ['invoice' => $invoice]);
    }

    public function edit(IssuedInvoice $invoice): View|RedirectResponse
    {
        if (! $invoice->isEditable()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Vystavenou fakturu nelze upravovat.');
        }

        $invoice->load('items');
        $settings = app(CurrentOrganization::class)->getOrFail()->settings;

        return view('invoices.edit', array_merge($this->formOptions(), [
            'invoice' => $invoice,
            'defaults' => [],
            'vatPayer' => (bool) $settings?->vat_payer,
        ]));
    }

    public function update(IssuedInvoiceRequest $request, IssuedInvoice $invoice): RedirectResponse
    {
        $header = $this->headerData($request);
        $items = $this->itemRows($request->validated('items'), $request->vatRegime());

        // O editovatelnosti rozhoduje až zamčený řádek v lifecycle vrstvě.
        // Vnější transakce obaluje založení kontaktu osoby i zamčenou úpravu
        // konceptu; výjimky se chytají AŽ VNĚ, takže zastaralý stav, cizí
        // reference i MoneyOverflow odvalí kontakt i fakturu společně.
        try {
            DB::transaction(function () use ($request, $invoice, $header, $items): void {
                if ($request->isManualRecipient()) {
                    $header['contact_id'] = $this->createPersonContact($request)->id;
                }

                $this->lifecycle->updateDraft($invoice, $header, $items);
            });
        } catch (InvalidStateTransition|InvoiceNotFound|InvalidInvoiceReference|MoneyOverflow $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Koncept faktury byl upraven.');
    }

    public function destroy(IssuedInvoice $invoice): RedirectResponse
    {
        try {
            $this->lifecycle->deleteDraft($invoice);
        } catch (InvalidStateTransition|InvoiceNotFound $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.index')->with('status', 'Koncept faktury byl smazán.');
    }

    public function issue(IssuedInvoice $invoice): RedirectResponse
    {
        try {
            $this->lifecycle->issue($invoice, CarbonImmutable::parse($invoice->issue_date));
        } catch (InvoiceNotIssuable|InvalidStateTransition|InvoiceNotFound|MoneyOverflow $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            // Číslo z řady už existuje (typicky po ručním snížení „dalšího čísla“).
            return redirect()->route('invoices.show', $invoice)->with(
                'error',
                'Fakturu nelze vystavit: číslo z této řady už existuje. '
                .'Upravte „další číslo“ číselné řady v nastavení a zkuste to znovu.',
            );
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Faktura byla vystavena pod číslem {$invoice->refresh()->invoice_number}.");
    }

    public function registerPayment(PaymentRequest $request, IssuedInvoice $invoice): RedirectResponse
    {
        $amount = Money::fromDecimalString($request->validated('amount'), $invoice->currency);

        try {
            $this->lifecycle->registerPayment(
                $invoice,
                $amount->getMinor(),
                CarbonImmutable::parse($request->validated('paid_on')),
                $request->validated('note'),
            );
        } catch (InvalidStateTransition|InvoiceNotFound|MoneyOverflow|\InvalidArgumentException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Platba byla zaevidována.');
    }

    public function markPaid(Request $request, IssuedInvoice $invoice): RedirectResponse
    {
        try {
            $this->lifecycle->markPaid($invoice, CarbonImmutable::now());
        } catch (InvalidStateTransition|InvoiceNotFound $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Faktura byla označena jako uhrazená.');
    }

    public function cancel(IssuedInvoice $invoice): RedirectResponse
    {
        try {
            $this->lifecycle->cancel($invoice);
        } catch (InvalidStateTransition|InvoiceNotFound $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Faktura byla stornována.');
    }

    /**
     * @return array<string, mixed>
     */
    private function headerData(IssuedInvoiceRequest $request): array
    {
        $settings = app(CurrentOrganization::class)->getOrFail()->settings;
        $vatPayer = (bool) $settings?->vat_payer;
        $regime = $request->vatRegime();

        // Pojistka k FormRequestu: zvláštní režim jen u plátce DPH.
        if ($regime === VatRegime::UsedGoodsMargin && ! $vatPayer) {
            throw ValidationException::withMessages([
                'vat_regime' => 'Zvláštní režim - použité zboží lze zvolit jen u organizace nastavené jako plátce DPH.',
            ]);
        }

        return [
            'vat_regime' => $regime,
            'margin_vat_rate' => $regime === VatRegime::UsedGoodsMargin
                ? UsedGoodsMargin::normalizeRate((string) $request->validated('margin_vat_rate'))
                : null,
            'contact_id' => $request->validated('contact_id'),
            'project_id' => $request->validated('project_id'),
            'bank_account_id' => $request->validated('bank_account_id'),
            'number_series_id' => $request->validated('number_series_id'),
            'issue_date' => $request->validated('issue_date'),
            'due_date' => $request->validated('due_date'),
            'tax_date' => $vatPayer ? $request->validated('tax_date') : null,
            'variable_symbol' => $request->validated('variable_symbol'),
            'note' => $request->validated('note'),
            'internal_note' => $request->validated('internal_note'),
            'currency' => 'CZK',
        ];
    }

    /**
     * Založí kontakt fyzické osoby zadané přímo ve formuláři. Volá se
     * výhradně uvnitř transakce store()/update(). Organizace je vždy
     * aktuální tenant, typ vždy odběratel; IČO/DIČ/external_id null.
     * Jména se mohou opakovat — nic se nededuplikuje ani nepřepisuje.
     */
    private function createPersonContact(IssuedInvoiceRequest $request): Contact
    {
        return Contact::query()->create($request->personAttributes() + [
            'organization_id' => app(CurrentOrganization::class)->getOrFail()->id,
            'type' => ContactType::Customer,
            'ico' => null,
            'dic' => null,
            'external_id' => null,
            'phone' => null,
            'note' => null,
        ]);
    }

    /**
     * Převede vstup formuláře na řádky položek (bez position a organizace —
     * ty doplní vrstva, která je zakládá).
     *
     * Režim se předává explicitně (z validovaného požadavku), aby položky
     * a hlavička vždy vznikly pro tentýž režim.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function itemRows(array $items, VatRegime $regime): array
    {
        $settings = app(CurrentOrganization::class)->getOrFail()->settings;
        $vatPayer = (bool) $settings?->vat_payer;
        $margin = $regime === VatRegime::UsedGoodsMargin;

        return array_values(array_map(fn (array $item): array => [
            'description' => $item['description'],
            'quantity' => str_replace(',', '.', (string) $item['quantity']),
            'unit' => $item['unit'],
            // Zvláštní režim: konečná prodejní cena vč. DPH; běžný režim: cena bez DPH.
            'unit_price_minor' => Money::fromDecimalString($item['unit_price'], 'CZK')->getMinor(),
            // Zvláštní režim nenese běžnou sazbu DPH u položky.
            'vat_rate' => $margin ? null : ($vatPayer ? ($item['vat_rate'] ?? '0') : null),
            // Interní pořizovací cena jen ve zvláštním režimu; při přepnutí na
            // běžný režim se odstraní (žádná tichá reinterpretace).
            'acquisition_unit_price_minor' => $margin
                ? Money::fromDecimalString($item['acquisition_unit_price'], 'CZK')->getMinor()
                : null,
            'line_subtotal_minor' => 0,
            'line_vat_minor' => 0,
            'line_total_minor' => 0,
        ], $items));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'customers' => Contact::query()
                ->whereIn('type', [ContactType::Customer, ContactType::Both])
                ->orderBy('name')->get(),
            'projects' => Project::query()->orderBy('name')->get(),
            'bankAccounts' => BankAccount::query()->orderBy('name')->get(),
            'numberSeries' => InvoiceNumberSeries::query()->orderByDesc('year')->orderBy('name')->get(),
        ];
    }
}
