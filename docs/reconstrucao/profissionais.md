# Profissionais (Fase 4)

## 1. Três conceitos separados

```text
User (conta de acesso: login, papel, ativo)       Customer (cliente)
        │ 0..1                                          (sem relação)
        │
        ▼ 0..1
Professional (quem atende: nome de exibição, serviços, agenda, site)
```

- **Professional ≠ User ≠ Customer.** O profissional é a definição única de "quem atende". A conta de acesso
  é **opcional**: só quem precisa entrar no painel tem um `User` vinculado (`professionals.user_id`, único).
- Cadastrar um profissional **nunca cria login**. A conta é criada antes em Usuários (proprietário) e depois
  vinculada na ficha. Desvincular não apaga a conta.
- Ao criar em Usuários uma conta com papel "Profissional", a ficha é criada automaticamente (Fase 3), porém
  **fora do site e sem receber agendamentos** até a gestão completar serviços e apresentação.
- Desativar o profissional **não** desativa a conta de acesso (são conceitos separados). A tela avisa quando
  a conta continua ativa, com o caminho para desativá-la em Usuários.

## 2. Campos

| Campo | Regra |
|---|---|
| `display_name` | Obrigatório, 2–80 caracteres, único sem diferenciar maiúsculas. É o nome na agenda, no site e para o cliente |
| `user_id` | Opcional; conta existente, ativa e ainda sem profissional |
| `slug` | Estável (URL do site), gerado na criação |
| `headline` (especialidade) / `bio` (apresentação) | Opcionais, públicos |
| `photo_path` | Opcional, disco de mídia ([servicos.md §5](servicos.md#5-imagens)) |
| `is_active` | Faz parte da equipe |
| `is_bookable` | Recebe agendamentos novos (ex.: desmarcado para quem está em treinamento) |
| `is_public` / `is_featured` | Aparece / tem destaque no site |
| `sort_order` | Ordem de exibição (subir/descer) |
| `lock_version` | Concorrência otimista |
| Comissão | **Fase 7:** saiu do cadastro do profissional e virou regra versionada (`commission_rules`, tela "Regras de comissão", só o proprietário; ver [comissoes.md](comissoes.md)). Enviar campos de comissão no formulário do profissional não tem efeito |

## 3. Estados: ativo × histórico

| Situação | Novos agendamentos | Site | Histórico |
|---|---|---|---|
| Ativo e agendável | sim (só nos serviços vinculados; pelo site, só se `is_public`) | se `is_public` | — |
| Ativo, **não** agendável | não | **não** (Fase 11, decisão do dono: o site mostra só quem recebe agendamento) | — |
| **Inativo** (desligado) | não | não | intacto |

**Profissional histórico** é quem já teve atendimento, comissão ou vale: nunca é apagado (FK `restrict`),
só desativado. Os agendamentos antigos continuam apontando para o registro e guardam o **nome fotografado**
(`appointments.professional_name`): renomear ou desativar o profissional não reescreve o passado. **Não
existe botão de excluir profissional.** Provas: `ProfessionalAdminTest::test_desativar_preserva_historico_e_nao_mexe_na_conta`
e `test_renomear_nao_reescreve_o_nome_fotografado_nos_agendamentos`.

Regras únicas (escopos do model, usados pelo `ProfessionalDirectory`):

- `Professional::bookable()`: ativo **e** agendável;
- `Professional::shownPublicly()`: ativo **e** público;
- `Professional::ordered()`: ordem de exibição.

## 4. A própria ficha

O profissional com conta vinculada vê a **própria** ficha em "Minha ficha" (serviços, situação,
apresentação). Ficha de outro profissional responde **404**. Ele **não** edita a própria ficha nem os
próprios serviços: isso é da gestão (`professionals.*`).

## 5. Telas

`/painel/profissionais` (lista com filtros, ordem, ativar/desativar com confirmação), `/novo` (depois de
cadastrar, vai direto para os serviços), `/{id}` (ficha), `/{id}/editar` (dados, conta vinculada,
apresentação, foto), `/{id}/servicos` ([relacao-profissional-servico.md](relacao-profissional-servico.md)).

## 6. Auditoria

Criação, edição (inclusive vínculo/desvínculo de conta: `user_id` antes/depois), ativação/desativação e
alteração de serviços executados (`professional.services_changed`, com incluídos e retirados) vão para
`audit_logs`.

## 7. Fora desta fase

Expediente semanal, pausas, folgas e bloqueios (`working_hours`, `schedule_breaks`, `time_off`,
`blocked_slots`) existem no modelo desde a Fase 2 e são alimentados pelo importador. A tela e as regras
entram com a agenda (Fase 5), porque são "disponibilidade de horários", que o briefing da Fase 4 excluiu.
