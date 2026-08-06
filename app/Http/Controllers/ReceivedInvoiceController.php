<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contacts\SupplierNotResolvable;
use App\Domain\Contacts\SupplierResolver;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Money\Money;
use App\Enums\ContactType;
use App\Enums\ReceivedInvoiceStatus;
use App\Http\Requests\ReceivedInvoiceRequest;
use App\Models\Contact;
use App\Models\Project;
use App\Models\ReceivedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReceivedInvoiceController extends Controller
{
    public function __construct(
        private readonly ReceivedInvoiceLifecycle $lifecycle,
        private readonly SupplierResolver $supplierResolver,
    ) {
    }

    public function index(Request $request): View
    {
        $filter = $request->query('stav');

        $invoices = ReceivedInvoice::query()
            ->with('contact')
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter && $filter !== 'overdue', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('received.index', ['invoices' => $invoices, 'filter' => $filter]);
    }

    public function create(): View
    {
        return view('received.create', array_merge($this->formOptions(), ['invoice' => null]));
    }

    public function store(ReceivedInvoiceRequest $request, AttachmentController $attachments): RedirectResponse
    {
        $invoice = DB::transaction(function () use ($request) {
            return ReceivedInvoice::create($this->data($request));
        });

        if ($request->hasFile('attachment')) {
            $attachments->attach($invoice, $request->file('attachment'));
        }

        return redirect()->route('received.show', $invoice)
            ->with('status', 'Přijatá faktura byla zaevidována.');
    }

    public function show(ReceivedInvoice $received): View
    {
        $received->load(['contact', 'project', 'attachments']);

        return view('received.show', ['invoice' => $received]);
    }

    public function edit(ReceivedInvoice $received): View|RedirectResponse
    {
        if (! $this->isEditable($received)) {
            return redirect()->route('received.show', $received)
                ->with('error', 'Uhrazenou nebo zamítnutou fakturu nelze upravovat.');
        }

        return view('received.edit', array_merge($this->formOptions(), ['invoice' => $received]));
    }

    public function update(ReceivedInvoiceRequest $request, ReceivedInvoice $received): RedirectResponse
    {
        // O editovatelnosti rozhoduje až zamčený řádek v lifecycle vrstvě.
        try {
            $this->lifecycle->updateDetails($received, $this->data($request));
        } catch (InvalidStateTransition|InvoiceNotFound $e) {
            return redirect()->route('received.show', $received)->with('error', $e->getMessage());
        }

        return redirect()->route('received.show', $received)->with('status', 'Faktura byla upravena.');
    }

    public function destroy(ReceivedInvoice $received): RedirectResponse
    {
        // Smazatelnost rozhoduje aktuální stav zamčeného řádku, ne dřív
        // načtená instance; přílohy a soubory uklízí lifecycle.
        try {
            $this->lifecycle->delete($received);
        } catch (InvalidStateTransition|InvoiceNotFound $e) {
            return redirect()->route('received.show', $received)->with('error', $e->getMessage());
        }

        return redirect()->route('received.index')->with('status', 'Přijatá faktura byla smazána.');
    }

    public function approve(ReceivedInvoice $received): RedirectResponse
    {
        return $this->transition(fn () => $this->lifecycle->approve($received), $received, 'Faktura byla schválena.');
    }

    public function reject(ReceivedInvoice $received): RedirectResponse
    {
        return $this->transition(fn () => $this->lifecycle->reject($received), $received, 'Faktura byla zamítnuta.');
    }

    public function markPaid(ReceivedInvoice $received): RedirectResponse
    {
        return $this->transition(
            fn () => $this->lifecycle->markPaid($received, CarbonImmutable::now()),
            $received,
            'Faktura byla označena jako uhrazená.',
        );
    }

    private function transition(callable $action, ReceivedInvoice $received, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidStateTransition $e) {
            return redirect()->route('received.show', $received)->with('error', $e->getMessage());
        }

        return redirect()->route('received.show', $received)->with('status', $message);
    }

    private function isEditable(ReceivedInvoice $received): bool
    {
        return in_array($received->status, [ReceivedInvoiceStatus::Received, ReceivedInvoiceStatus::Approved], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ReceivedInvoiceRequest $request): array
    {
        return [
            'contact_id' => $this->resolveSupplierId($request),
            'project_id' => $request->validated('project_id'),
            'supplier_invoice_number' => $request->validated('supplier_invoice_number'),
            'variable_symbol' => $request->validated('variable_symbol'),
            'issue_date' => $request->validated('issue_date'),
            'received_date' => $request->validated('received_date'),
            'due_date' => $request->validated('due_date'),
            'total_minor' => Money::fromDecimalString($request->validated('total'), 'CZK')->getMinor(),
            'vat_minor' => $request->validated('vat') !== null
                ? Money::fromDecimalString($request->validated('vat'), 'CZK')->getMinor()
                : null,
            'currency' => 'CZK',
            'note' => $request->validated('note'),
        ];
    }

    /**
     * Dodavatel buď vybraný ze seznamu, nebo dohledaný/založený podle IČO.
     * Doménovou chybu překládá na chybu formuláře u pole IČO.
     */
    private function resolveSupplierId(ReceivedInvoiceRequest $request): int
    {
        if ($request->validated('supplier_mode') === 'existing') {
            return (int) $request->validated('contact_id');
        }

        try {
            return $this->supplierResolver->resolveByIco(
                (string) $request->validated('supplier_ico'),
                $request->validated('supplier_name'),
            )->id;
        } catch (SupplierNotResolvable $e) {
            throw ValidationException::withMessages(['supplier_ico' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'suppliers' => Contact::query()
                ->whereIn('type', [ContactType::Supplier, ContactType::Both])
                ->orderBy('name')->get(),
            'projects' => Project::query()->orderBy('name')->get(),
        ];
    }
}
