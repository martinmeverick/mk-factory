<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ares\AresClient;
use App\Domain\Ares\AresSubject;
use App\Domain\Ares\AresUnavailable;
use App\Domain\Contacts\CzechIco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Čtecí endpoint pro předvyplňování formulářů z ARESu.
 * Nic neukládá — samotné založení kontaktu dělá až odeslání formuláře,
 * takže aplikace funguje i bez JavaScriptu.
 */
class AresLookupController extends Controller
{
    public function __construct(
        private readonly AresClient $ares,
    ) {
    }

    public function show(string $ico): JsonResponse
    {
        if (! CzechIco::isValid($ico)) {
            return response()->json([
                'message' => 'Zadané IČO nemá platný formát ani kontrolní číslici.',
            ], 422);
        }

        try {
            $subject = $this->ares->findByIco($ico);
        } catch (AresUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($subject === null) {
            return response()->json([
                'message' => 'Subjekt s tímto IČO nebyl v registru nalezen.',
            ], 404);
        }

        return response()->json(['subject' => $this->present($subject)]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ], [], ['q' => 'hledaný název']);

        try {
            $subjects = $this->ares->searchByName($data['q'], 10);
        } catch (AresUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'subjects' => array_map(fn (AresSubject $s): array => $this->present($s), $subjects),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AresSubject $subject): array
    {
        return [
            'ico' => $subject->ico,
            'name' => $subject->name,
            'dic' => $subject->dic,
            'vat_payer' => $subject->vatPayer,
            'street' => $subject->street,
            'city' => $subject->city,
            'zip' => $subject->zip,
            'country' => $subject->country,
            'text_address' => $subject->textAddress,
        ];
    }
}
