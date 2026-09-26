# 13. Estratégia de testes

## 13.1 Situação atual

| Tipo | Existe? | Detalhe |
|---|---|---|
| Unitários | Não | — |
| Integração | Parcial | `tests/assinaturas.php`: script CLI próprio, sem framework, que copia o banco para uma pasta temporária e verifica assinaturas, webhooks, idempotência, comissão de assinante e MRR |
| E2E (navegador) | Não | — |
| Testes manuais | Provável | Não documentados |
| CI | Não | — |

**Áreas sem nenhuma cobertura:** agendamento (disponibilidade, conflitos, antecedência),
preço e descontos, fidelidade, cupons/vouchers, autenticação e permissões, relatórios
financeiros, comissões comuns, e-mails, uploads, chatbot, interface.

O script de assinaturas é valioso: os **cenários** dele devem virar testes do novo sistema.

## 13.2 Pirâmide proposta

```text
                ▲  E2E (Playwright)          ~20 fluxos críticos
               ▲▲▲ Feature/HTTP (Pest)       rotas, permissões, validação, banco real (SQLite em memória)
             ▲▲▲▲▲▲ Unit (Pest)              motor de agenda, preço, fidelidade, comissão, políticas
```

| Camada | Ferramenta | O que cobre | Meta |
|---|---|---|---|
| Unitário | Pest/PHPUnit | Serviços de domínio puros: `Availability`, `Pricing`, fidelidade, comissão, políticas de cancelamento/reagendamento, recorrência de despesas | ≥ 90% de linhas nos módulos Scheduling, Pricing, Loyalty, Finance |
| Feature / integração | Pest + banco SQLite em memória + fakes (e-mail, fila, Stripe, IA) | Controllers, validação, persistência, eventos, jobs, webhooks | Toda rota com teste de sucesso, validação e autorização |
| Matriz de autorização | Teste gerado a partir da lista de rotas × perfis | Cada rota nega acesso a quem não tem permissão (**negar por padrão**) | 100% das rotas |
| Segurança | Testes específicos | XSS nos campos livres, CSRF, IDOR (acessar recurso de outro cliente/profissional), rate limit, upload malicioso, Host header | Casos de S-01 a S-16 como testes de regressão |
| Contrato de integrações | Payloads reais gravados | Webhook do Stripe (assinatura válida/ inválida, duplicado, fora de ordem) | Todos os eventos tratados |
| E2E | Playwright (Chromium; viewport celular e desktop) | Fluxos críticos no navegador | Lista 13.4 |
| Acessibilidade | axe-core no Playwright + Lighthouse CI | Site, agendamento, área do cliente, painel | Zero violações "serious/critical" |
| Visual (opcional) | Screenshots do Playwright | Componentes do design system | Revisão manual das diferenças |
| Migração | Testes do importador | Transformações unitárias + conciliação sobre um banco de amostra anonimizado | Conciliação 100% |
| Performance (leve) | Script de carga simples | Busca de horários e criação de agendamento com 50 agendamentos simultâneos | Sem duplicidade; p95 < 500 ms |

## 13.3 Cenários obrigatórios do motor de agenda (exemplos)

- Serviço de 45 min numa grade de 15 min: ocupa 3 blocos.
- Horário que termina depois do fim do expediente: recusado.
- Intervalo de almoço no meio do serviço: recusado.
- Ausência de dia inteiro e de período parcial.
- Dois clientes tentando o mesmo horário ao mesmo tempo: só um grava.
- Sobreposição parcial (14:00–14:45 × 14:30): recusada.
- Antecedência mínima e máxima; limite de itens.
- Reagendar para o próprio horário (não conflita consigo mesmo).
- Cancelar dentro e fora do prazo; cancelar concluído (proibido).
- Reserva aguardando pagamento expira em 15 min e libera o horário.
- Mudança de fuso configurada respeitada em todos os cálculos.

## 13.4 Fluxos E2E críticos

1. Visitante vê serviços e horários **sem login** e agenda criando conta na confirmação.
2. Cliente logado agenda com cupom; orçamento exibido = valor gravado.
3. Cliente remarca e cancela respeitando a política.
4. Cliente avalia um atendimento concluído.
5. Esqueci a senha → e-mail (capturado) → nova senha → login.
6. Profissional abre o dia, fecha comanda com produto e gorjeta.
7. Recepção cria agendamento manual para cliente sem conta.
8. Admin altera preço de serviço; relatório do mês anterior **não muda**.
9. Admin paga comissão com vale descontado; recibo confere.
10. Perfil Recepção **não** acessa Financeiro nem Configurações (URL direta inclusive).
11. Usuário desativado perde o acesso na requisição seguinte.
12. Lembrete da véspera enviado uma única vez; link de confirmar presença funciona.
13. Assinatura [se aprovada]: checkout (Stripe em modo teste), webhook, benefício aplicado no agendamento.
14. Campanha enviada em lote respeitando opt-out.
15. Exclusão de conta anonimiza e mantém o financeiro.
16. Landing: CTA "Agendar" visível no hero e na barra inferior no celular; sem link de admin.

## 13.5 Regras de processo

- **Definição de pronto** de qualquer tarefa: testes da camada adequada escritos e passando
  no CI, análise estática sem erros, revisão de código.
- **Nenhuma fase avança** com teste falhando, pulado ou desativado.
- Bug encontrado vira primeiro um teste que falha, depois a correção.
- CI roda: lint (Pint), análise estática (PHPStan/Larastan nível alto), unitários + feature,
  E2E nos fluxos críticos, `composer audit`, `npm audit`, build de assets.
- **Homologação** com dados anonimizados do banco real (gerados pelo importador +
  anonimizador) antes de cada entrega de fase que toque dados.
- Roteiro de **teste manual de aceite** por fase, executado pelo dono do produto (checklist
  curto no final de cada fase do roadmap).
