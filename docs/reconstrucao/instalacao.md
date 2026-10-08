# Instalação de uma barbearia (Fase 13)

O sistema é vendido como **instalação independente**: cada barbearia recebe o pacote e roda a própria cópia,
no próprio servidor, com o próprio banco. Não há multiempresa nem dados compartilhados entre barbearias.
Este guia é o passo a passo usado no ensaio da Fase 13 (homologação instalada a partir do pacote, três
vezes, e a virada ensaiada uma vez). A troca do sistema antigo pelo novo está em
[plano-virada.md](plano-virada.md); a operação do dia a dia, em [operacao.md](operacao.md).

## 1. Requisitos do servidor

| Item | Mínimo | Observação |
|---|---|---|
| PHP | **8.4+** com `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd` (com WebP), `curl`, `intl` | `zip` e `sqlite3` são usados pela cópia de segurança; `gd` reprocessa as fotos |
| Servidor web | Apache ou Nginx com HTTPS | A raiz pública é **`barbearia/public`** (nunca a pasta do projeto) |
| Cron | 1 linha, a cada minuto | Sem cron não há lembrete, fila de e-mail, cópia diária nem monitoramento |
| SSH ou terminal | Recomendado | Para `php artisan …`. Sem SSH, o provedor precisa oferecer terminal ou execução de comando |
| Banco | SQLite (padrão, D-02) | Um arquivo; a cópia diária cobre. MySQL/MariaDB é possível, mas a cópia passa a ser do provedor |
| E-mail | SMTP do servidor/provedor, ou Resend | Ver §5 |
| Disco | ~300 MB + fotos + cópias | 14 cópias diárias ficam em `storage/app/backups` (ou `BACKUP_PATH`) |

Node e Composer **não** são necessários no servidor: o pacote já vem com as dependências PHP de produção e
com CSS/JS compilados.

## 2. Pacote

Gerado a partir de um commit aprovado:

```bash
bash novo-sistema/scripts/empacotar.sh <commit> pacotes/
```

Sai `barbearia-<data>-<commit>.zip` + `.sha256`. Confira o hash antes de instalar
(`sha256sum -c barbearia-….zip.sha256`). O pacote **não** contém `.env`, banco, testes, ferramentas de ensaio
nem `node_modules`.

## 3. Primeira instalação (passo a passo)

```bash
unzip barbearia-<versao>.zip            # cria barbearia/
cd barbearia
cp .env.example .env                    # preencher (§4) — nunca versionar
php artisan key:generate --force
touch database/database.sqlite          # ou DB_DATABASE com caminho absoluto fora da pasta pública
php artisan migrate --force
php artisan storage:link                # /storage → storage/app/public (fotos)
# Barbearia NOVA (sem sistema antigo):
php artisan app:create-owner            # primeiro proprietário (interativo; senha provisória)
# Barbearia que VEM do sistema antigo: ver plano-virada.md (importação no lugar do create-owner)
php artisan optimize                    # caches de configuração, rotas, eventos e telas
php artisan app:diagnose                # tudo OK antes de abrir
```

**Permissões de arquivos (Linux):** o usuário do servidor web precisa **escrever** só em `storage/` e
`bootstrap/cache/` (e no arquivo do banco e na pasta dele, por causa do `-wal`). O resto fica somente
leitura. Exemplo: `chown -R usuario:www-data storage bootstrap/cache database && chmod -R ug+rwX storage
bootstrap/cache database && chmod 640 .env`. Nunca `777`.

**Cron (uma linha):**

```
* * * * * cd /caminho/barbearia && php artisan schedule:run >> /dev/null 2>&1
```

O agendador cuida de: fila de e-mails (`QUEUE_WORK_VIA_SCHEDULER=true`, padrão, para hospedagem sem
supervisor de processos), lembretes, pedidos de avaliação, campanhas, expiração de assinaturas, retenção,
**cópia diária às 02:40** e **diagnóstico de hora em hora** (monitoramento). Em VPS com supervisor, use um
worker `php artisan queue:work --queue=emails,default` e `QUEUE_WORK_VIA_SCHEDULER=false`.

## 4. `.env` de produção

Partir do `.env.example` e mudar (o resto pode ficar no padrão):

