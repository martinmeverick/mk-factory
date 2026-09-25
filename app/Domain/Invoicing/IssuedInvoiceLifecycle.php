<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\InvoiceTotalsCalculator;
use App\Domain\Money\Money;
use App\Domain\Money\UsedGoodsMargin;
use App\Domain\Pdf\InvoiceLogoSnapshotStore;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\VatRegime;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Jediné místo, kde se mění stavy vydaných faktur (viz INVOICE_LIFECYCLE.md).
 *
 * Každá mutace jede podle stejného vzoru:
 *   transakce → tenant-scoped dotaz → lockForUpdate() → načtení aktuální
 *   faktury → kontrola stavu → doménová validace → změna → audit → commit.
 *
 * Kontrola stavu se NIKDY nedělá nad instancí předanou volajícím — ta může
 * být zastaralá. Rozhoduje výhradně zamčený řádek v databázi.
 */
final class IssuedInvoiceLifecycle
{
    /**
     * Mapa povolených přechodů — jediné místo pravdy.
     */
    private const array TRANSITIONS = [
        'draft' => ['issued'],
        'issued' => ['partially_paid', 'paid', 'cancelled'],
        'partially_paid' => ['paid'],
        'paid' => [],
        'cancelled' => [],
    ];

    /**
     * Editovatelná pole hlavičky konceptu — odvozeno ze skutečného formuláře
     * (IssuedInvoiceRequest + IssuedInvoiceController::headerData()).
     * Tenant identita, stav, číslo, částky dokladu, snapshoty ani časy
     * přechodů sem NEPATŘÍ; klíč mimo whitelist je programátorská chyba
     * volajícího a končí výjimkou, ne tichým ignorováním.
     */
    private const array DRAFT_HEADER_ATTRIBUTES = [
        'contact_id',
        'project_id',
        'bank_account_id',
        'number_series_id',
        'issue_date',
        'due_date',
        'tax_date',
        'variable_symbol',
        'note',
        'internal_note',
        'currency',
        'discount_type',
        'discount_value',
        // Režim DPH a interní sazba zvláštního režimu - použité zboží jsou
        // volbou uživatele v konceptu; odvozené margin_*_minor součty sem
        // NEPATŘÍ — počítá je výhradně kalkulačka.
        'vat_regime',
        'margin_vat_rate',
    ];

    /**
     * Editovatelná pole položky konceptu. Součty line_* přepočítává
     * kalkulačka, controller je při zakládání nuluje — proto ve whitelistu
     * jsou. position, organization_id a issued_invoice_id doplňuje výhradně
     * tato služba.
     */
    private const array DRAFT_ITEM_ATTRIBUTES = [
        'description',
        'quantity',
        'unit',
        'unit_price_minor',
        'vat_rate',
        // Interní pořizovací cena (zvláštní režim - použité zboží); odvozené
        // line_acquisition/line_margin_* počítá kalkulačka.
        'acquisition_unit_price_minor',
        'line_subtotal_minor',
        'line_vat_minor',
        'line_total_minor',
    ];

    /**
     * Reference hlavičky, které musí patřit TÉŽE organizaci jako faktura.
     * Whitelist klíčů sám o sobě nestačí: povolený `contact_id` může nést
     * cizí id. Ověřuje se proti organization_id ZAMČENÉ faktury, ne proti
     * ambientnímu tenant contextu — ten může být nastavený jinak nebo
     * vůbec (konzole, fronta).
     *
     * @var array<string, class-string<Model>>
     */
    private const array DRAFT_REFERENCES = [
        'contact_id' => Contact::class,
        'project_id' => Project::class,
        'bank_account_id' => BankAccount::class,
        'number_series_id' => InvoiceNumberSeries::class,
    ];

