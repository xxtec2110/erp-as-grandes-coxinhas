# Go-live controlado do GrandChef

Este procedimento é operacional. Ele não substitui a revisão humana exibida em **Configurações → Integrações → GrandChef → Preparar operação**.

## Gates obrigatórios

1. Products oficiais criados pelo catálogo oficial, sem criar categoria ou preço fictício.
2. Mappings de produto confirmados manualmente; uma sugestão nunca vale como mapping.
3. Todas as formas de pagamento mapeadas. Pix e dinheiro não recebem taxa inventada.
4. Débito/crédito com adquirente, bandeira e taxa vigente para a data da venda.
5. Estoque inicial baseado em contagem física e lançado pelo fluxo de preview + confirmação.
6. Todos os pedidos em `READY` no dry-run oficial.
7. Backup PostgreSQL validado e restore testado em um banco separado.
8. Primeira importação limitada a um único pedido.

`PDV_IMPORT_ENABLED` deve permanecer `false` até todos os itens acima serem revisados. Sincronização e reprocessamento do GrandChef alimentam apenas o staging. O serviço legado de importação direta rejeita conexões GrandChef.

## Backup PostgreSQL antes do primeiro pedido

Use variáveis locais ou o gerenciador de segredos do ambiente. Não coloque senha na linha de comando, em arquivos versionados ou neste documento.

```powershell
$env:PGPASSWORD = '<senha-local-ou-do-ambiente>'
pg_dump --host=<host> --port=<porta> --username=<usuario> --dbname=<banco> --format=custom --file=<caminho-seguro>\erp-pre-grandchef.dump
pg_restore --list <caminho-seguro>\erp-pre-grandchef.dump
Remove-Item Env:\PGPASSWORD
```

O arquivo só é considerado verificado se `pg_dump` terminar com código zero e `pg_restore --list` listar seu conteúdo sem erro.

## Restore de teste

Nunca restaure por cima do banco local real ou de produção. Crie um banco descartável e isolado, sem conexões da aplicação, e restaure nele:

```powershell
createdb --host=<host> --port=<porta> --username=<usuario> <banco_teste_isolado>
$env:PGPASSWORD = '<senha-local-ou-do-ambiente>'
pg_restore --host=<host> --port=<porta> --username=<usuario> --dbname=<banco_teste_isolado> --clean --if-exists <caminho-seguro>\erp-pre-grandchef.dump
Remove-Item Env:\PGPASSWORD
```

Valide migrations, contagens principais e uma consulta somente leitura no banco isolado. Depois descarte esse banco conforme a política de backup da empresa. Este repositório não executa restore automaticamente.

## Primeira importação

1. Confirme que `PDV_FIRST_IMPORT_SINGLE_ORDER=true`.
2. Escolha um pedido simples já marcado `READY`.
3. Abra a prévia final e confira cabeçalho, itens, split payments, taxas, líquido e saldo projetado.
4. Marque os dois checkboxes e digite `IMPORTAR`.
5. Confirme uma única vez.
6. Confira `product_sale_orders`, `product_sales`, `product_sale_payments`, alocações, `stock_movements`, relatórios e eventos de integração.
7. Em repetição acidental, o mesmo pedido deve retornar `already_imported`, sem novos efeitos.

## Cancelamento e reversão

Uma reversão preserva os registros originais, cria contrapartidas financeiras e de estoque e exige status externo cancelado reconhecido. Registre sempre o motivo. O evento de auditoria deve identificar ator, conexão, unidade, pedido externo, transação oficial e IDs gerados.

## Rollback operacional

- Não use `migrate:fresh`, `db:wipe` ou edição direta de saldo.
- Desligue `PDV_IMPORT_ENABLED` para impedir novas importações.
- Preserve staging e auditoria.
- Para um pedido já importado e posteriormente cancelado no PDV, use exclusivamente o serviço oficial de reversão.
- Restore completo só deve ser uma decisão de incidente, com janela, backup validado e banco de destino explicitamente aprovado.
