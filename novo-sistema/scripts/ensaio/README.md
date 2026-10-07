# Ferramentas de ensaio (Fase 13)

Só para **ensaio e homologação**, nunca em produção. Não entram no pacote de instalação (`empacotar.sh`).

| Arquivo | Para quê |
|---|---|
| `popular-antigo.sh` | Grava dados **fictícios** numa cópia de ensaio do sistema antigo pelas ações do próprio painel antigo (catálogo, equipe, clientes, agenda, comanda, financeiro, promoções) |
| `popular-antigo-cliente.sh` | Parte do cliente no antigo: login, avaliação, descadastro |
| `stripe-simulado.php` | Simulador da API do Stripe (roteador do `php -S`) que entrega webhooks assinados por HTTP depois de responder. Controle do ciclo em `/sim/*` |
| `smtp-receptor.py` | Receptor SMTP local que grava cada e-mail como `.eml` (só 127.0.0.1) |
| `instalar-homolog.sh` | Instala a homologação a partir do pacote, importa a cópia e as fotos (variáveis `DEST`, `URL_APP`, `COPIA`, `UPLOADS`) |
| `conferir-amostra.php` | Confere no banco novo, registro a registro, a amostra gravada pelo antigo |
| `stripe-ciclo.sh` | Ciclo de webhooks pelo simulador com o estado da assinatura a cada passo |
| `virada-1-copia.sh`, `virada-3-relancar.py` | Ensaio da virada (cópia final pelo painel antigo) e do retorno (relançar no antigo a partir dos CSV) |
| `roteiro.sh` | Roda `tests/homologacao` com as senhas de ensaio lidas de um arquivo local |

Os scripts esperam a pasta de trabalho do ensaio (a mesma do script) com `acessos-ensaio.txt` (senhas de
teste, fora do repositório), `pacotes/`, `antigo/` (cópia de ensaio do sistema antigo).

Senhas nunca vão no código nem na linha de comando registrada: os scripts recebem por argumento ou
variável de ambiente e o ensaio guarda as de teste num arquivo local fora do repositório. Roteiros de
navegador da homologação: `tests/homologacao` com `playwright.homologacao.config.js`. Passo a passo e
resultados: [docs/reconstrucao/homologacao.md](../../../docs/reconstrucao/homologacao.md).
