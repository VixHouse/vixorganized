<?php

namespace App\Http\Controllers\Api;

use App\Models\Phase;
use App\Models\Project;
use App\Models\Step;
use App\Models\User;
use App\Services\ProjectTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API JSON de escrita para integrações que lançam demandas (AlfaMatriz).
 * Protegida por X-Api-Key com VIX_API_WRITE_KEY (ver api.key:escrita).
 *
 * Faz o mesmo que as telas: cria projeto com o pipeline padrão, adiciona
 * passo, marca/desmarca, registra e muda status — com as fases fechando e
 * reabrindo sozinhas e tudo no histórico. Quem fez vem do header
 * X-Vix-Autor (e-mail de um usuário daqui); sem ele, fica sem autor e o
 * histórico diz de onde veio.
 */
class ProjectWriteApiController extends ProjectApiController
{
    public function storeProject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(array_keys(config('vix.statuses')))],
            'due_date' => ['nullable', 'date'],
            'use_template' => ['nullable', 'boolean'],
        ]);
        $autor = $this->autor($request);

        $project = DB::transaction(function () use ($data, $autor, $request) {
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'aguardando',
                'due_date' => $data['due_date'] ?? null,
                'owner_id' => $autor?->id,
            ]);
            if ($request->boolean('use_template', true)) {
                ProjectTemplate::apply($project);
            }
            $project->log('criou', "Projeto criado: {$project->name}".$this->origem($request), $autor?->id);

            return $project;
        });

        return response()->json(['data' => $this->detalhe($project)], 201);
    }

    public function status(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('vix.statuses')))],
        ]);

        $project->status = $data['status'];
        $project->completed_at = $data['status'] === 'concluido' ? ($project->completed_at ?? now()) : null;
        $project->save();
        $project->log('status', "Status: {$project->statusLabel()}".$this->origem($request), $this->autor($request)?->id);

        return response()->json(['data' => $this->detalhe($project)]);
    }

    /** A demanda: um passo novo na fase pedida, ou na fase atual. */
    public function storeStep(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'phase_id' => ['nullable', 'integer'],
        ]);
        $autor = $this->autor($request);

        $step = DB::transaction(function () use ($data, $project, $autor, $request) {
            $phase = $this->faseDestino($project, $data['phase_id'] ?? null);
            $position = (int) $phase->steps()->max('position') + 1;
            $step = $phase->steps()->create(['title' => $data['title'], 'notes' => $data['notes'] ?? null, 'position' => $position]);
            $project->log('passo', "Passo adicionado em {$phase->name}: {$step->title}".$this->origem($request), $autor?->id);

            // Fase que estava concluída ganhou um passo novo -> reabre.
            if ($phase->syncCompletion()) {
                $project->syncCurrentPhase();
            }

            return $step;
        });

        return response()->json(['data' => ['step_id' => $step->id, 'phase_id' => $step->phase_id, 'project' => $this->detalhe($project)]], 201);
    }

    /** Marca ou desmarca (done=true/false). Repetir o mesmo pedido não muda nada. */
    public function check(Request $request, Project $project, Step $step): JsonResponse
    {
        $phase = $this->faseDoPasso($project, $step);
        $request->validate(['done' => ['required', 'boolean']]);
        $done = $request->boolean('done');
        $autor = $this->autor($request);

        if ($step->is_done !== $done) {
            DB::transaction(function () use ($step, $phase, $project, $done, $autor, $request) {
                $step->is_done = $done;
                $step->done_at = $done ? now() : null;
                $step->done_by = $done ? $autor?->id : null;
                $step->save();

                $project->log('check', ($done ? '✔ ' : '↩ ').$phase->name.': '.$step->title.$this->origem($request), $autor?->id);
                if ($phase->syncCompletion()) {
                    $project->log('fase', ($phase->isDone() ? 'Fase concluída: ' : 'Fase reaberta: ').$phase->name, $autor?->id);
                }
                $project->syncCurrentPhase();
            });
        }

        return response()->json(['data' => $this->detalhe($project)]);
    }

    public function comment(Request $request, Project $project, Step $step): JsonResponse
    {
        $this->faseDoPasso($project, $step);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $autor = $this->autor($request);

        $comment = $step->comments()->create(['user_id' => $autor?->id, 'body' => $data['body']]);
        $project->log('registro', "Registro em {$step->title}: ".Str::limit($data['body'], 120).$this->origem($request), $autor?->id);

        return response()->json(['data' => ['comment_id' => $comment->id]], 201);
    }

    /** O mesmo formato do GET /api/projetos/{slug}, já atualizado. */
    private function detalhe(Project $project): array
    {
        return $this->show($project->fresh())->getData(true)['data'];
    }

    private function autor(Request $request): ?User
    {
        $email = trim((string) $request->header('X-Vix-Autor'));

        return $email === '' ? null : User::where('email', $email)->first();
    }

    /** Sufixo do histórico: de onde o pedido veio. */
    private function origem(Request $request): string
    {
        $de = Str::limit(preg_replace('/[^\pL\pN ._-]/u', '', (string) $request->header('X-Vix-Origem', 'API')), 40, '');

        return ' (via '.($de ?: 'API').')';
    }

    private function faseDestino(Project $project, ?int $phaseId): Phase
    {
        if ($phaseId !== null) {
            return $project->phases()->whereKey($phaseId)->firstOr(fn () => abort(response()->json(['message' => 'Fase não é deste projeto.'], 422)));
        }

        return $project->currentPhase
            ?? $project->phases()->reorder()->orderByDesc('position')->first()
            ?? $project->phases()->create(['name' => 'Demandas', 'position' => 1]);
    }

    private function faseDoPasso(Project $project, Step $step): Phase
    {
        $phase = $step->phase;
        abort_unless($phase && $phase->project_id === $project->id, 404);

        return $phase;
    }
}
