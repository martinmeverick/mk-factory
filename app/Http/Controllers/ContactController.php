<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactType;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('typ');

        $contacts = Contact::query()
            ->when(in_array($type, ['customer', 'supplier'], true), function ($query) use ($type) {
                $query->whereIn('type', [$type, ContactType::Both->value]);
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('contacts.index', ['contacts' => $contacts, 'type' => $type]);
    }

    public function create(): View
    {
        return view('contacts.create');
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        Contact::create($request->validated());

        return redirect()->route('contacts.index')->with('status', 'Kontakt byl vytvořen.');
    }

    public function edit(Contact $contact): View
    {
        return view('contacts.edit', ['contact' => $contact]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update($request->validated());

        return redirect()->route('contacts.index')->with('status', 'Kontakt byl upraven.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        if ($contact->issuedInvoices()->exists() || $contact->receivedInvoices()->exists()) {
            return redirect()->route('contacts.index')
                ->with('error', 'Kontakt nelze smazat — existují k němu faktury.');
        }

        $contact->delete();

        return redirect()->route('contacts.index')->with('status', 'Kontakt byl smazán.');
    }
}
