# Relação profissional × serviço (Fase 4)

## 1. O que é

Tabela `professional_service` (PK composta `professional_id` + `service_id`): **quais serviços cada
profissional executa**. Não se assume que todo profissional faz tudo.

```text
João                      Carlos
 ├── Corte                 ├── Corte
 ├── Barba                 └── Barba
 └── Corte + Barba
```

"Corte + Barba" pode ser um **serviço** comum (com preço e duração próprios), como no exemplo acima. Combos
do sistema antigo (`packages`, preço próprio e duração = soma dos serviços) usam a tabela irmã
`professional_package`, sem tela nesta fase.

## 2. A pergunta que a agenda vai fazer

"Quem pode receber um agendamento **novo** deste serviço?" tem **uma** resposta, em
`App\Modules\Team\Services\ProfessionalDirectory`:

| Método | Resposta |
|---|---|
| `bookableFor(Service)` | Profissionais **ativos e agendáveis**, **vinculados** ao serviço, e só se o serviço for agendável (ativo, com categoria ativa), na ordem da equipe |
| `bookableServicesOf(Professional)` | Serviços agendáveis que o profissional executa (vazio se ele não for agendável) |
| `publicTeam()` | Equipe do site (ativos e públicos, na ordem) |

A Fase 5 deve usar esses métodos. Nenhuma tela, controller ou JavaScript repete a regra.

## 3. Regras de gravação (`ProfessionalAdmin::syncServices`)

- Só serviços **ativos** podem ser vinculados. Serviço inativo, excluído ou id inexistente: **nada é
  gravado** e aparece um aviso.
- Ids repetidos no pedido são recusados na validação.
- Vínculos com serviços que **ficaram inativos** depois são **preservados**: a tela não os mostra como
  opção, e salvar a lista não os apaga. Se o serviço for reativado, o vínculo volta a valer sozinho. A tela
  lista esses vínculos guardados.
- Tudo numa transação, com o profissional bloqueado. Dois administradores salvando ao mesmo tempo, ou um
  serviço desativado no meio do caminho, nunca deixam o vínculo pela metade.
- Desmarcar tudo remove todos os vínculos (menos os guardados de serviços inativos).
- Excluir um serviço (só possível se nunca usado) remove os vínculos dele.
- Desativar o profissional **não** apaga os vínculos: ele só sai de `bookableFor`.
- Cada alteração vai para a auditoria (`professional.services_changed`, com os ids incluídos e retirados,
  quem e quando).

## 4. Permissão

Definir os serviços de um profissional exige `professionals.services`. O próprio profissional não altera os
seus. Ver [papeis-permissoes.md](papeis-permissoes.md).

## 5. Testes

`ProfessionalAdminTest::test_define_os_servicos_que_o_profissional_executa_e_audita`,
`test_nao_vincula_servico_inativo_inexistente_ou_duplicado`,
`test_vinculo_com_servico_que_foi_desativado_fica_guardado`,
`test_diretorio_so_oferece_quem_pode_receber_agendamento`,
`CatalogAuthorizationTest::test_ids_manipulados_nao_dao_acesso_indevido`, E2E "profissional: cadastro com
foto e vínculo com serviços".