    public function __construct(
        private readonly InvoiceNumberGenerator $numberGenerator,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly InvoiceLogoSnapshotStore $logoSnapshots,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function issue(IssuedInvoice $invoice, ?CarbonImmutable $issueDate = null): void
    {
        $capturedLogoPath = null;

        try {
            $this->mutate($invoice, function (IssuedInvoice $locked) use ($issueDate, &$capturedLogoPath): void {
                $this->assertTransition($locked->status, IssuedInvoiceStatus::Issued);

                $organization = $locked->organization()->withoutGlobalScope('organization')->firstOrFail();

                if ($locked->contact_id === null) {
                    throw InvoiceNotIssuable::because('není vyplněn odběratel.');
                }

                if ($locked->number_series_id === null) {
                    throw InvoiceNotIssuable::because('není vybrána číselná řada.');
                }

                // Bankovní účet je povinný, jen pokud organizace nějaký má.
                if ($locked->bank_account_id === null
                    && $organization->bankAccounts()->withoutGlobalScope('organization')->exists()) {
                    throw InvoiceNotIssuable::because('není vybrán bankovní účet.');
                }

                if (! $locked->items()->withoutGlobalScope('organization')->exists()) {
                    throw InvoiceNotIssuable::because('faktura nemá žádnou položku.');
                }

                $settings = $organization->settings()->withoutGlobalScope('organization')->first();

                // Zvláštní režim - použité zboží: opětovná kontrola těsně před
                // vystavením, nad ZAMČENÝM řádkem a PŘED přepočtem (nastavení
                // organizace se mohlo od uložení konceptu změnit; kalkulačka
                // by bez pořizovací ceny skončila neodchycenou výjimkou).
                if ($locked->vatRegime() === VatRegime::UsedGoodsMargin) {
                    $this->assertUsedGoodsMarginIssuable($locked, $settings);
                }

                // Přepočet PŘED validací součtu i před spotřebováním čísla řady:
                // neplatný doklad nesmí číslo spotřebovat.
                $this->totalsCalculator->recalculate($locked);

                $this->assertPositiveTotal($locked);

                // Logo se zmrazí kopií — pozdější změna či smazání firemního
                // loga nesmí změnit historický doklad. Soubor vzniká PŘED
                // spotřebováním čísla řady a jeho zápis se OVĚŘUJE: bez
                // snapshotu se faktura nevystaví (LogoSnapshotFailed).
                // Kompenzaci po rollbacku DB transakce dělá catch níže.
                $capturedLogoPath = $this->logoSnapshots->capture($organization, $locked);

                $series = $locked->numberSeries()->withoutGlobalScope('organization')->firstOrFail();
                $number = $this->numberGenerator->nextNumber($series);

                $variableSymbol = $locked->variable_symbol;

                if ($variableSymbol === null || $variableSymbol === '') {
                    // Číslice z čísla faktury, max 10 (zprava — zachová pořadovou část).
                    $variableSymbol = substr((string) preg_replace('/\D/', '', $number), -10);
                }

                $resolvedIssueDate = $issueDate ?? CarbonImmutable::today();
                $dueDate = $locked->due_date
                    ?? $resolvedIssueDate->addDays($settings?->default_due_days ?? 14);

                $this->writeLifecycleState($locked, [
                    'status' => IssuedInvoiceStatus::Issued,
                    'invoice_number' => $number,
                    'variable_symbol' => $variableSymbol,
                    'issue_date' => $resolvedIssueDate,
                    'due_date' => $dueDate,
                    'supplier_snapshot' => $this->supplierSnapshot($organization),
                    'customer_snapshot' => $this->customerSnapshot($locked),
                    'bank_account_snapshot' => $this->bankAccountSnapshot($locked),
                    'footer_text' => $settings?->invoice_footer_text,
                    'logo_snapshot_path' => $capturedLogoPath,
                    'issued_at' => now(),
                ]);

                $this->auditLogger->log('invoice.issued', $locked, [
                    'status' => ['from' => IssuedInvoiceStatus::Draft->value, 'to' => IssuedInvoiceStatus::Issued->value],
                    'invoice_number' => $number,
                    'vat_regime' => $locked->vatRegime()->value,
                ]);
            });
        } catch (\Throwable $e) {
            // Filesystem s DB společnou transakci NEMÁ — soubor snapshotu
            // vzniklý tímto pokusem by po rollbacku zůstal osiřelý, proto se
            // kompenzačně smaže. Po ÚSPĚŠNÉM commitu se sem kód nedostane,
            // takže commitnutý doklad o soubor nikdy nepřijde.
            if ($capturedLogoPath !== null) {
                $this->logoSnapshots->discard($capturedLogoPath);
            }

            throw $e;
        }
    }

    /**
     * Předpoklady zvláštního režimu - použité zboží (§ 90 ZDPH). Kód
     * neposuzuje právní způsobilost prodeje — hlídá jen konzistenci dat:
     * organizace je plátce DPH, je zadána podporovaná interní sazba, měna
     * CZK, každá položka má pořizovací cenu, ceny nejsou záporné a množství
     * jsou celé kusy. Bez toho by interní DPH z přirážky byla tiše nulová
     * nebo by doklad nesl nepravdivý údaj o plátcovství.
     *
     * Volá se výhradně nad ZAMČENOU fakturou uvnitř issue().
     */
    private function assertUsedGoodsMarginIssuable(IssuedInvoice $invoice, ?OrganizationSettings $settings): void
    {
        if (! (bool) $settings?->vat_payer) {
            throw InvoiceNotIssuable::because(
                'zvláštní režim - použité zboží lze použít jen u plátce DPH, organizace je nyní nastavena jako neplátce.'
            );
        }

        $rate = $invoice->margin_vat_rate === null ? null : (string) $invoice->margin_vat_rate;

        if (! UsedGoodsMargin::isSupportedRate($rate)) {
            throw InvoiceNotIssuable::because('u zvláštního režimu chybí nebo není podporována interní sazba DPH z přirážky.');
        }

        if (strtoupper((string) $invoice->currency) !== UsedGoodsMargin::CURRENCY) {
            throw InvoiceNotIssuable::because('zvláštní režim - použité zboží je podporován jen v CZK.');
        }

        foreach ($invoice->items()->withoutGlobalScope('organization')->get() as $item) {
            $label = '„'.$item->description.'“';

            if ($item->acquisition_unit_price_minor === null) {
                throw InvoiceNotIssuable::because("položka {$label} nemá zadanou pořizovací cenu.");
            }

            if ((int) $item->acquisition_unit_price_minor < 0 || (int) $item->unit_price_minor < 0) {
                throw InvoiceNotIssuable::because("položka {$label} má zápornou cenu, ve zvláštním režimu to není povoleno.");
            }

            if (! UsedGoodsMargin::isWholePositiveQuantity((string) $item->quantity)) {
                throw InvoiceNotIssuable::because("položka {$label} musí mít množství v celých kusech (alespoň 1).");
            }
        }
    }

    public function registerPayment(
        IssuedInvoice $invoice,
        int $amountMinor,
        CarbonImmutable $paidOn,
        ?string $note = null,
    ): void {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Částka platby musí být kladná.');
        }

        $this->mutate($invoice, function (IssuedInvoice $locked) use ($amountMinor, $paidOn, $note): void {
            $this->applyPayment($locked, $amountMinor, $paidOn, $note);
        });
    }

