<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationSelectController extends Controller
{
    public function index(Request $request): View
    {
        return view('organizations.select', [
            'organizations' => $request->user()->organizations()->orderBy('name')->get(),
        ]);
    }

    public function select(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);

        $request->session()->put('current_organization_id', $organization->id);

        return redirect()->route('dashboard');
    }
}
