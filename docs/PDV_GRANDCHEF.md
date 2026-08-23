# Integração PDV / GrandChef

## Estado atual

A fundação GrandChef é multiunidade e exclusivamente read-only até a importação oficial ser autorizada. Cada loja possui sua própria `PdvConnection`, com endpoint em `configuration` e credenciais Bearer e Device em `encrypted_credentials` (cast criptografado e atributo oculto).

> **Limite permanente de responsabilidade:** GrandChef é fonte externa exclusivamente das vendas realizadas. Produtos, estoque, estoque inicial, entradas, compras, ingredientes, fichas técnicas, custos, produção e demais dados operacionais são controlados exclusivamente pelo ERP.

O endpoint, a autenticação Bearer e o contrato GraphQL foram validados diretamente no schema real da Unidade Ibirá por introspecção mínima read-only. `GrandChefQueryContract` está ligado a `GrandChefValidatedQueryContract`, que contém somente as queries e os campos efetivamente confirmados.

## Escopo por unidade

- `pdv_connections.location_id` é a unidade oficial da conexão.
- Existe no máximo uma conexão do mesmo provider por unidade.
- Conexões legadas sem unidade são preservadas, ficam visíveis apenas para Admin Master e não podem ser ativadas, testadas ou sincronizadas sem vínculo explícito.
- Endpoint, credencial, mappings, inbox, eventos, checkpoints e IDs externos sempre pertencem à `PdvConnection`.
- Não existe fallback global de endpoint ou token e não se usa `PdvConnection::first()` para resolver integração.
- O backend exige `pdv.manage` e acesso à unidade; Admin Master mantém acesso global.
- Uma unidade de produção não recebe GrandChef automaticamente.

## Administração web

Área canônica: `Configurações → Integrações → GrandChef`.

A listagem mostra somente unidades acessíveis, status, ativação, credencial configurada, última tentativa, última conexão bem-sucedida, sync, checkpoint, staged, bloqueados, prontos, importados, revertidos, marco operacional, flags e erro sanitizado. A edição nunca devolve Bearer ou Device ao HTML:

- Bearer vazio preserva o Bearer atual;
- Device vazio preserva o Device atual;
- cada credencial pode ser substituída de forma independente;
- ativação exige loja ativa, endpoint HTTPS, Bearer e Device;
- conexão vinculada não pode ser movida para outra unidade.

Mappings de unidade são confinados à unidade da conexão. Produtos externos só contam como coxinha após mapping humano confirmado com um `Product` da categoria oficial `Coxinhas`. Não há confirmação automática por fuzzy matching.

## Transporte GraphQL

`GrandChefGraphqlClient` recebe obrigatoriamente uma `PdvConnection` e é responsável por:

- endpoint e o único header comprovado `Authorization: Bearer <ACCESS>, Device <DEVICE>`;
- HTTPS;
- timeout;
- retry limitado a falha de conexão e erro 5xx;
- POST GraphQL;
- distinção de HTTP 401/403, 5xx, erro GraphQL, resposta inválida e resposta vazia;
- mensagens seguras sem corpo bruto ou credencial.

`GrandChefQueryContract` isola a parte que depende do schema real: query de conexão, listagem, detalhe, paginação e normalização. Testes de transporte continuam usando `Http::fake` e não acessam a internet.

Contrato confirmado:

- `pedidos(filter: PedidoFilter, order: PedidoOrder, limit: Int, page: Int): PedidoPagination`;
- `PedidoFilter.data_conclusao: DateFilter`, usando `from` e `to` (`DateTime`);
- `PedidoFilter.estado: PedidoEstadoFilter`, usando `eq: concluido`;
- paginação por `current_page`, `last_page` e `has_more_pages`;
- `pedido(id: ID, codigo: String, mesa: Int): PedidoSummary`;
- `PedidoSummary.itens: [Item]` e `PedidoSummary.pagamentos: [Pagamento]`;
- `Item.produto: Produto` e `Pagamento.forma: Forma`.

A listagem retorna cabeçalhos `Pedido`, sem itens e pagamentos. Para que o relatório seja completo, o provider consulta read-only `pedido(id: ...)` para cada pedido da página. Se algum detalhe não for retornado, a consulta falha de forma explícita em vez de apresentar totais parciais como fechamento.

## Relatório read-only

O relatório por período usa `America/Sao_Paulo`, percorre cursores opacos com limite de páginas e bloqueio de cursor repetido. Se o total informado não corresponder à quantidade integral obtida, a interface marca o resultado como parcial.