    /**
     * Doplatí zbývající částku. Zbytek se počítá až nad zamčeným řádkem,
     * takže souběžné volání nevytvoří druhou doplatkovou platbu.
     */
    public function markPaid(IssuedInvoice $invoice, CarbonImmutable $paidOn): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked) use ($paidOn): void {
            $remaining = $locked->total_minor - $locked->paid_amount_minor;

            if ($remaining <= 0) {
                throw InvalidStateTransition::because(
                    'Faktura už nemá co doplatit.'
                );
            }

            $this->applyPayment($locked, $remaining, $paidOn, null);
        });
    }

    public function cancel(IssuedInvoice $invoice): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked): void {
            $this->assertTransition($locked->status, IssuedInvoiceStatus::Cancelled);

            if ($locked->paid_amount_minor > 0
                || $locked->payments()->withoutGlobalScope('organization')->exists()) {
                throw InvalidStateTransition::because(
                    'Stornovat lze jen fakturu bez evidovaných plateb.'
                );
            }

            $previousStatus = $locked->status;

            // Číslo faktury zůstává spotřebované — řada se nevrací (auditní stopa).
            $this->writeLifecycleState($locked, [
                'status' => IssuedInvoiceStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            $this->auditLogger->log('invoice.cancelled', $locked, [
                'status' => ['from' => $previousStatus->value, 'to' => IssuedInvoiceStatus::Cancelled->value],
            ]);
        });
    }

    /**
     * Smazání konceptu. Stav se ověřuje nad zamčeným řádkem, takže souběžné
     * vystavení a smazání se serializují a vystavenou fakturu nelze odstranit.
     */
    public function deleteDraft(IssuedInvoice $invoice): void
    {
        $this->mutate($invoice, function (IssuedInvoice $locked): void {
            if ($locked->status !== IssuedInvoiceStatus::Draft) {
                throw InvalidStateTransition::because('Smazat lze pouze koncept faktury.');
            }

            $locked->items()->withoutGlobalScope('organization')->delete();
            $locked->delete();
        });
    }

    /**
     * Přepis položek konceptu. Editovatelnost se rozhoduje až nad zamčeným
     * řádkem — controller o ní nerozhoduje podle dřív načtené instance.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(IssuedInvoice $invoice, array $header, array $items): void
    {
        // Kontrakt se vynucuje PŘED transakcí: klíč mimo whitelist je chyba
        // volajícího a shodí celé volání — nezapíše se nic, ani legitimní
        // část změny.
        $this->assertOnlyKeys($header, self::DRAFT_HEADER_ATTRIBUTES, 'hlavičky konceptu');

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(sprintf(
                    'Položka konceptu č. %d musí být pole atributů.',
                    $index + 1,
                ));
            }

            $this->assertOnlyKeys($item, self::DRAFT_ITEM_ATTRIBUTES, sprintf('položky konceptu č. %d', $index + 1));
        }

        $this->mutate($invoice, function (IssuedInvoice $locked) use ($header, $items): void {
            if ($locked->status !== IssuedInvoiceStatus::Draft) {
                throw InvalidStateTransition::because('Upravovat lze pouze koncept faktury.');
            }

            // Tenant kontrola referencí běží PŘED jakýmkoli zápisem —
            // hlavičkou, položkami, přepočtem i auditem. Selhání tedy
            // odvalí úplně všechno a faktura zůstane netknutá.
            $this->assertReferencesBelongToInvoice($locked, $header);

            $locked->forceFill($header)->save();

            $locked->items()->withoutGlobalScope('organization')->delete();

            foreach (array_values($items) as $position => $item) {
                $locked->items()->create($item + [
                    'organization_id' => $locked->organization_id,
                    'position' => $position + 1,
                ]);
            }

            $this->totalsCalculator->recalculate($locked);
        });
    }

    /**
     * Společné jádro obou platebních cest — běží už nad zamčeným řádkem.
     */
    private function applyPayment(
        IssuedInvoice $locked,
        int $amountMinor,
        CarbonImmutable $paidOn,
        ?string $note,
    ): void {
        if (! in_array($locked->status, [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid], true)) {
            throw InvalidStateTransition::because(
                'Platbu lze evidovat jen u vystavené či částečně uhrazené faktury.'
            );
        }

        $previousStatus = $locked->status;

        // Součet přes Money — přetečení skončí doménovou chybou, ne saturací.
        $newPaidAmount = Money::fromMinor($locked->paid_amount_minor, $locked->currency)
            ->plus(Money::fromMinor($amountMinor, $locked->currency));

        $total = Money::fromMinor($locked->total_minor, $locked->currency);

        if ($newPaidAmount->getMinor() > $total->getMinor()) {
            throw InvalidStateTransition::because(sprintf(
                'Platba %s převyšuje zbývající částku %s.',
                Money::fromMinor($amountMinor, $locked->currency)->formatCzech(),
                $total->minus(Money::fromMinor($locked->paid_amount_minor, $locked->currency))->formatCzech(),
            ));
        }

        $locked->payments()->create([
            'organization_id' => $locked->organization_id,
            'amount_minor' => $amountMinor,
            'currency' => $locked->currency,
            'paid_on' => $paidOn,
            'note' => $note,
        ]);

        $newStatus = $newPaidAmount->getMinor() >= $total->getMinor()
            ? IssuedInvoiceStatus::Paid
            : IssuedInvoiceStatus::PartiallyPaid;

        if ($newStatus !== $previousStatus) {
            $this->assertTransition($previousStatus, $newStatus);
        }

        $this->writeLifecycleState($locked, [
            'paid_amount_minor' => $newPaidAmount->getMinor(),
            'status' => $newStatus,
            'paid_at' => $newStatus === IssuedInvoiceStatus::Paid ? $paidOn : $locked->paid_at,
        ]);

        $this->auditLogger->log('invoice.payment_registered', $locked, [
            'amount_minor' => $amountMinor,
            'paid_on' => $paidOn->toDateString(),
            'status' => ['from' => $previousStatus->value, 'to' => $newStatus->value],
        ]);
    }

    /**
     * Interní zápis lifecycle polí nad ZAMČENÝM modelem. Váže se do
     * privátního scope IssuedInvoice (obdoba friend třídy) — model žádnou
     * veřejnou zápisovou metodu lifecycle polí nenabízí, takže běžný
     * aplikační kód tuto cestu nemá. Pole každého zápisu jsou natvrdo
     * vyjmenovaná v jednotlivých operacích výše; model navíc drží vlastní
     * whitelist jako defense-in-depth.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeLifecycleState(IssuedInvoice $locked, array $attributes): void
    {
        \Closure::bind(
            function (array $attributes): void {
                /** @var IssuedInvoice $this */
                $this->persistLifecycleState($attributes);
            },
            $locked,
            IssuedInvoice::class,
        )($attributes);
    }

    /**
     * Každá nenulová reference hlavičky musí existovat a patřit STEJNÉ
     * organizaci jako zamčená faktura. Dotazy jdou bez globálního scope
     * a porovnávají organization_id explicitně — ambientní tenant context
     * není důkaz vlastnictví.
     *
     * @param  array<string, mixed>  $header
     *
     * @throws InvalidInvoiceReference
     */
    private function assertReferencesBelongToInvoice(IssuedInvoice $locked, array $header): void
    {
        $organizationId = (int) $locked->organization_id;

        foreach (self::DRAFT_REFERENCES as $attribute => $model) {
            if (! array_key_exists($attribute, $header)) {
                continue;
            }

            $value = $header[$attribute];

            // null = reference se odebírá; povinnost contact_id
            // a number_series_id vynucuje až vystavení.
            if ($value === null || $value === '') {
                continue;
            }

            $belongs = $model::query()
                ->withoutGlobalScope('organization')
                ->whereKey($value)
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $belongs) {
                throw InvalidInvoiceReference::forAttribute($attribute, $value);
            }
        }
    }

    /**
     * Klíče mimo whitelist jsou programátorská chyba volajícího — tiché
     * ignorování by skrylo pokus zapsat organization_id, status a podobně.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     */
    private function assertOnlyKeys(array $attributes, array $allowed, string $subject): void
    {
        $unexpected = array_diff(array_keys($attributes), $allowed);

        if ($unexpected !== []) {
            throw new InvalidArgumentException(sprintf(
                'Nepovolené atributy %s: %s.',
                $subject,
                implode(', ', $unexpected),
            ));
        }
    }

    /**
     * Transakce + tenant-scoped zamčení + provedení operace nad zamčeným
     * záznamem. Volajícího instance se na závěr srovná s DB.
     */
    private function mutate(IssuedInvoice $invoice, callable $operation): void
    {
        $organizationId = $this->expectedOrganizationId($invoice);
        $key = $invoice->getKey();

        if ($key === null) {
            throw new InvalidArgumentException('Fakturu je nutné nejdřív uložit.');
        }

        DB::transaction(function () use ($invoice, $operation, $organizationId, $key): void {
            /** @var IssuedInvoice|null $locked */
            $locked = IssuedInvoice::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereKey($key)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw InvoiceNotFound::forKey($key);
            }

            $operation($locked);

            // Volající drží instanci, se kterou dál pracuje (redirecty, PDF).
            if ($locked->exists) {
                $invoice->setRawAttributes($locked->getAttributes(), true);
            } else {
                $invoice->exists = false;
            }
        });
    }

    /**
     * Organizace, na kterou se zamčené načtení omezí. Nastavený tenant
     * context musí souhlasit s organizací faktury — jinak fail closed.
     */
    private function expectedOrganizationId(IssuedInvoice $invoice): int
    {
        $invoiceOrganizationId = $invoice->organization_id;

        if ($invoiceOrganizationId === null) {
            throw new InvalidArgumentException('Faktura nemá přiřazenou organizaci.');
        }

        $current = $this->currentOrganization->id();

        if ($current !== null && $current !== (int) $invoiceOrganizationId) {
            throw InvoiceNotFound::forKey($invoice->getKey());
        }

        return (int) $invoiceOrganizationId;
    }

    /**
     * Běžná vydaná faktura musí mít kladný součet. Záporné položky (slevy)
     * zůstávají povolené, dokud je výsledek kladný.
     */
    private function assertPositiveTotal(IssuedInvoice $invoice): void
    {
        if ((int) $invoice->total_minor <= 0) {
            throw InvoiceNotIssuable::because(sprintf(
                'celková částka musí být kladná (aktuálně %s). Dobropisy a nulové doklady zatím nejsou podporované.',
                Money::fromMinor((int) $invoice->total_minor, $invoice->currency)->formatCzech(),
            ));
        }
    }

    private function assertTransition(IssuedInvoiceStatus $from, IssuedInvoiceStatus $to): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw InvalidStateTransition::between($from->value, $to->value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierSnapshot(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'ico' => $organization->ico,
            'dic' => $organization->dic,
            'street' => $organization->street,
            'city' => $organization->city,
            'zip' => $organization->zip,
            'country' => $organization->country,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'website' => $organization->website,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSnapshot(IssuedInvoice $invoice): array
    {
        /** @var Contact $contact */
        $contact = $invoice->contact()->withoutGlobalScope('organization')->firstOrFail();

        return [
            'name' => $contact->name,
            'ico' => $contact->ico,
            'dic' => $contact->dic,
            'street' => $contact->street,
            'city' => $contact->city,
            'zip' => $contact->zip,
            'country' => $contact->country,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bankAccountSnapshot(IssuedInvoice $invoice): ?array
    {
        /** @var BankAccount|null $account */
        $account = $invoice->bankAccount()->withoutGlobalScope('organization')->first();

        if ($account === null) {
            return null;
        }

        return [
            'account_number' => $account->account_number,
            'bank_code' => $account->bank_code,
            'iban' => $account->iban,
            'bic' => $account->bic,
        ];
    }
}
