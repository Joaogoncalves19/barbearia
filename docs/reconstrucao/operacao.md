# Operação da instalação (Fase 13)

O que roda sozinho, o que conferir e o que fazer quando algo falha. Instalação: [instalacao.md](instalacao.md).

## 1. Rotinas automáticas (cron → `schedule:run`)

| Quando | Comando | O quê |
|---|---|---|
| a cada minuto | `app:scheduler-heartbeat` | Batimento: prova que o cron está vivo |
| a cada minuto | `queue:work --stop-when-empty` | Entrega os e-mails da fila (hospedagem sem supervisor) |
| a cada minuto | `app:communication campaigns` | Lote de campanha (ritmo limitado) |
| a cada 5 min | `app:communication reminders` | Lembretes (véspera e horas antes) |
| a cada 10 min | `app:communication review-requests` | Pedido de avaliação 3 h depois do atendimento |
| **hora em hora** | **`app:diagnose --alert`** | **Monitoramento: avisa `MONITOR_EMAIL`** (§3) |
| 02:40 (horário da barbearia) | **`app:backup`** | **Cópia diária conferida** (§2) |
| 03:10 / 03:30 (UTC) | `app:subscriptions-expire`, `app:communication retention` | Links vencidos, assinaturas manuais, retenção (12 meses) |
| diária | `queue:prune-failed`, `queue:prune-batches` | Limpeza da fila |

Todas são idempotentes (rodar duas vezes não duplica nada) e têm `withoutOverlapping`.

## 2. Cópia de segurança e restauração

**O que entra:** o banco (cópia consistente pela API de backup do SQLite, com o sistema no ar) e os
arquivos enviados (`storage/app/public` e `storage/app/private`: fotos, arquivo morto e relatórios da
migração), mais um `manifest.json` com SHA-256 de cada parte e a contagem de linhas por tabela.
**O que não entra:** o `.env` (APP_KEY, chaves do Stripe e do e-mail). Guarde o `.env` e a
`BACKUP_PASSWORD` num gerenciador de senhas, fora do servidor.

| Comando | Para quê |
|---|---|
| `php artisan app:backup [--label=antes-da-virada]` | Gera, confere e guarda uma cópia; apaga as mais antigas que as `BACKUP_KEEP` (14) mantidas, **só depois** de a nova passar na conferência |
| `php artisan app:backup-verify [arquivo]` | Confere sem restaurar: hash do arquivo, manifesto, `integrity_check` do banco, contagem por tabela, cada arquivo |
| `php artisan app:backup-restore <arquivo> --to=<pasta nova>` | Ensaio de restauração: extrai numa pasta nova (nada da instalação é tocado) |
| `php artisan app:backup-restore <arquivo> --replace-current --force` | Restaura **no lugar**: confere, copia o estado atual (`…-antes-da-restauracao.zip`), entra em manutenção, guarda banco e arquivos atuais em `restauracao-<data>/`, coloca a cópia, roda migrations, confere a integridade e só então sai da manutenção |

- Cifra: com `BACKUP_PASSWORD`, cada parte do zip é AES-256 (sem a senha nada abre, nem o manifesto).
- **Fora do servidor:** uma vez por semana, leve a cópia mais recente para outro lugar (nuvem ou mídia
  externa). A cópia que só existe no mesmo servidor não protege contra perda do servidor.
- **Ensaio de restauração:** uma vez por mês, `--to=` numa pasta nova e `app:backup-verify` (5 min).

Ensaiado na homologação (Fase 13): cópia de 78 tabelas e 9 arquivos em < 1 s; restauração numa pasta nova
com contagem idêntica tabela a tabela; restauração no lugar com o sistema no ar em ~1 s, registro gravado
depois da cópia desapareceu, estado anterior preservado e sistema de volta ao ar com integridade OK.

## 3. Monitoramento básico

