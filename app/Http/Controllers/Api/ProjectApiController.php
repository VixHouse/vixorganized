<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * API JSON de leitura para integrações (n8n, relatórios, Claude).
 * Protegida por X-Api-Key (VIX_API_KEY ou VIX_API_WRITE_KEY). A escrita fica
 * em ProjectWriteApiController.
 */
class ProjectApiController extends Controller
{
    public function index(): JsonResponse
    {
        $projects = Project::with(['owner:id,name', 'currentPhase:id,name'])
            ->withCount(['steps', 'steps as done_steps_count' => fn ($q) => $q->where('is_done', true)])
            ->orderBy('rank')
            ->get()
            ->map(fn (Project $p) => $this->summary($p));

        return response()->json(['data' => $projects]);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load(['owner:id,name', 'currentPhase:id,name', 'phases.steps.doneBy:id,name']);

        $data = $this->summary($project);
        $data['description'] = $project->description;
        $data['phases'] = $project->phases->map(fn ($phase) => [
            'id' => $phase->id,
            'name' => $phase->name,
            'position' => $phase->position,
            'completed_at' => $phase->completed_at?->toIso8601String(),
            'steps' => $phase->steps->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'notes' => $s->notes,
                'is_done' => $s->is_done,
                'done_at' => $s->done_at?->toIso8601String(),
                'done_by' => $s->doneBy?->name,
            ]),
        ]);

        return response()->json(['data' => $data]);
    }

    protected function summary(Project $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'rank' => $p->rank,
            'status' => $p->status,
            'status_label' => $p->statusLabel(),
            'owner' => $p->owner?->name,
            'current_phase' => $p->currentPhase?->name,
            'progress' => $p->progress(),
            'due_date' => $p->due_date?->toDateString(),
            'completed_at' => $p->completed_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
