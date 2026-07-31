<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Naplní CurrentOrganization z session a ověří členství uživatele.
 * Bez platné organizace přesměruje na její výběr.
 */
class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = $request->session()->get('current_organization_id');

        $organization = null;

        if ($organizationId !== null) {
            $organization = Organization::query()->find($organizationId);

            if ($organization === null || ! $user->belongsToOrganization($organization)) {
                $request->session()->forget('current_organization_id');
                $organization = null;
            }
        }

        if ($organization === null) {
            $memberships = $user->organizations()->get();

            if ($memberships->count() === 1) {
                $organization = $memberships->first();
                $request->session()->put('current_organization_id', $organization->id);
            } else {
                return redirect()->route('organizations.select');
            }
        }

        app(CurrentOrganization::class)->set($organization);
        view()->share('currentOrganization', $organization);

        return $next($request);
    }
}
