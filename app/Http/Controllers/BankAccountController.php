<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\CzechIban;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                BankAccount::query()->update(['is_default' => false]);
            }
            BankAccount::create($data);
        });

        return redirect()->route('settings.edit')->with('status', 'Bankovní účet byl přidán.');
    }

    public function update(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $bankAccount) {
            if ($data['is_default']) {
                BankAccount::query()->whereKeyNot($bankAccount->id)->update(['is_default' => false]);
            }
            $bankAccount->update($data);
        });

        return redirect()->route('settings.edit')->with('status', 'Bankovní účet byl upraven.');
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->delete();

        return redirect()->route('settings.edit')->with('status', 'Bankovní účet byl smazán.');
    }

    /**
     * Validace + dopočet IBAN z čísla účtu, pokud nebyl zadán.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'regex:/^(\d{1,6}-)?\d{1,10}$/'],
            'bank_code' => ['required', 'digits:4'],
            'iban' => ['nullable', 'string', 'max:34'],
            'bic' => ['nullable', 'string', 'max:11'],
            'is_default' => ['nullable', 'boolean'],
        ], [
            'account_number.regex' => 'Číslo účtu zadejte ve formátu 123456789 nebo 19-123456789.',
        ], [
            'name' => 'název účtu', 'account_number' => 'číslo účtu',
            'bank_code' => 'kód banky', 'iban' => 'IBAN', 'bic' => 'BIC',
        ]);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        if (empty($data['iban'])) {
            $data['iban'] = CzechIban::fromAccountNumber($data['account_number'], $data['bank_code']);
        } else {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban']));

            if (! CzechIban::isValid($data['iban'])) {
                throw ValidationException::withMessages(['iban' => 'Zadaný IBAN není platný.']);
            }
        }

        return $data;
    }
}
