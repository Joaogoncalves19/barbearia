# Treinamento — Proprietário

**Você pode tudo** o que gerente, recepção e financeiro fazem ([gerente.md](gerente.md),
[recepcao.md](recepcao.md), [financeiro.md](financeiro.md)). Este roteiro cobre só o que é **exclusivo**
seu e as rotinas de segurança da instalação.

## Pessoas e acessos

- **Usuários** — *Usuários → Novo*: nome, usuário, papel (gerente, recepção, financeiro, profissional) e
  senha provisória (a pessoa troca no primeiro acesso). Para desligar alguém: *Desativar* (nada é
  apagado; o histórico continua com o nome dela). ✔
- **Barbeiro com login** — no cadastro do profissional, bloco *Acesso ao painel*: usuário e senha
  provisória no próprio cadastro (o sistema pede a sua senha de novo antes). ✔
- **Auditoria** — *Auditoria*: quem fez o quê e quando (descontos, estornos, preços, permissões, LGPD).

## Regras do negócio

- **Regras de comissão** — por profissional e por tipo (serviço, produto); a mudança vale dali em diante e
  fica no histórico. ✔
- **Fidelidade e aniversário**, **planos de assinatura**, **lembretes e avisos** e **aparência** (um dos 8
  temas) também são seus.

## LGPD

- **Excluir os dados de um cliente** (pedido dele): o próprio cliente faz pela conta (*Privacidade →
  Encerrar conta*). Pedido feito na barbearia: *Clientes →* ficha do cliente *→ Anonimizar cadastro*,
  confirme a senha e digite ANONIMIZAR. Antes, cancele o horário marcado, conclua a comanda aberta e
  encerre a assinatura vigente (a tela diz o que falta). Não dá para desfazer. ✔

## Segurança da instalação (o que o dono precisa saber)

1. **Cópia de segurança diária** acontece sozinha de madrugada. Uma vez por semana, olhe se a mais
   recente existe e foi aprovada (quem cuida do servidor roda `php artisan app:backup-verify`), e
   guarde uma cópia **fora do servidor** (nuvem ou pendrive). As cópias são cifradas: guarde a senha delas
   (`BACKUP_PASSWORD`) e o `.env` também **fora do servidor** (gerenciador de senhas). Se o servidor se
   perder, sem essa senha a cópia não abre.
2. **Avisos de problema** chegam no e-mail de monitoramento (cron parado, cópia atrasada, e-mails
   falhando). Não ignore: repasse para quem cuida do servidor.
3. **Nunca** compartilhe o seu usuário. Se alguém sair da equipe, desative no mesmo dia.
4. Chaves do Stripe e do e-mail ficam só no servidor (`.env`), nunca em tela, mensagem ou planilha.

## Exercício final ✔

Crie um usuário de recepção de teste, entre com ele numa janela anônima, veja que o menu é menor e que
*Usuários*, *Comissões* e *Campanhas* não aparecem; depois desative esse usuário.
