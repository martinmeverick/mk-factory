<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function rules(): array
    {
        $projectId = $this->route('project')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'contact_id' => [
                'nullable', 'integer',
                Rule::exists('contacts', 'id')
                    ->where('organization_id', app(\App\Domain\Tenancy\CurrentOrganization::class)->id()),
            ],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'external_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('projects', 'external_id')
                    ->where('organization_id', app(\App\Domain\Tenancy\CurrentOrganization::class)->id())
                    ->ignore($projectId),
            ],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'název', 'code' => 'interní kód', 'contact_id' => 'klient',
            'status' => 'stav', 'external_id' => 'externí identifikátor', 'note' => 'poznámka',
        ];
    }
}
