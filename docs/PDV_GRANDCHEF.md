# Integração PDV / GrandChef

## Estado atual

A fundação GrandChef é multiunidade e exclusivamente read-only até a importação oficial ser autorizada. Cada loja possui sua própria `PdvConnection`, com endpoint em `configuration` e Bearer token em `encrypted_credentials` (cast criptografado e atributo oculto).

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

A listagem mostra somente unidades acessíveis, status, ativação, credencial configurada, última tentativa, última conexão bem-sucedida e erro sanitizado. A edição nunca devolve o Bearer ao HTML:

- token vazio preserva a credencial atual;
- novo token substitui explicitamente a credencial;
- ativação exige loja ativa, endpoint HTTPS e token;
- conexão vinculada não pode ser movida para outra unidade.

Mappings de unidade são confinados à unidade da conexão. Produtos externos só contam como coxinha após mapping humano confirmado com um `Product` da categoria oficial `Coxinhas`. Não há confirmação automática por fuzzy matching.

## Transporte GraphQL

`GrandChefGraphqlClient` recebe obrigatoriamente uma `PdvConnection` e é responsável por:

- endpoint e Bearer da conexão;
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

O relatório não cria `ProductSale`, `StockMovement`, `IngredientStockMovement`, financeiro, recebível ou qualquer lançamento operacional. A futura importação continuará obrigatoriamente pelo fluxo:

`GrandChef → PdvConnection → Provider → mappings → PdvSaleImportService → ProductSaleService → StockMovementService`.

## Idempotência e sanitização

Os identificadores permanecem escopados por conexão:

- `pdv_connection_id + external_sale_id + external_item_id`;
- `pdv_connection_id + external_event_id`;
- `pdv_connection_id + external_product_id`.

`PdvPayloadSanitizer` remove recursivamente authorization, Bearer, tokens, secrets, passwords e cookies antes de persistir inbox ou metadados de observabilidade. Respostas integrais de erro de autenticação não são armazenadas.

## Limites atuais

Mutations, backfill amplo, webhook e ativação da importação real continuam fora deste incremento. O relatório permanece estritamente read-only e a contagem oficial de coxinhas continua dependendo de mappings confirmados por uma pessoa.

## Transação oficial de venda

O staging externo e o ledger operacional permanecem separados. `PdvOrder` registra o que o GrandChef informou; `ProductSaleOrder` nasce somente depois de um POST explícito de confirmação e representa o cabeçalho oficial do pedido. Cada item confirmado continua sendo gravado como `ProductSale` pelo `ProductSaleService`, que aciona o fluxo oficial do `StockMovementService`.

`PdvOrderImportPlanService` produz um dry-run puro, sem persistência, contendo mappings, saldos consolidados por produto, itens, descontos, pagamentos, taxas, alocações, movimentos previstos, blockers e warnings. `PdvOrderImportService` recalcula esse plano dentro de uma transação, bloqueia a unidade e o pedido, e só então cria o cabeçalho, os itens, os pagamentos e as baixas de estoque. Qualquer falha reverte o pedido inteiro.

O caminho oficial preserva todos os pagamentos em `ProductSalePayment`; não seleciona `payments[0]` nem elege um pagamento principal. A taxa é calculada uma vez sobre cada pagamento, com snapshots da configuração vigente. Dinheiro e Pix ficam sem taxa quando não existe configuração explícita. Troco é preservado no cabeçalho e retirado do valor oficial em dinheiro, portanto não vira receita.

Como os relatórios precisam atribuir taxas aos produtos sem duplicar faturamento, `ProductSalePaymentAllocation` distribui bruto, receita, taxa e líquido entre os itens. A distribuição usa decimal exato e maior resto, com desempate estável pelo identificador externo. Assim, as parcelas fecham exatamente nos centavos do pagamento e a taxa fixa é aplicada somente uma vez.

As constraints de `pdv_order_id`, `pdv_order_payment_id` e `idempotency_key`, combinadas com locks transacionais, impedem reimportação e efeitos duplicados. `PdvOrderReversalService` preserva os registros originais, cria movimentos e pagamentos inversos uma única vez e utiliza exclusivamente os snapshots importados. Mudanças posteriores de mapping ou `source_hash` não reescrevem silenciosamente o histórico.

Importação em lote existe apenas como serviço interno e trata cada pedido em sua própria transação. Scheduler, backfill e importação automática continuam desativados.

### Gate operacional

`PDV_IMPORT_ENABLED` tem valor padrão `false`. Com a flag desligada, o preview permanece disponível, o botão de confirmação fica desabilitado e o backend recusa a operação. Ativar a flag não substitui os demais gates: todos os mappings, produtos, taxas obrigatórias, saldos, totais, permissões e escopo de unidade ainda precisam estar válidos.