| O quê | Como |
|---|---|
| Site no ar | Monitor externo (UptimeRobot, Better Stack, o do provedor) chamando `https://dominio/up` a cada 5 min. `/up` responde 500 se o banco não responde |
| Cron, fila, e-mails, cópia | `app:diagnose --alert` de hora em hora: falha crítica ou problema de operação (agendador parado há > 5 min, jobs com falha, e-mails na fila há > 2 h, e-mails que falharam, cópia com mais de 26 h) vira `critical` no log e **um e-mail para `MONITOR_EMAIL`**, no máximo uma vez a cada 6 h para o mesmo conjunto de problemas |
| Erros da aplicação | `storage/logs/laravel-AAAA-MM-DD.log` (14 dias), `jobs` (30 dias), `security` (90 dias). Nenhum guarda senha, token ou chave |
| Stripe | *Assinaturas → Eventos do Stripe* (recebido, aplicado, duplicado, antigo, erro) e o painel de webhooks do Stripe (entregas com falha) |
| E-mails | *Comunicação → E-mails enviados*: situação, tentativas, motivo; *Reenviar* o que falhou |

## 4. Quando algo falha

| Sinal | Causa provável | O que fazer |
|---|---|---|
| "Agendador ativo" em AVISO | Cron não configurado ou parou | Conferir a linha do cron e `php artisan schedule:run` à mão |
| E-mails na fila há > 2 h | Cron parado ou SMTP recusando | Ver o log `jobs`; testar o SMTP; *Reenviar* depois de resolver |
| Jobs com falha | Erro no envio ou no processamento | `php artisan queue:failed`; corrigir; `queue:retry all` |
| Cópia com mais de 26 h | Cron parado, disco cheio ou senha errada | `php artisan app:backup` à mão e ler a mensagem |
| `/up` com erro | Banco inacessível ou disco cheio | Logs; espaço em disco; permissões de `database/` |
| Webhook do Stripe com erro | Segredo trocado ou URL errada | `STRIPE_WEBHOOK_SECRET` igual ao do endpoint; reenviar o evento pelo painel do Stripe (é idempotente) |

## 5. Primeiros 30 dias (critério de estabilidade da Fase 13)

A Fase 13 só se considera **concluída** depois de 30 dias de operação estável em produção
([roadmap.md](roadmap.md)). Acompanhar e registrar (planilha ou anotação diária, 5 minutos):

| Métrica | Fonte | Meta |
|---|---|---|
| Disponibilidade do site | Monitor externo de `/up` | ≥ 99,5 % no mês; nenhuma queda > 30 min sem explicação |
| Cópia diária | `app:diagnose` / pasta de cópias | 30 de 30 dias aprovadas; 1 restauração de ensaio no período |
| Agendador | Diagnóstico de hora em hora | Nenhum aviso de agendador parado não resolvido em 1 h |
| E-mails | *E-mails enviados* | Falhas definitivas < 1 % e todas explicadas; nenhuma fila parada > 2 h |
| Webhooks do Stripe | *Eventos do Stripe* e painel do Stripe | 0 eventos com erro não reprocessados; assinaturas iguais nos dois lados |
| Caixa | Fechamentos | Diferenças explicadas; nenhuma divergência por erro do sistema |
| Comissões e repasses | Extrato × conferência manual do 1º fechamento | Igual centavo a centavo |
| Agenda | Reclamações da recepção e dos clientes | Nenhum agendamento perdido, duplicado ou fora do expediente |
| Incidentes | Registro de incidentes (data, impacto, causa, correção) | Nenhum incidente **crítico** (perda de dado, cobrança errada, vazamento) |
| Segurança | Log `security`, auditoria | Nenhum acesso indevido; senhas provisórias trocadas |

**Incidente crítico** (perda de dado, cobrança duplicada ou errada, dado de cliente exposto, sistema fora do
ar por mais de 2 h em horário de atendimento) **reinicia a contagem** dos 30 dias depois de corrigido.
O sistema antigo fica disponível **somente leitura** durante esses 30 dias e só é arquivado depois do aceite.
