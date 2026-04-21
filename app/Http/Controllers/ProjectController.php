<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Project::class, $workspace]);

        $projects = $workspace->projects()
            ->with('creator')           // N+1対策
            // ->withCount('tasks')        // ← Day5で追加予定（今は削除OK）
            ->latest()
            ->get();

        return Inertia::render('Projects/Index', [
            'workspace' => $workspace,
            'projects'  => $projects,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('create', [Project::class, $workspace]);

        $workspace->projects()->create([
            ...$request->validated(),    // バリデーション済みデータを展開
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを作成しました');
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを更新しました');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('workspaces.projects.index', $workspace)
            ->with('success', 'プロジェクトを削除しました');
    }
}
