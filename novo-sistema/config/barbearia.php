<?php

/*
|--------------------------------------------------------------------------
| Configuracao propria do sistema da barbearia
|--------------------------------------------------------------------------
|
| Somente parametros de INFRAESTRUTURA e de exibicao vivem aqui, lidos do
| ambiente (.env). Regras de negocio configuraveis pelo dono (antecedencia,
| fidelidade, lembretes...) serao gravadas em banco, com tipos e validacao,
| a partir da Fase 2 -- nunca como JSON livre (ver arquitetura-nova.md).
|
| Segredos (senhas, tokens, chaves) NUNCA ficam neste arquivo nem em codigo:
| apenas em variaveis de ambiente.
|
*/

return [

    // Fuso em que datas e horas sao EXIBIDAS. O banco grava em UTC
    // (config/app.php 'timezone' => 'UTC'); a conversao acontece na borda.
    'display_timezone' => env('BARBEARIA_TIMEZONE', 'America/Sao_Paulo'),

    // Moeda e localidade de formatacao de valores.
    'currency' => env('BARBEARIA_CURRENCY', 'BRL'),

    // Paginas de referencia visual (/prototipos/*) e catalogo de componentes
    // (/design-system). Usam apenas dados de exemplo. Desligadas por padrao;
    // o .env de desenvolvimento e o de homologacao ligam explicitamente.
    'prototypes_enabled' => (bool) env('BARBEARIA_PROTOTYPES', false),

    // Disco das imagens publicas (fotos de profissionais, imagens de
    // servicos). Local: "public" (storage/app/public, servido em /storage
    // depois de `php artisan storage:link`). Producao pode usar outro disco
    // (ex.: s3) sem mudar codigo. Imagens nunca vao para o banco nem o Git.
    'media_disk' => env('MEDIA_DISK', 'public'),

    // Provedor de entrega dos e-mails do dominio (D-05; emails.md §7):
    // "mailer" = mailer do Laravel (MAIL_MAILER: log/array/smtp);
    // "resend" = API do Resend (chave em RESEND_API_KEY). Trocar de fornecedor
    // e so isto: fila, modelos e regras nao mudam.
    'email_provider' => env('EMAIL_PROVIDER', 'mailer'),

    'queue' => [
        // Em hospedagem sem supervisor de processos, o agendador sobe um
        // worker a cada minuto que processa a fila e encerra (ver
        // routes/console.php). Em VPS com supervisor, deixe false.
        'work_via_scheduler' => (bool) env('QUEUE_WORK_VIA_SCHEDULER', true),
    ],

    'scheduler' => [
        // Depois de quantos minutos sem batimento o agendador e considerado parado.
        'heartbeat_tolerance_minutes' => (int) env('SCHEDULER_HEARTBEAT_TOLERANCE', 5),
    ],

    // Limites e validades das contas (ver docs/reconstrucao/seguranca-contas.md).
    'security' => [
        // Tentativas de login por minuto, por conta + IP (equipe e clientes).
        // Por IP, sozinho, o limite e 6x maior (contra "password spraying").
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),

        // Pedidos de e-mail (redefinicao, link magico) por IP a cada 10 min.
        'email_requests_per_ip' => (int) env('AUTH_EMAIL_REQUESTS_PER_IP', 10),

        // Cadastros de cliente por IP por hora.
        'registrations_per_ip' => (int) env('AUTH_REGISTRATIONS_PER_IP', 5),

        // Validade do link magico do cliente (minutos).
        'magic_link_minutes' => (int) env('AUTH_MAGIC_LINK_MINUTES', 15),

        // Validade do link de confirmacao de e-mail (minutos).
        'email_verification_minutes' => (int) env('AUTH_EMAIL_VERIFICATION_MINUTES', 60 * 24),
    ],

];
