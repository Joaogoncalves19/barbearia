# 15. Decisões pendentes (dono do produto)

> Situação atualizada na Fase 1: ver [decisoes-fase-1.md](decisoes-fase-1.md). D-16 foi
> autorizada e executada; D-02 e D-10 têm decisão técnica provisória; D-01, D-07 e D-08
> seguem com o dono.
>
> **Fase 2:** D-07 (direção visual) decidida: **A**. Logo e nome seguem pendentes.
> D-10 continua pendente: a equipe antiga entra por **usuário** (`users.username`); o e-mail é opcional até a
> decisão. D-11, D-17, D-20 e D-21 foram implementadas **na forma mais conservadora** (arquivar, importar como
> está e marcar para revisão, só o resumo, nunca mesclar), sem fechar a decisão. Ver
> [relatorio-fase-2.md](relatorio-fase-2.md#decisões).
>
> **Fase 3 (decisões do dono):** **D-10** decidida: a equipe entra por **usuário ou e-mail** (o usuário
> continua sendo o identificador; o e-mail é opcional). **D-12** decidida: o cliente entra com **senha e
> link mágico**, identificado pelo e-mail; **CPF obrigatório** para o cliente. **D-21**: continua valendo
> nunca mesclar sozinho; casos ambíguos ficam como pendência. **D-17**: agendamentos antigos nunca concluídos
> ficam como histórico, sem virar atendimento, receita, comissão ou pontos (testado no importador); a
> classificação de cada um fica para a migração real. O sistema ainda não está em produção: não há dados
> reais a migrar. Ver [relatorio-fase-3.md](relatorio-fase-3.md).
>
> **Fase 4:** CPF obrigatório também no cadastro pelo balcão (decisão do dono), garantido no model para todo
> cliente novo; legado sem CPF segue como exceção temporária. **Nova decisão pendente D-24 (combos):**
> manter os combos do sistema antigo (`packages`: preço próprio, duração = soma) com tela própria, ou
> representar "Corte + Barba" como um serviço comum? Recomendação e impacto em
> [relatorio-fase-4.md](relatorio-fase-4.md#4-decisões).
>
> **Fase 5:** **D-24 decidida (opção A):** "Corte + Barba" é um serviço comum, sem entidade nem tela de combos.
> **D-06:** confirmação automática (como hoje), com opção configurável de exigir confirmação da equipe para
> agendamentos do site. **D-13:** implementada com os valores recomendados como padrão configurável: o cliente
> cancela/remarca até 2 h antes, no máximo 2 remarcações; depois disso, só pela barbearia. O dono pode ajustar
> em "Funcionamento e regras". Ver [relatorio-fase-5.md](relatorio-fase-5.md).
>
> **Fase 6 (decisões do dono):** **D-25** um caixa aberto por vez na barbearia. **D-26** encaixe (atendimento
> sem agendamento) permitido, com cliente cadastrado ou só nome e telefone. **D-27** produtos no atendimento:
> venda (cobrada) e consumo (material, não cobrado), ambos baixando o estoque. **D-28** não existe "pagar
> depois": só se conclui pago. **Decididas na aprovação da Fase 6:** **D-29** desconto só por proprietário e
> gerente, com motivo obrigatório e auditado; a recepção **não** dá desconto, sem permissão genérica que
> contorne isso. **D-30** estorno só por proprietário e financeiro (gerente, recepção e profissional **não**);
> estorno é movimentação nova, o pagamento original não é apagado nem editado. **D-31** repasse da gorjeta ao
> profissional na Fase 7, junto de comissão e repasses, com os conceitos **separados** (gorjeta = valor que o
> cliente destina ao profissional; comissão = remuneração por regra de comissão). **D-32** o encaixe ocupa a
> agenda como um agendamento normal (origem `walk_in`, mesma regra de disponibilidade e conflito).
> **D-33** (decidida na aprovação final da Fase 6): serviço incluído durante o atendimento **não** estende
> automaticamente o horário ocupado na agenda; o valor é recalculado normalmente e a duração e o horário
> reservados (agendamento ou encaixe) continuam os mesmos. Se um dia existir "estender o horário", ela deverá
> usar a regra única da agenda, considerar o profissional, verificar o próximo compromisso, só alterar com
> disponibilidade e registrar no histórico/auditoria. Ver
> [relatorio-fase-6.md](relatorio-fase-6.md#4-decisões) e [§15](relatorio-fase-6.md#15-correção-antes-da-fase-7-o-encaixe-ocupa-a-agenda).
>
> **Fase 7 (decisões do dono, no início da fase):** **D-34** vales entram na Fase 7: lançados com motivo,
> abatidos no próximo repasse, estornáveis sem apagar; em dinheiro, saem do caixa aberto. **D-35** estorno de
> pagamento ajusta comissão e gorjeta automaticamente: quem estorna informa a parte da gorjeta (vira gorjeta
> negativa); o resto reduz a comissão na mesma proporção. **D-36** comissão de produto com percentual próprio
> por profissional (o legado "sim" vira o mesmo percentual dos serviços). **D-37** repasse em dinheiro sai do
> caixa aberto; Pix/transferência só registrado.
> **Decididas na aprovação da Fase 7:** **D-38** só o proprietário cria ou altera regra de comissão (operação de
> alta responsabilidade, auditada); o financeiro consulta, paga repasses, faz ajustes e estorna conforme as
> permissões. **D-39** a taxa da maquininha é custo da barbearia: **nunca** é descontada da comissão nem da
> gorjeta (a gorjeta é integralmente do profissional); se um dia for considerada, será regra explícita,
> configurável, com histórico e auditoria. **D-40** repasse sem período fixo obrigatório: a equipe escolhe
> quando fechar. Comprovante: o dono primeiro decidiu "só a tela" e, em seguida, **mudou de ideia**:
> comprovantes **impressos e com envio por e-mail** para atendimento do cliente, repasse ao profissional,
> vale-presente e fechamento de caixa, implementados na Fase 8. Ver
> [relatorio-fase-7.md](relatorio-fase-7.md#4-decisões).
>
> **Fase 8 (decisões do dono, no início da fase):** **D-14** decidida: **implementar** a indicação (código
> por cliente, desconto no primeiro atendimento do indicado, pontos para quem indicou uma vez). **D-41** o
> vale-presente é **forma de pagamento**: a venda entra no caixa e a comissão é sobre o valor cheio do serviço.
> **D-42** um desconto só por agendamento/atendimento, **vale o maior**, incluindo o desconto manual do
> balcão. **D-43** resgate de pontos escolhido ao agendar ou no balcão; os pontos ficam reservados e **só saem
> do saldo na conclusão** do atendimento. **D-40 revista:** comprovantes impressos e por e-mail dos quatro
> documentos, implementados nesta fase. Itens implementados de forma conservadora e marcados **PRECISA DE
> DECISÃO** (P8-01 a P8-07): ver [relatorio-fase-8.md](relatorio-fase-8.md#4-decisões). **Aprovação da Fase 8
> (2026-10-05):** P8-01 a P8-07 ficam **decididas como implementadas e documentadas**.
>
> **Fase 9 (decisões do dono, no início da fase):** **D-03** decidida: assinaturas **mantidas**, com Stripe.
> **D-44** benefício como hoje: serviços incluídos no plano saem de graça, **sem limite**. **D-45** o benefício
> entra no motor único: **um desconto só, vale o maior**. **D-46** comissão de serviço coberto **sobre o preço
> de tabela**, com a regra de assinante do profissional (percentual, fixo por atendimento, sem comissão).
> **D-47** adesão **como hoje, no agendamento**, pelo Stripe Checkout, e também por **link de pagamento** gerado
> no painel; ativa quando o Stripe confirma. Pontos implementados de forma conservadora e marcados **PRECISA DE
> DECISÃO** (P9-01 a P9-10): ver [assinaturas.md §10](assinaturas.md#10-precisa-de-decisão). **Aprovação da
> Fase 9 (2026-10-05):** **P9-10** decidida: eventos do Stripe guardados por **12 meses**; depois, rotina
> auditável remove os dados pessoais e preserva só o necessário (inclusive o ID, para não reprocessar).
>
> **Fase 10 (decisões do dono, no início da fase):** **D-48** avaliação **só publicada depois da aprovação** da
> equipe. **D-49** pedido de avaliação por **e-mail + aviso na conta**, prazo de **30 dias**. **D-50** e-mails de
> assinatura só na **ativação, falha de pagamento e cancelamento**; renovação só como aviso na conta. **D-51**
> lembretes **como hoje**: véspera a partir das 9h e 2 h antes, configuráveis, link de confirmar presença,
> e-mail + aviso. **Aprovação da Fase 10 (2026-10-05):** **D-05** decidida: **Resend**, atrás de uma abstração
> de provedor (trocar de fornecedor sem reescrever fila, modelos, regras, preferências, novas tentativas e
> histórico), credenciais só no ambiente. **P10-01** no máximo **4 campanhas de marketing por cliente em 30
> dias** (transacional e assinatura não contam). **P10-02** importados com consentimento "desconhecido" ficam
> fora das campanhas. **P10-03** registros de e-mail e avisos guardados **12 meses**, depois anonimização
> automática e auditável. **P10-04** o link de pagamento da assinatura **pode ir por e-mail** (fila central,
> idempotente, sem link novo, no histórico, transacional). **Pendências de homologação:** entrega real com o
> provedor, aprovação visual dos e-mails reais, ciclo do Stripe em modo teste e webhook real do Stripe.
>
> **Fase 11 (site público):** direção oficial A ("Ofício contemporâneo"), nada inventado (números,
> depoimentos, endereço, horários, preços). Pontos marcados **PRECISA DE DECISÃO**: ver
> [relatorio-fase-11.md](relatorio-fase-11.md#3-decisões).
>
> **Aprovação da Fase 11 (2026-10-06):** **P11-01** páginas de privacidade e termos só com texto preenchido
> no painel; antes da publicação definitiva os textos precisam estar preenchidos e revisados; nada de texto
> jurídico inventado. **P11-02** fotos e logo **reais** são a direção definitiva (D-07/D-08), cadastrados pelo
> painel quando fornecidos; até lá, os estados sem imagem. **P11-03** profissional fora do site não aparece,
> não é selecionável no agendamento público nem na remarcação pela conta; a equipe opera normalmente; histórico
> intacto (testado na Fase 12). **P11-04** mínimos mantidos (nota média com 3 avaliações publicadas; galeria
> com 3 fotos), sem seção vazia nem conteúdo fictício. **P11-05 / D-09** "barbeiro em destaque" **não**
> implementado; item futuro no roadmap. Pendências de homologação: entrega real pelo Resend, aprovação visual
> dos e-mails, ciclo do Stripe em modo teste, webhook real do Stripe, teste com usuários reais, Lighthouse.
>
> **Fase 12 (área do cliente):** pontos marcados **PRECISA DE DECISÃO**: ver
> [relatorio-fase-12.md](relatorio-fase-12.md#decisões-necessárias).
>
> **Fase 13 (homologação, migração e virada):** **P13-01** tela de Clientes no painel (existia no antigo,
> "Manter"; inclui a anonimização pelo balcão, P12-03), **P13-02** relatórios gerais/despesas (adiados na
> Fase 7), **P13-03** provedor de e-mail em produção (SMTP do servidor × Resend; D-05), **P13-04** hospedagem
> e domínio (D-01), **P13-05** funcionamento deduzido do expediente na importação, **P13-06** cliente ativo
> migrado com e-mail já confirmado, **P13-07** janela e responsável da virada, **P13-08** guarda do sistema
> antigo arquivado. Ver [relatorio-fase-13.md](relatorio-fase-13.md#9-decisões-pendentes).

Decisões que **não** cabem ao desenvolvimento. Cada uma traz a recomendação técnica e a fase
que ela bloqueia. As marcadas 🔴 bloqueiam o início da Fase 1.

| ID | Decisão | Opções | Recomendação | Bloqueia |
|----|---------|--------|--------------|----------|
| 🔴 D-01 | **Hospedagem e stack** | (a) Hospedagem PHP paga com SSH + cron (compartilhada de qualidade ou VPS gerenciada) com Laravel; (b) manter hospedagem gratuita e usar PHP sem framework | **(a)**. A hospedagem gratuita atual não oferece cron nem SSH e seu firewall já forçou gambiarras no código. Custo típico de uma hospedagem adequada é baixo perto do risco atual | Fase 1 |
| 🔴 D-02 | **Banco de dados** | SQLite (WAL) ou MySQL/MariaDB | SQLite, salvo se a hospedagem escolhida oferecer MySQL gerenciado com backup ou houver plano de várias unidades | Fase 1 |
| 🔴 D-07 | **Marca** | Logo, nome de exibição, cores, fontes, tom de voz | Fornecer o logo real (vetor) e referências de que o dono gosta. Paleta e fontes da proposta são ponto de partida | Fase 1 (tokens) / Fase 11 |
| ~~D-03~~ | **Assinaturas mensais** — **decidida (Fase 9): manter, com Stripe** | Manter (Stripe), manter com outro meio (ex.: Pix recorrente), remover | Manter só se houver assinantes ativos ou interesse real. **Precisa de validação:** quantos assinantes existem hoje? | Fases 2 e 9 |
| D-04 | **Chatbot / IA** | Manter o chatbot com IA, manter só por regras, remover; IA no painel sim/não | Adiar para a Fase 14. Se mantido, sem login pelo chat. Medir uso atual antes | Fase 14 |
| D-05 | **Provedor de e-mail** | Gmail SMTP (atual), provedor transacional (ex.: Amazon SES, Brevo, Postmark) | Provedor transacional com domínio próprio (SPF/DKIM/DMARC): melhor entrega e campanhas sem limite do Gmail | **Decidida (aprovação da Fase 10): Resend, atrás de uma abstração.** Pendente só a entrega real na homologação |
| D-06 | **Aprovação de agendamento** | Automática (atual) ou manual pela recepção | Manter automática; aprovação manual vira opção por serviço/profissional, se necessário | Fase 5 |
| D-08 | **Fotografia** | Sessão profissional, fotos próprias da equipe, sem fotos (versão tipográfica) | Sessão profissional curta (ambiente, equipe, trabalhos). É o maior ganho visual possível | Fase 11 |
| ~~D-09~~ | **"Barbeiro em destaque"** por nota no site — **decidida (P11-05): não implementar agora**; item futuro no roadmap (Fase 14) | Manter, remover, destaque editorial escolhido pelo dono | Remover a comparação por nota; se desejar, destaque editorial | — |
| D-10 | **Login da equipe** | Por e-mail (padrão) ou por usuário | E-mail (permite redefinir senha sozinho). Requer e-mail de cada membro da equipe | Fase 3 |
| D-11 | **Funcionalidades abandonadas** | Lista de espera, CRM (tags/status), metas por profissional, retenção, conciliação de pagamentos, ações em lote na agenda | Aprovar **lista de espera** (útil em barbearia) e metas; descartar CRM/retenção/conciliação como módulos (as partes úteis viram tags e anotações do cliente) | Fase 2 |
| D-12 | **Login do cliente** | Senha (atual); link mágico por e-mail; ambos. Identificador: e-mail, telefone, CPF | E-mail + senha, com link mágico opcional; telefone como contato, não como login; **CPF só se houver motivo fiscal** (dado sensível) | Fase 3 |
| D-13 | **Política de cancelamento e remarcação** | Prazo mínimo, limite de remarcações, o que fazer com faltas | Ex.: cancelar/remarcar até 2 h antes pelo app; depois disso, só pelo WhatsApp | Fase 5 |
| ~~D-14~~ | **Indicação** — **decidida (Fase 8): implementar** | Manter (com tela de configuração), remover | Manter se já houver indicações registradas | Fase 8 |
| D-15 | **PWA** (app instalável) | Manter, remover | Manter simples (manifesto + ícones); sem notificações push inicialmente | Fase 11 |
| D-16 | **Correções emergenciais no sistema atual** | Corrigir agora S-01 a S-04 (e rotacionar segredos) no sistema em produção; não mexer até a virada | **Corrigir agora**, em mudança mínima e separada da reconstrução, porque o sistema atual segue no ar por meses. **Exige autorização explícita** (a Fase 0 proíbe alterar o sistema) | Nenhuma (paralela) |
| D-17 | **Agendamentos passados não concluídos** (status `aprovado` com data passada) na migração | Marcar como concluído, como falta ou revisar um a um | Importar como "a revisar" e resolver em lote com o dono | Fase 2 |
| D-18 | **Rastreamento de erros** | Sentry (ou similar), só logs | Serviço de rastreamento no plano gratuito | Fase 1 |
| D-19 | **Suporte ao desenvolvedor** | Manter o "reportar problema" (com e-mail configurável), remover; crédito "Desenvolvido por" | Manter o botão com destino configurável; crédito fora da interface do cliente final | Fase 7 |
| D-20 | **Histórico de campanhas** | Migrar completo, migrar só o resumo, não migrar | Migrar só o resumo (data, assunto, totais) | Fase 2 |
| D-21 | **Clientes duplicados** | Mesclagem assistida antes da virada; manter separados | Mesclagem assistida, caso a caso, com aprovação do dono | Fase 2 |
| D-22 | **Recursos menores** | Barbeiros favoritos; tema escuro do painel; impressão de agenda/voucher; exportações CSV | Manter impressão e CSV; favoritos e tema escuro só se houver uso | Fases 5–12 |
| D-23 | **Pagamento online por atendimento** (novo) | Não oferecer (atual); sinal/antecipado via Pix; pagamento integral | Fora do escopo inicial; avaliar depois da virada | Fase 14 |

## Informações que também precisamos do dono

1. Acesso de leitura a uma **cópia recente do banco de produção** e da pasta `uploads/`
   (para o diagnóstico da estratégia de migração).
2. Onde e como o sistema está hospedado hoje (painel, domínio, DNS, e-mail).
3. Se o cron dos lembretes está configurado em produção.
4. Quantos profissionais, clientes ativos e assinantes existem; volume semanal de agendamentos.
5. Quais funcionalidades a equipe **realmente usa** no dia a dia (validar a matriz de funcionalidades).
6. Conta Stripe (se D-03 = manter): quem administra e quais webhooks estão cadastrados.
