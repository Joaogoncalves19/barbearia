# Plano de virada: do sistema antigo para o novo (Fase 13)

Troca de **uma barbearia** (instalação independente) do sistema antigo para o novo. Ensaiado de ponta a
ponta em 2026-10-07 com uma cópia de ensaio do sistema antigo e dados fictícios (ver §8). A virada real só
acontece com **autorização explícita do dono**, com a cópia real e com os pré-requisitos do §1 cumpridos.
Se algo der errado: [plano-retorno.md](plano-retorno.md).

## 1. Pré-requisitos (antes de marcar a data)

| # | Item | Situação em 2026-10-07 |
|---|---|---|
| 1 | Dono aprovou a Fase 13 e decidiu P13-01 a P13-06 ([relatorio-fase-13.md](relatorio-fase-13.md)) | Pendente |
| 2 | Hospedagem definida (D-01) com PHP 8.4, HTTPS, cron e SSH/terminal ([instalacao.md](instalacao.md) §1) | **Pendente** |
| 3 | Ensaio da migração com a **cópia real recente** (autorizado pelo dono), pendências revisadas e decididas | **Pendente** (feito só com dados fictícios) |
| 4 | Ciclo do Stripe em **modo teste** com a conta do dono e webhook de teste apontando para a homologação | **Pendente** (feito com simulador) |
| 5 | E-mail real (SMTP do servidor ou Resend) entregue a uma lista-semente; SPF/DKIM/DMARC no DNS | **Pendente** (feito com receptor local) |
| 6 | Testes de aceite do dono e da equipe na homologação + teste com 5 pessoas (roteiro em [homologacao.md](homologacao.md)) | **Pendente** |
| 7 | Treinamento feito ([treinamento/](treinamento/README.md)) | Material pronto; sessões pendentes |
| 8 | Textos legais preenchidos (P11-01), fotos e logo reais (D-07/D-08) | Pendente com o dono |
| 9 | Pacote gerado do commit aprovado, com SHA-256 conferido | Pronto por commit (`empacotar.sh`) |

## 2. Janela e comunicação

- **Quando:** depois do fechamento, fora de dia de pico (ex.: domingo à noite ou segunda de manhã, se a
  barbearia fecha). Duração estimada: **1 h** (ensaio: ~10 min de máquina; o resto é conferência humana).
- **Aviso:** clientes (site/rede social: "agendamento online indisponível das X às Y") e equipe (novo
  endereço do painel, usuário de cada um, senha provisória para quem não tinha acesso).
- **Quem participa:** quem executa (técnico) e o dono (conferência de 15 min e decisão de seguir/abortar).

## 3. Sequência da virada

Horário de referência **T0** = início da janela (anotar, hora da barbearia).

| Passo | O quê | Como | Conferência |
|---|---|---|---|
| 1 | Congelar o antigo | Desligar o agendamento público do antigo (página de manutenção no servidor web ou no painel da hospedagem). O sistema antigo **não é alterado** | Site antigo não aceita agendamento |
| 2 | **Backup final do antigo** | Painel antigo → *Configurações → Dados → Backup do banco* (baixa o `.sqlite`) + copiar a pasta `uploads/` inteira | `sha256sum` da cópia anotado; guardar em 3 lugares (servidor, nuvem, mídia externa) |
| 3 | Instalar o novo | [instalacao.md](instalacao.md) §3 com o pacote aprovado, **sem** `app:create-owner` | `app:diagnose` sem FALHA de configuração |
| 4 | Simular a importação | `php artisan legacy:import /caminho/copia.sqlite --dry-run` | Conciliação toda OK; pendências iguais às revisadas no ensaio real |
| 5 | Importar | `php artisan legacy:import /caminho/copia.sqlite --force` | Conciliação, integridade, "origem inalterada: sim" |
| 6 | Fotos | `php artisan legacy:import-photos /caminho/copia-uploads` | Reprocessadas × ausentes conferidas |
| 7 | Cópia do novo | `php artisan app:backup --label=depois-da-virada` | Aprovada |
| 8 | Conferência do dono (15 min) | Amostra: 5 clientes, 5 agendamentos futuros, 2 profissionais, 1 assinatura, saldo de pontos de 2 clientes, comissões do mês; login com senha antiga (dono, 1 barbeiro, 1 cliente); *Funcionamento* | Tudo igual ao antigo |
| 9 | Stripe | No painel do Stripe: novo endpoint `https://dominio/webhooks/stripe` (mesma versão da API, eventos de assinatura, fatura, checkout e reembolso); copiar o `whsec_` para `STRIPE_WEBHOOK_SECRET`; **rotacionar** a chave secreta (as antigas ficaram em texto puro no banco antigo, S-10) e pôr a nova em `STRIPE_SECRET`; `php artisan optimize` | *Eventos do Stripe* recebe o "ping"/evento de teste. Desativar o endpoint antigo (`/webhook_stripe.php`) **só depois** |
| 10 | E-mail | `MAIL_*` / `RESEND_API_KEY` com credenciais **novas** (rotacionar a senha SMTP antiga) | E-mail de teste chega |
| 11 | Apontar o domínio | DNS ou raiz do site para `barbearia/public` | `https://dominio/up` = 200; site abre com HTTPS |
| 12 | Cron | Linha do cron ativada | Em 2 min, `app:diagnose` mostra o agendador OK |
| 13 | Monitor externo | Monitor de `/up` ligado; `MONITOR_EMAIL` definido | Alerta de teste recebido |
| 14 | Liberar | Avisar equipe e clientes | Primeiro agendamento real pelo site conferido |

