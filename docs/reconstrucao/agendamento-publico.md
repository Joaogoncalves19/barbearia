# Agendamento pelo site (Fase 11)

> O site **não tem agenda própria**. Todo botão "Agendar" leva ao fluxo real da Fase 5
> ([agendamento.md](agendamento.md)), com a mesma `Availability` e o mesmo `BookingService` do painel:
> conflitos, encaixes (que ocupam a agenda), duração, profissional, expediente, pausas, folgas, bloqueios,
> antecedência, cancelamento e remarcação continuam valendo sem nenhuma regra repetida.

## 1. Entradas

| De onde | Para onde |
|---|---|
| "Agendar horário" (cabeçalho, barra do celular, topo, chamada final) | `/agendar` (escolher serviço) |
| Serviço no início ou em `/servicos` | `/agendar/{serviço}` (escolher profissional) |
| "Ver horários" na página do profissional | `/agendar/{serviço}/horarios?profissional={nome}` (direto aos horários dele) |
| Assinatura (com Stripe configurado) | `/agendar`; a adesão é oferecida na confirmação (D-47) |

Login só na confirmação (Fase 5). Depois: confirmação por e-mail, lembretes e confirmação de presença (Fase 10).

## 2. Regra nova (Fase 11): pelo site, só o que é publicado

O canal do **cliente** só agenda **serviço publicado** e **profissional publicado** (além de ativo e
agendável). A regra está na `Availability` (motivos `service_not_public` e `professional_not_public`), então
vale para a lista, para os horários, para "sem preferência" (só profissionais publicados entram) e para
qualquer pedido montado à mão. A **equipe** continua agendando qualquer serviço/profissional ativo pelo painel
(ex.: serviço interno fora do site). Testes: `SitePagesTest::test_pelo_site_so_agenda_o_que_e_publico...`.

Antes da Fase 11 a tela `/agendar` listava todo serviço agendável, inclusive os fora do site; agora lista só
os publicados.

## 3. Remarcação pelo cliente

A remarcação pela conta passa pela mesma regra: se o profissional ou o serviço saiu do site depois do
agendamento, o cliente não remarca pelo site com ele (fala com a barbearia; a equipe remarca). Registrado
como ponto de atenção em [relatorio-fase-11.md](relatorio-fase-11.md#3-decisões).
