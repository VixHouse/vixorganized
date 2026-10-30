<?php

return [
    // Limite de projetos "em andamento" ao mesmo tempo. Acima disso a tela avisa.
    'wip_limit' => (int) env('VIX_WIP_LIMIT', 3),

    // Chave para a API JSON (n8n, integrações). Vazio = API desligada.
    'api_key' => env('VIX_API_KEY'),

    // Chave da API de escrita (AlfaMatriz e outras integrações que lançam demandas).
    // Vazio = escrita desligada. Também vale para a leitura.
    'api_write_key' => env('VIX_API_WRITE_KEY'),

    'statuses' => [
        'aguardando'   => ['label' => 'Aguardando',   'color' => 'slate'],
        'em_andamento' => ['label' => 'Em andamento', 'color' => 'blue'],
        'pausado'      => ['label' => 'Pausado',      'color' => 'amber'],
        'concluido'    => ['label' => 'Concluído',    'color' => 'emerald'],
    ],

    // Pipeline padrão aplicado a todo projeto novo. Cada fase vira uma coluna do kanban
    // e cada item vira um passo com CHECK. Depois de criado, tudo pode ser editado.
    'template' => [
        'Descoberta' => [
            'Definir objetivo e resultado esperado',
            'Levantar necessidades e requisitos',
            'Identificar envolvidos e responsável',
            'Estimativa inicial de custo',
        ],
        'Planejamento' => [
            'Definir escopo e entregáveis',
            'Definir cronograma e marcos',
            'Cotar fornecedores / parceiros',
            'Aprovar orçamento',
        ],
        'Execução' => [
            'Contratar / comprar',
            'Executar entregáveis',
            'Acompanhamento semanal',
            'Registrar riscos e bloqueios',
        ],
        'Validação' => [
            'Conferir / testar entregáveis',
            'Ajustes e correções',
            'Aprovação final',
        ],
        'Entrega' => [
            'Colocar em operação',
            'Treinar envolvidos',
            'Documentar',
        ],
        'Encerramento' => [
            'Fechar custos',
            'Lições aprendidas',
            'Arquivar',
        ],
    ],
];