São preservados separadamente:

- subtotal/bruto;
- descontos;
- total da venda;
- total pago;
- troco;
- itens e quantidades;
- todos os pagamentos, inclusive split payment;
- status, IDs externos e timestamps.

O relatório não cria `Product`, `ProductCategory`, `ProductPrice`, `Ingredient`, receita, compra, `ProductSale`, `StockMovement`, `IngredientStockMovement`, financeiro, recebível ou qualquer lançamento operacional. A importação oficial segue obrigatoriamente pelo fluxo:

`GrandChef → PdvConnection → staging → readiness/ImportPlan → PdvOrderImportService → ProductSaleOrder → ProductSaleService → StockMovementService`.

O preço externo é evidência/snapshot daquela venda e nunca atualiza o preço oficial de um `Product`.

## Idempotência e sanitização

Os identificadores permanecem escopados por conexão:

- `pdv_connection_id + external_sale_id + external_item_id`;
- `pdv_connection_id + external_event_id`;
- `pdv_connection_id + external_product_id`.

`PdvPayloadSanitizer` remove recursivamente authorization, Bearer, tokens, secrets, passwords e cookies antes de persistir inbox ou metadados de observabilidade. Respostas integrais de erro de autenticação não são armazenadas.

## Limites atuais

Mutations, backfill amplo, webhook e ativação da importação real continuam fora deste incremento. O relatório permanece estritamente read-only e a contagem oficial de coxinhas continua dependendo de mappings confirmados por uma pessoa.

O contrato real validado não fornece bandeira por pagamento. Crédito e débito são configuráveis com forma, adquirente, parcelas e taxa sem inventar bandeira. Uma bandeira interna continua opcional e somente deve ser selecionada quando uma regra humana explícita for válida para todo aquele mapping. Taxas mantêm vigência e histórico; vendas guardam snapshots, portanto alterações futuras não recalculam o passado.

## Transação oficial de venda

O staging externo e o ledger operacional permanecem separados. `PdvOrder` registra o que o GrandChef informou; `ProductSaleOrder` nasce somente depois de um POST explícito de confirmação e representa o cabeçalho oficial do pedido. Cada item confirmado continua sendo gravado como `ProductSale` pelo `ProductSaleService`, que aciona o fluxo oficial do `StockMovementService`.

`PdvOrderImportPlanService` produz um dry-run puro, sem persistência, contendo mappings, saldos consolidados por produto, itens, descontos, pagamentos, taxas, alocações, movimentos previstos, blockers e warnings. `PdvOrderImportService` recalcula esse plano dentro de uma transação, bloqueia a unidade e o pedido, e só então cria o cabeçalho, os itens, os pagamentos e as baixas de estoque. Qualquer falha reverte o pedido inteiro.

O caminho oficial preserva todos os pagamentos em `ProductSalePayment`; não seleciona `payments[0]` nem elege um pagamento principal. A taxa é calculada uma vez sobre cada pagamento, com snapshots da configuração vigente. Dinheiro e Pix ficam sem taxa quando não existe configuração explícita. Troco é preservado no cabeçalho e retirado do valor oficial em dinheiro, portanto não vira receita.

Como os relatórios precisam atribuir taxas aos produtos sem duplicar faturamento, `ProductSalePaymentAllocation` distribui bruto, receita, taxa e líquido entre os itens. A distribuição usa decimal exato e maior resto, com desempate estável pelo identificador externo. Assim, as parcelas fecham exatamente nos centavos do pagamento e a taxa fixa é aplicada somente uma vez.

As constraints de `pdv_order_id`, `pdv_order_payment_id` e `idempotency_key`, combinadas com locks transacionais, impedem reimportação e efeitos duplicados. `PdvOrderReversalService` preserva os registros originais, cria movimentos e pagamentos inversos uma única vez e utiliza exclusivamente os snapshots importados. Mudanças posteriores de mapping ou `source_hash` não reescrevem silenciosamente o histórico.

Importação em lote existe apenas como serviço interno e trata cada pedido em sua própria transação. Scheduler, backfill e importação automática continuam desativados.

### Gate operacional

`PDV_IMPORT_ENABLED` tem valor padrão `false`. Com a flag desligada, o preview permanece disponível, o botão de confirmação fica desabilitado e o backend recusa a operação. Ativar a flag não substitui os demais gates: todos os mappings, produtos, taxas obrigatórias, saldos, totais, permissões e escopo de unidade ainda precisam estar válidos.

## Estoque, marco operacional e sincronização