| Variável | Produção | Por quê |
|---|---|---|
| `APP_NAME` | **Nome da barbearia** | Aparece no assunto dos e-mails de conta (achado da homologação: ficava "Barbearia") |
| `APP_ENV` | `production` | Libera indexação do site, exige `--force` na importação |
| `APP_DEBUG` | `false` | Nunca mostrar erro detalhado |
| `APP_URL` | `https://dominio-da-barbearia` | Todos os links de e-mail saem daqui |
| `APP_KEY` | gerada por `key:generate` | **Guardar fora do servidor** (gerenciador de senhas) |
| `BARBEARIA_PROTOTYPES` | `false` | Desliga `/prototipos` e `/design-system` |
| `SESSION_SECURE_COOKIE` | `true` | Cookie só em HTTPS |
| `LOG_LEVEL` | `info` | |
| `DB_DATABASE` | caminho absoluto | De preferência fora de `public/` (já está, em `database/`) |
| `EMAIL_PROVIDER` / `MAIL_*` | ver §5 | |
| `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | chaves **novas** (ver plano-virada.md) | Vazias = sem assinatura online e webhook recusado |
| `BACKUP_PASSWORD` | senha forte | Cifra as cópias (AES-256). Guardar também fora do servidor |
| `BACKUP_PATH` | pasta fora de `public/` que o provedor também copie | Padrão `storage/app/backups` |
| `MONITOR_EMAIL` | e-mail de quem cuida do servidor | Recebe o aviso do diagnóstico |

`app:diagnose` reprova (FALHA) produção com `APP_DEBUG=true`, sem HTTPS, sem cookie seguro, com protótipos
ligados ou com e-mail em `log`.

## 5. E-mail

Dois caminhos, sem mudar código (D-05, emails.md §7):

- **SMTP do servidor** (o que o sistema antigo fazia com o PHPMailer): `EMAIL_PROVIDER=mailer`,
  `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`
  (`smtps` na porta 465; vazio na 587 com STARTTLS), `MAIL_FROM_ADDRESS` do domínio da barbearia.
  **Ensaiado na homologação** (receptor SMTP local): todos os modelos saíram por SMTP.
- **Resend**: `EMAIL_PROVIDER=resend` + `RESEND_API_KEY` para os e-mails do negócio. Os e-mails de conta
  (senha, link mágico, confirmação) usam o mailer do Laravel: **`MAIL_MAILER` também precisa ser `smtp`**
  (o SMTP do próprio Resend serve). `MAIL_MAILER=resend` não funciona sem um pacote extra; o diagnóstico
  avisa.

Em qualquer caso: SPF, DKIM e DMARC do domínio configurados no DNS antes da virada.

## 5.1 Stripe e e-mail reais: o que o instalador configura

Na Fase 13, a lógica foi provada com um simulador do Stripe e um receptor SMTP locais. As contas reais são
do **comprador** e só existem na instalação dele. Nada disso vem no pacote, e nenhuma credencial é
inventada nem guardada no código.

**Stripe**, na conta do comprador, primeiro em **modo teste**:
1. Chave secreta de teste (`sk_test_...`) em `STRIPE_SECRET`.
2. Endpoint de webhook `https://dominio/webhooks/stripe`, com os eventos de [webhooks.md](webhooks.md)
   §1.
3. Segredo do endpoint (`whsec_...`) em `STRIPE_WEBHOOK_SECRET`. Rodar `php artisan config:cache` de novo.
4. Ciclo completo com cartões de teste do Stripe:
   - link ou adesão no agendamento e checkout pago, até a assinatura ficar ativa;
   - renovação (relógio de teste do Stripe);
   - falha de cobrança e recuperação;
   - reenvio do evento pelo painel do Stripe: tem de dar `duplicate`;
   - cancelamento no fim do período, reativação, reembolso e cancelamento imediato.

   Conferir *Assinaturas → Eventos do Stripe* sem erro pendente.
5. Depois do aceite: as chaves **reais** e um endpoint real novo, com o segredo novo.

**E-mail** (§5): escolher Resend ou SMTP do servidor (P13-03, por ambiente) e:
1. Configurar SPF, DKIM e DMARC do domínio no DNS.
2. Enviar para uma lista-semente (Gmail, Outlook, e-mail do próprio domínio) cada modelo, a partir de
   ações de teste:
   - confirmação, remarcação, cancelamento e lembrete;
   - comprovante;
   - e-mails da assinatura;
   - confirmação e troca de e-mail, nova senha e link mágico;
   - campanha de teste.
3. Conferir que nada caiu em spam e que o descadastro de 1 clique funciona.
4. O dono da barbearia aprova o visual no cliente de e-mail dele.

## 6. Conferência depois de instalar

- [ ] `php artisan app:diagnose` sem FALHA (o agendador e a cópia só ficam OK depois do primeiro minuto/dia).
- [ ] `https://dominio/up` responde 200 (é o endereço do monitor externo).
- [ ] `php artisan app:backup` e `php artisan app:backup-verify` aprovados.
- [ ] Entrar no painel com o proprietário; trocar a senha provisória.
- [ ] *Funcionamento*, *Profissionais/Expediente* e *Serviços* conferidos (o site só oferece horário dentro
      do funcionamento).
- [ ] E-mail de teste: *Campanhas → Enviar teste para mim* ou um agendamento de teste.
