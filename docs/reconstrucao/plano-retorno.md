# Plano de retorno ao sistema antigo (Fase 13)

Como desfazer a virada ([plano-virada.md](plano-virada.md)) se um gatilho do §6 dela acontecer. O plano
**foi executado** em ensaio (§5), não é só teórico. Ele funciona porque o sistema antigo **não é tocado**
na virada: o banco dele fica parado no estado do backup final, e tudo o que acontecer depois existe só no
sistema novo, de onde é exportado.

## 1. Quando decidir

- Qualquer gatilho do [plano-virada.md](plano-virada.md) §6, durante a janela ou nas primeiras **72 h**.
- Quem decide: o dono (ou quem ele delegar), com o técnico.
- **Durante a janela** (antes de liberar), o retorno é só não seguir: reabrir o antigo (passos 5 e 6).
- **Depois de liberar**, há dados novos no sistema novo: seguir todos os passos.

## 2. Passo a passo

| Passo | O quê | Comando / ação | Conferência |
|---|---|---|---|
| 1 | Parar o novo para o público | `php artisan down --retry=60` | Site responde 503 |
| 2 | Guardar o estado do novo | `php artisan app:backup --label=antes-do-retorno` | Cópia aprovada (serve para análise e para uma nova tentativa) |
| 3 | Exportar o que o novo gravou | `php artisan app:rollback-export --since="AAAA-MM-DD HH:MM"` (T0 da virada, hora da barbearia) | CSV em `storage/app/private/retorno/<data>/`: agendamentos, atendimentos, pagamentos, caixa, clientes, assinaturas + `resumo.txt`. O que veio **da importação** fica de fora sozinho (o corte é o fim da última importação) |
| 4 | Tirar o novo do domínio | Reapontar DNS/raiz do site; desligar o cron do novo | Domínio não chega mais no novo |
| 5 | Conferir que o antigo está intacto | `sha256sum` do banco do antigo = valor anotado no passo 2 da virada | Iguais |
| 6 | Reabrir o antigo | Domínio de volta para o antigo; remover a página de manutenção | Login no painel antigo; agenda com os agendamentos de antes |
| 7 | Stripe de volta | Reativar o endpoint antigo do webhook; desativar o novo. **Reenviar pelo painel do Stripe** os eventos ocorridos desde T0 (a lista também está em `assinaturas.csv`) | Assinaturas iguais nos dois lados |
| 8 | Relançar no antigo | A recepção lança no painel antigo, a partir dos CSV: agendamentos futuros (agendamento manual), atendimentos concluídos (agendamento + produtos + fechar comanda com forma de pagamento e gorjeta), clientes novos | Contagens do `resumo.txt` conferidas |
| 9 | Avisar | Equipe e clientes (quem agendou no novo recebe confirmação do antigo ou um contato da recepção) | — |
| 10 | Registrar | Incidente: o que disparou, o que foi relançado, quanto tempo levou | — |

**O que não volta sozinho:** comprovantes e e-mails já enviados pelo novo (continuam válidos para o
cliente); pontos ganhos no novo (relançar como ajuste no antigo, se houver); assinatura criada no novo
(continua no Stripe: o reenvio de eventos do passo 7 faz o antigo conhecê-la).

## 3. Quanto tempo

Máquina: segundos (ensaio: exportação em 3 s, relançamento de 1 agendamento e 1 atendimento em 1 s). O
tempo real é o relançamento manual pela recepção (~2 min por item) e a propagação do DNS. Por isso a virada
acontece fora do horário de pico e o acompanhamento das primeiras 72 h é reforçado: quanto antes o gatilho,
menos o que relançar.

## 4. Depois do retorno

- O sistema novo fica parado com o estado guardado (passo 2). Corrigir a causa, repetir o ensaio e marcar
  nova virada (nova importação a partir de um backup novo do antigo, em banco novo vazio).
- Nada do sistema novo é apagado durante o retorno.

## 5. Ensaio executado (2026-10-07, dados fictícios)

Continuação do ensaio da virada ([plano-virada.md](plano-virada.md) §8), com o novo já em operação na
porta que fazia papel de domínio.

| Passo | Resultado | Tempo |
|---|---|---|
| 1. Manutenção no novo | Site 503 | < 1 s |
| 2. Cópia "antes-do-retorno" | Aprovada | < 1 s |
| 3. Exportação | **Achado e corrigido no ensaio:** a 1ª versão exportava também o que veio da importação (feita depois de T0), o que duplicaria registros no antigo. Corte passou a ser o fim da última importação. Resultado final: 2 agendamentos (1 encaixe, 1 online), 1 atendimento, 1 pagamento (pix 74,90 + gorjeta 5,00), 1 movimento de caixa, 1 cliente alterado | 3 s |
| 4. Novo desligado da porta | HTTP 000 | — |
| 5. Banco do antigo | SHA-256 no desligamento = SHA-256 na volta (`d4726a57…`): **intacto** | — |
| 6. Antigo de volta na porta | Login do admin antigo; painel abre; 4 agendamentos de antes | — |
| 8. Relançamento pelas ações do painel antigo | Agendamento online do dia 10/10 relançado; atendimento relançado como agendamento + produto + fechar comanda (pix, gorjeta 5,00). Antigo com 6 agendamentos, 2 concluídos no dia | 1 s |

Passo 7 (Stripe) não foi ensaiado com o Stripe real (sem conta de teste): no simulador, o mesmo evento
reenviado é reconhecido como duplicado e não aplica nada duas vezes, que é o que torna o reenvio seguro.
