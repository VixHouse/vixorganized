<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API de escrita (AlfaMatriz lança demandas): chave própria e as mesmas regras das telas. */
class ApiEscritaTest extends TestCase
{
    use RefreshDatabase;

    private const LEITURA = 'chave-de-leitura-n8n';

    private const ESCRITA = 'chave-de-escrita-matriz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['vix.api_key' => self::LEITURA, 'vix.api_write_key' => self::ESCRITA]);
    }

    private function escrita(array $extra = []): array
    {
        return ['X-Api-Key' => self::ESCRITA, 'Accept' => 'application/json'] + $extra;
    }

    public function test_escrita_exige_a_chave_de_escrita_no_header(): void
    {
        $this->postJson('/api/projetos', ['name' => 'X'], ['X-Api-Key' => self::LEITURA])->assertUnauthorized();
        $this->postJson('/api/projetos?api_key='.self::ESCRITA, ['name' => 'X'])->assertUnauthorized();
        config(['vix.api_write_key' => '']);
        $this->postJson('/api/projetos', ['name' => 'X'], ['X-Api-Key' => ''])->assertUnauthorized();
        $this->assertSame(0, Project::count());
    }

    public function test_leitura_aceita_as_duas_chaves(): void
    {
        $this->getJson('/api/projetos', ['X-Api-Key' => self::LEITURA])->assertOk();
        $this->getJson('/api/projetos', ['X-Api-Key' => self::ESCRITA])->assertOk();
        $this->getJson('/api/projetos', ['X-Api-Key' => 'outra'])->assertUnauthorized();
    }

    public function test_cria_projeto_com_o_pipeline_e_o_autor(): void
    {
        $felipe = User::factory()->create(['email' => 'felipe@vix.local', 'name' => 'Felipe']);

        $r = $this->postJson('/api/projetos', ['name' => 'Totem RFID', 'due_date' => '2026-11-30'],
            $this->escrita(['X-Vix-Autor' => 'felipe@vix.local', 'X-Vix-Origem' => 'AlfaMatriz']))->assertCreated();

        $r->assertJsonPath('data.name', 'Totem RFID')->assertJsonPath('data.status', 'aguardando')
            ->assertJsonPath('data.current_phase', 'Descoberta')->assertJsonPath('data.owner', 'Felipe')
            ->assertJsonCount(count(config('vix.template')), 'data.phases');
        $p = Project::sole();
        $this->assertSame('totem-rfid', $p->slug);
        $this->assertSame('Projeto criado: Totem RFID (via AlfaMatriz)', $p->activities()->sole()->description);
        $this->assertSame($felipe->id, $p->activities()->sole()->user_id);
    }

    public function test_demanda_entra_na_fase_atual_e_reabre_fase_fechada(): void
    {
        $p = $this->projeto();
        $descoberta = $p->phases->first();
        foreach ($descoberta->steps as $s) {
            $this->postJson("/api/projetos/{$p->slug}/passos/{$s->id}/check", ['done' => true], $this->escrita())->assertOk();
        }
        $this->assertNotNull($descoberta->fresh()->completed_at);
        $this->assertSame('Planejamento', $p->fresh()->currentPhase->name);

        // Sem fase: vai para a atual.
        $r = $this->postJson("/api/projetos/{$p->slug}/passos", ['title' => 'Cotar leitores UHF', 'notes' => 'ver 3 fornecedores'], $this->escrita())->assertCreated();
        $this->assertSame('Planejamento', Step::find($r->json('data.step_id'))->phase->name);

        // Na fase concluída: ela reabre e volta a ser a atual.
        $this->postJson("/api/projetos/{$p->slug}/passos", ['title' => 'Validar com o cliente', 'phase_id' => $descoberta->id], $this->escrita())
            ->assertCreated()->assertJsonPath('data.project.current_phase', 'Descoberta');
        $this->assertNull($descoberta->fresh()->completed_at);
    }

    public function test_fase_de_outro_projeto_e_passo_de_outro_projeto_sao_recusados(): void
    {
        $a = $this->projeto('A');
        $b = $this->projeto('B');

        $this->postJson("/api/projetos/{$a->slug}/passos", ['title' => 'x', 'phase_id' => $b->phases->first()->id], $this->escrita())->assertStatus(422);
        $passoDeB = $b->phases->first()->steps->first();
        $this->postJson("/api/projetos/{$a->slug}/passos/{$passoDeB->id}/check", ['done' => true], $this->escrita())->assertNotFound();
        $this->postJson("/api/projetos/{$a->slug}/passos/{$passoDeB->id}/registros", ['body' => 'x'], $this->escrita())->assertNotFound();
        $this->assertFalse($passoDeB->fresh()->is_done);
    }

    public function test_marcar_e_idempotente_e_fechar_tudo_conclui_o_projeto(): void
    {
        $p = $this->projeto();
        $passo = $p->phases->first()->steps->first();

        $this->postJson("/api/projetos/{$p->slug}/passos/{$passo->id}/check", ['done' => true], $this->escrita())->assertOk();
        $this->postJson("/api/projetos/{$p->slug}/passos/{$passo->id}/check", ['done' => true], $this->escrita())->assertOk();
        $this->assertSame(1, $p->activities()->where('action', 'check')->count());

        foreach (Step::all() as $s) {
            $this->postJson("/api/projetos/{$p->slug}/passos/{$s->id}/check", ['done' => true], $this->escrita());
        }
        $this->assertSame('concluido', $p->fresh()->status);

        $this->postJson("/api/projetos/{$p->slug}/passos/{$passo->id}/check", ['done' => false], $this->escrita())
            ->assertOk()->assertJsonPath('data.status', 'em_andamento');
    }

    public function test_registro_e_status(): void
    {
        $p = $this->projeto();
        $passo = $p->phases->first()->steps->first();

        $this->postJson("/api/projetos/{$p->slug}/passos/{$passo->id}/registros", ['body' => 'Cliente pediu orçamento até sexta'], $this->escrita())->assertCreated();
        $this->assertSame('Cliente pediu orçamento até sexta', $passo->comments()->sole()->body);

        $this->postJson("/api/projetos/{$p->slug}/status", ['status' => 'pausado'], $this->escrita())->assertOk()->assertJsonPath('data.status', 'pausado');
        $this->postJson("/api/projetos/{$p->slug}/status", ['status' => 'inventado'], $this->escrita())->assertStatus(422);
    }

    private function projeto(string $nome = 'Projeto'): Project
    {
        $slug = $this->postJson('/api/projetos', ['name' => $nome], $this->escrita())->assertCreated()->json('data.slug');

        return Project::where('slug', $slug)->with('phases.steps')->sole();
    }
}