**Segredos:** nenhum segredo do antigo é reaproveitado. O importador **não** traz `config_email`,
`config_stripe`, `config_chatbot` (pendência `secret_section_not_imported` lembra de recadastrar) e o `.env`
novo nasce com `APP_KEY` nova. Senhas de pessoas (hash bcrypt) continuam valendo e são refeitas no primeiro
login.

## 4. Configurações do `.env` na virada

Ver [instalacao.md](instalacao.md) §4. Itens que costumam ser esquecidos: `APP_NAME` com o nome da
barbearia, `APP_URL` com `https://`, `SESSION_SECURE_COOKIE=true`, `BARBEARIA_PROTOTYPES=false`,
`BACKUP_PASSWORD`, `MONITOR_EMAIL`, `MAIL_MAILER=smtp` também com Resend.

## 5. Checklist final antes de liberar

- [ ] `php artisan app:diagnose` sem FALHA e sem AVISO (exceto cópia, se ainda não deu 02:40: rodar `app:backup`).
- [ ] Conciliação da importação 100 % OK e relatório de pendências guardado.
- [ ] Login com senha antiga: dono, um barbeiro (cai na área do profissional), um cliente.
- [ ] Agendamento de teste pelo site e cancelamento; e-mails de confirmação e cancelamento chegaram.
- [ ] Atendimento de teste no balcão com caixa aberto; comprovante; estorno do teste pelo financeiro.
- [ ] Stripe: evento de teste recebido e aplicado; assinaturas vigentes iguais às do antigo.
- [ ] `/up` monitorado; cópia do dia aprovada e uma cópia levada para fora do servidor.
- [ ] Permissões de arquivos conferidas (`storage/`, `bootstrap/cache/`, `database/` graváveis; `.env` 640).
- [ ] `robots.txt` liberando o site (só em `APP_ENV=production`).

## 6. Quando abortar (gatilhos de retorno)

Abortar e executar [plano-retorno.md](plano-retorno.md) se, **durante a janela ou nas primeiras 72 h**:

1. a importação falhar ou a conciliação não fechar (nada foi gravado: só não seguir);
2. login com senha antiga não funcionar para dono, equipe ou clientes;
3. agendamento pelo site ou pelo balcão falhar, ou aparecer horário duplicado ou fora do expediente;
4. divergência financeira (caixa, comissão, faturamento do mês) em relação ao antigo;
5. Stripe cobrando em dobro, ou webhooks rejeitados sem causa clara;
6. e-mails não saindo por mais de 2 h;
7. o dono decidir que não está seguro.

Depois de 72 h sem gatilho, o retorno deixa de ser rotina (o antigo continua só leitura por 30 dias).

## 7. Depois da virada

- 72 h de acompanhamento reforçado: `app:diagnose`, *E-mails enviados*, *Eventos do Stripe*, caixa do dia.
- Contar os 30 dias de estabilidade ([operacao.md](operacao.md) §5). O antigo fica somente leitura e é
  arquivado (banco + `uploads/` cifrados) depois do aceite.

## 8. Ensaio executado (2026-10-07, dados fictícios)

Porta `8300` fez o papel do domínio. O antigo foi instalado numa pasta de ensaio pelo `install.php` dele e
recebeu dados fictícios pelas próprias ações dele (catálogo, barbeiro com login, clientes, assinatura
manual, cupom, vale-presente, fidelidade, agendamentos, comanda com produto/gorjeta/pix, despesa, vale,
repasse, avaliação, descadastro).

| Passo | Resultado | Tempo |
|---|---|---|
| Backup final pelo botão do painel antigo + `uploads/` | SHA-256 da cópia = arquivo de origem; `quick_check` ok | 4 s |
| Antigo desligado da porta | HTTP 000 | — |
| Instalação a partir do pacote `f5e84c3` + simulação + importação + reexecução + fotos + caches | Conciliação 6/6, integridade OK, origem inalterada, nada duplicado na reexecução | 79 s |
| Cópia "depois-da-virada" | Aprovada | < 1 s |
| Novo na porta + operação real | Dono criou a equipe; barbeiro e cliente entraram com a senha antiga; recepção abriu caixa, fez encaixe com produto e pix + gorjeta, enviou comprovante; cliente agendou pelo site | 2 min 42 s |

O retorno ensaiado em seguida está em [plano-retorno.md](plano-retorno.md) §5.
