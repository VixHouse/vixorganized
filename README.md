# VIXORGANIZE

Fila priorizada de projetos com kanban de fases e checklist por fase. Feito para o gerente tocar os projetos sem aprender ferramenta nova: entra, vê a ordem de prioridade, abre o projeto, marca o CHECK do passo que concluiu.

Stack: Laravel 13 + PHP 8.3 + Postgres (Supabase) + Blade + Alpine.js + Tailwind 4 + Lucide.

Interface: tema claro e escuro (botão no topo, segue o sistema por padrão), tokens HSL em `resources/css/app.css` (trocar a identidade inteira é trocar os valores lá), fontes Inter (texto), Plus Jakarta Sans (títulos) e JetBrains Mono (números). Atalho `N` abre novo projeto.

## O que faz

- **Fila de projetos** com rank ordenável (só existe um projeto na posição 1). Arrasta pela alça ou usa ▲▼.
- **Limite de WIP**: acima de `VIX_WIP_LIMIT` projetos "em andamento" a tela avisa em vermelho.
- **Kanban por projeto**: cada fase é uma coluna. Fases podem ser renomeadas (duplo clique), reordenadas (arrastar), adicionadas e removidas.
- **Checklist por fase**: cada passo tem CHECK. Quando todos os passos da fase estão marcados, a fase fecha sozinha e a "fase atual" avança. Quando todas as fases fecham, o projeto vira "Concluído".
- **Pipeline padrão** aplicado a todo projeto novo (6 fases: Descoberta, Planejamento, Execução, Validação, Entrega, Encerramento). Editável em `config/vix.php`.
- **Histórico**: quem marcou o quê e quando, por projeto.
- **Detalhe do passo**: clicar no passo abre um painel lateral com título, observação, status e um registro (comentários com nome, data e hora).
- **Sidebar** com a fila de projetos para pular entre eles. Anterior/próximo no projeto (atalhos `[` e `]`).
- **Mobile**: menu vira drawer, painel do passo vira folha inferior.
- **API JSON** somente leitura para n8n / relatórios: `GET /api/projetos` e `GET /api/projetos/{slug}` com header `X-Api-Key`.

## Rodar local (SQLite, sem Supabase)

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # opcional: 4 projetos de exemplo
npm run build
php artisan serve
```

Acesse http://localhost:8000. Usuários criados pelo seed (senha padrão `vixorganize`, definida em `SEED_PASSWORD`):

| Usuário | E-mail |
|---|---|
| Vitão | vitor@vixorganize.local |
| Felipe | felipe@vixorganize.local |

Troque nome, e-mail e senha no `.env` (`SEED_*`) antes de rodar o seed, ou edite direto na tabela `users`.

## Conectar no Supabase

No painel do Supabase: **Project Settings → Database → Connection string**. Use o **Session pooler** (porta 5432) para migrations e o app; o Transaction pooler (6543) não suporta prepared statements e quebra o Laravel.

No `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=aws-0-sa-east-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.SEU_PROJECT_REF
DB_PASSWORD=SUA_SENHA
DB_SSLMODE=require
```

Depois:

```bash
php artisan migrate --seed
```

O Supabase Auth não é usado: o login é sessão do próprio Laravel (tabela `users`). Se quiser trocar para Supabase Auth mais tarde, só o `AuthController` muda.

## API para n8n

Defina `VIX_API_KEY` no `.env` (qualquer string longa). Com a chave vazia a API fica desligada.

```bash
curl -H "X-Api-Key: SUA_CHAVE" https://seu-host/api/projetos
```

Retorna rank, status, fase atual, progresso e prazo de cada projeto. O endpoint por slug retorna também as fases e os passos com quem marcou e quando.

### Escrita (AlfaMatriz)

Defina `VIX_API_WRITE_KEY` (outra string longa, diferente da de leitura). Vazia, a escrita fica desligada. Ela também serve para ler. Só vale no header `X-Api-Key`, nunca em querystring.

| Método e caminho | Corpo | O que faz |
|---|---|---|
| `POST /api/projetos` | `name`, `description?`, `due_date?`, `status?`, `use_template?` (padrão: sim) | Cria o projeto no fim da fila, com o pipeline padrão |
| `POST /api/projetos/{slug}/status` | `status` | Muda o status |
| `POST /api/projetos/{slug}/passos` | `title`, `notes?`, `phase_id?` | Lança uma demanda: passo novo na fase pedida ou na fase atual; fase concluída reabre |
| `POST /api/projetos/{slug}/passos/{id}/check` | `done` (true/false) | Marca ou desmarca; repetir não duplica. As fases e o projeto fecham sozinhos como na tela |
| `POST /api/projetos/{slug}/passos/{id}/registros` | `body` | Registro (comentário) no passo |

Headers opcionais: `X-Vix-Autor` (e-mail de um usuário daqui, que vira o autor) e `X-Vix-Origem` (aparece no histórico como "(via AlfaMatriz)"). As respostas trazem o projeto atualizado no mesmo formato do `GET /api/projetos/{slug}`. Limite: 60 pedidos por minuto.

## Estrutura

```
app/Models/Project.php        projeto, rank, status, fase atual, progresso
app/Models/Phase.php          coluna do kanban; fecha sozinha quando todos os passos estão feitos
app/Models/Step.php           passo com CHECK (done_at, done_by)
app/Models/Activity.php       histórico
app/Services/ProjectTemplate.php  aplica o pipeline padrão
config/vix.php                limite de WIP, statuses e o pipeline padrão
resources/views/projects/     index (fila), show (kanban), create/edit
```

## Deploy

Qualquer host PHP 8.3 com `pdo_pgsql` serve (Laravel Forge, Railway, Render, VPS com Nginx). Rode `npm run build` antes de subir ou no pipeline, e aponte o document root para `public/`.