GrandChef **não fornece nem sincroniza estoque**. O saldo oficial nasce apenas dos Services do ERP. Estoque inicial exige `OpeningStockService` com preview e confirmação; vendas importadas baixam saldo apenas via `ProductSaleService → StockMovementService`. Não existe ledger, saldo ou ajuste paralelo do PDV.

`operational_start_at` é configurado por conexão e permanece anulável. Enquanto estiver `NULL`, nenhum pedido pode ser importado. Pedidos anteriores ao marco continuam no staging como histórico de pré-operação; o instante exato e os posteriores são elegíveis. A primeira janela futura de sync começa no marco, e as seguintes usam checkpoint escopado por conexão/unidade/stream, sem retroceder automaticamente.

`PDV_ENABLED`, `PDV_SYNC_ENABLED` e `PDV_IMPORT_ENABLED` são gates independentes e ficam `false` por padrão. O scheduler só é registrado quando `PDV_SYNC_INTERVAL_MINUTES` for maior que zero. Uma falha é isolada por conexão e não impede o processamento das demais lojas. Fábrica Central não recebe conexão GrandChef automática.

## Reversão e conciliação

Uma venda importada só pode ser revertida após cancelamento/estorno reconhecido na origem. O fluxo administrativo usa POST, CSRF, `pdv.manage`, escopo de unidade, motivo obrigatório, confirmação textual e chave da requisição. A transação preserva o original, cria movimentos e pagamentos inversos idempotentes, mantém snapshots e registra auditoria sanitizada.

A tela de conciliação por período é somente leitura. Ela compara total externo bruto e comparável com pedidos oficiais do ERP, separando importados, prontos, bloqueados, pré-operacionais, cancelados e revertidos, além de pagamentos e diferença monetária. A conciliação nunca corrige saldo, cria ledger ou altera venda.

## Segurança, auditoria e operação

- Todas as páginas exigem autenticação e `pdv.manage`; o Service também valida acesso à unidade.
- Escritas web usam CSRF, revalidação no backend, transação, locks e idempotência.
- Troca de conexão/credenciais, teste, mapping/remapping, marco operacional, importação e reversão deixam trilha de auditoria sem credenciais.
- Erros externos são convertidos em códigos/mensagens seguras; payload GraphQL bruto, headers, cookies e segredos não são persistidos.
- O caminho legado de importação direta recusa explicitamente provider `grandchef`; ele não alcança `payments[0]`, venda ou estoque.
- Antes do go-live, faça backup do PostgreSQL e valide restauração em ambiente separado. Nunca habilite sync/import antes de cadastrar mappings, taxas humanas aplicáveis, estoque físico e marco oficial.

### Checklist de go-live

1. Confirmar backup restaurável e acesso à loja correta.
2. Testar Bearer + Device sem registrar seus valores.
3. Revisar Products e mappings humanos.
4. Configurar adquirentes/taxas reais, com bandeira apenas quando aplicável.
5. Informar estoque físico pelo fluxo oficial do ERP.
6. Definir conscientemente data/hora do marco operacional.
7. Conferir readiness e ImportPlan sem efeitos colaterais.
8. Habilitar sync e import separadamente somente após autorização.
9. Importar primeiro um único pedido e conferir venda, pagamentos, taxas e estoque.
10. Usar health, eventos e conciliação para acompanhamento.

### Troubleshooting seguro

- `AUTH_EXPIRED`: substituir apenas a credencial vencida e testar a conexão.
- `NOT_CONFIGURED` / `CONNECTION_DISABLED`: conferir endpoint, Bearer, Device e ativação da loja.
- `OPERATIONAL_START_NOT_SET` / `BEFORE_OPERATIONAL_START`: definir o marco ou manter o pedido como histórico.
- `PRODUCT_MAPPING_MISSING`: mapear um Product oficial existente; nunca criar automaticamente a partir da venda.
- `PAYMENT_MAPPING_MISSING` / `PAYMENT_CONFIG_MISSING`: configurar método, adquirente e taxa vigente; bandeira é opcional quando a origem não a fornece.
- `STOCK_INSUFFICIENT`: corrigir estoque pelos fluxos oficiais, nunca pelo GrandChef.
- `TOTAL_MISMATCH` / `SOURCE_CHANGED`: interromper a importação e reconciliar a evidência externa.
- `ALREADY_IMPORTED`: tratar como idempotência bem-sucedida; não repetir efeitos.
- `IMPORT_DISABLED`: autorização operacional ainda não foi concedida.
