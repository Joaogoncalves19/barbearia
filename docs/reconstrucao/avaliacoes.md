# Avaliações (Fase 10, D-48 e D-49)

> **D-48 (dono): "só após aprovação"** — a avaliação só aparece depois que a equipe aprova.
> **D-49 (dono): "e-mail + aviso, prazo 30 dias"** — pedido de avaliação por e-mail e na conta; dá para
> avaliar até 30 dias depois do atendimento.

## 1. Base válida

Só avalia quem tem:

1. atendimento **concluído**;
2. do **próprio** cliente (cadastrado; o atendimento é dele);
3. com profissional;
4. concluído há no máximo **30 dias**;
5. ainda **sem avaliação** — uma por atendimento, **único no banco** (`reviews.attendance_id`).

A rota passa pela Policy do atendimento (atendimento de outra pessoa ou não concluído = 404) e o serviço
(`Reviews::submit`) confere tudo de novo, inclusive o dono do atendimento. Duplo envio ou dois processos: o
índice único barra o segundo ("Este atendimento já foi avaliado").

## 2. Cliente

**Minha conta → Avaliações**: atendimentos que podem ser avaliados e as próprias avaliações com a situação
(Em revisão, Publicada, Não publicada) e a resposta da barbearia (quando publicada). Nota de 1 a 5 e
comentário opcional (até 1.000 caracteres). Nota e comentário não mudam depois de enviados.

## 3. Moderação (painel → Comunicação → Avaliações)

| Ação | Habilidade | Regra | Auditoria |
|---|---|---|---|
| Ver todas (aguardando primeiro) | `reviews.view` | — | — |
| Ver as publicadas dos próprios atendimentos | `reviews.view_own` (profissional) | só aprovadas, só as dele; nome do cliente só o primeiro | — |
| Aprovar | `reviews.moderate` | publica | `review.approved` (quem, quando, situação anterior) |
| Recusar | `reviews.moderate` | **motivo obrigatório**; não aparece para ninguém | `review.rejected` (motivo) |
| Destacar / tirar destaque | `reviews.moderate` | só publicada | `review.featured` / `review.unfeatured` |
| Responder | `reviews.reply` | só publicada; uma resposta por avaliação (responder de novo substitui) | `review.replied` (texto anterior e novo) |

Quem moderou e quando ficam na própria avaliação (`moderated_by_user_id`, `moderated_at`,
`moderation_reason`). Avaliação nunca é apagada.

## 4. Comentário é texto (regressão S-02)

Comentário e resposta são guardados **como vieram** (sem "limpar" HTML), só sem caracteres de controle e com
tamanho limitado, e **escapados em toda tela** (`{{ }}` do Blade; quebras de linha por CSS `pre-line`). Um
`<script>` aparece como texto no painel e na conta (`test_comentario_e_texto_nunca_html_em_toda_tela`). O
site público (Fase 11) deve seguir a mesma regra.

## 5. Pedido de avaliação

Rotina a cada 10 minutos (`app:communication review-requests`): atendimentos concluídos há pelo menos 3 h
(configurável, 0 a 72) e no máximo 30 dias, de cliente cadastrado, ainda sem avaliação → e-mail + aviso na
conta, **uma vez por atendimento** (chave `review_request:{atendimento}`). Se o cliente avaliar antes de o
e-mail sair, o e-mail não sai. Pode ser desligado na configuração.

## 6. Importadas

As avaliações do sistema antigo (publicadas sem moderação) entram **aprovadas** e marcadas `is_legacy`; uma
resposta por avaliação (a segunda vira pendência do importador). Destaques importados mantidos.

## 7. Integridade

R49: nota de 1 a 5; situação conhecida; moderada tem quem e quando (exceto importadas); recusada tem motivo;
destaque só publicada; atendimento do próprio cliente e concluído.
