<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactType;
use App\Http\Requests\ProjectRequest;
use App\Models\Contact;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        $projects = Project::query()
            ->with('contact')
            ->withCount(['issuedInvoices', 'receivedInvoices'])
            ->orderBy('name')
            ->paginate(25);

        return view('projects.index', ['projects' => $projects]);
    }

    public function create(): View
    {
        return view('projects.create', ['clients' => $this->clients()]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        Project::create($request->validated());

        return redirect()->route('projects.index')->with('status', 'Projekt byl vytvořen.');
    }

    public function edit(Project $project): View
    {
        return view('projects.edit', ['project' => $project, 'clients' => $this->clients()]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()->route('projects.index')->with('status', 'Projekt byl upraven.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        if ($project->issuedInvoices()->exists() || $project->receivedInvoices()->exists()) {
            return redirect()->route('projects.index')
                ->with('error', 'Projekt nelze smazat — jsou k němu přiřazeny faktury.');
        }

        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Projekt byl smazán.');
    }

    private function clients()
    {
        return Contact::query()->orderBy('name')->get();
    }
}
