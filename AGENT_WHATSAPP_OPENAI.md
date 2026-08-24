# Agente ERP, OpenAI e WhatsApp

## Princípio arquitetural

O agente é uma camada de interpretação e orquestração. Ele não possui banco, saldo ou regra de negócio paralelos.

```text
WhatsApp inbound
  -> assinatura e idempotência
  -> identidade externa aprovada
  -> usuário, permissões e unidades autorizadas
  -> ErpAgentService
  -> AgentToolRegistry / AgentToolExecutor
  -> Service oficial do ERP
  -> resposta auditada
  -> outbox idempotente / cliente WhatsApp
```

O ERP é a fonte oficial de estoque, catálogo, preço, venda e financeiro. GrandChef é somente uma origem de vendas do PDV e nunca fornece saldo de estoque ou preço oficial ao agente.

## Identidade, autorização e unidade

- O remetente é resolvido por `WhatsAppIdentityResolver` a partir do telefone normalizado.
- Apenas identidade aprovada e ativa, ligada a usuário ERP ativo, segue para inbox, mídia, IA ou Tool.
- Flags do canal e permissões do usuário são revalidadas em toda mensagem.
- `AuthorizationService` revalida a permissão e a `Location` em toda execução.
- Uma única unidade autorizada ou a unidade padrão válida pode fornecer contexto implícito.
- Em contexto multiunidade sem preferência, a consulta é pausada para o usuário escolher uma unidade.
- Unidade explícita inexistente, ambígua ou fora do acesso falha de forma fechada. IDs não são hardcoded.

## Read Tools oficiais

| Tool | Service oficial | Permissão | Unidade |
|---|---|---|---|
| `sales.summary` | `SalesSummaryService` | `sales.view` | obrigatória |
| `sales.products.ranking` | `ProductSalesRankingService` | `sales.view` | obrigatória |
| `sales.payments.summary` | `PaymentFeeReportService` | `sales.view` | obrigatória |
| `stock.products.query` | `StockPositionService` / `StockBalanceService` | `stock.view` | obrigatória |
| `stock.ingredients.query` | `IngredientStockPositionService` / `IngredientStockService` | `ingredient_stock.view` | obrigatória |
| `pdv.health` | `PdvHealthService` | `pdv.manage` | obrigatória |
| `pdv.reconciliation` | `PdvSalesReconciliationService` | `pdv.manage` | obrigatória |
| `products.prices.query` | `Product` / `ProductPrice` oficiais | `products.view` | global |
| `products.catalog.query` | `AgentOperationalReadService` / catálogo oficial | `products.view` | global |
| `ingredients.catalog.query` | `Ingredient` / `IngredientPrice` oficiais | `ingredients.view` | global |
| `suppliers.catalog.query` | `Supplier` oficial | `suppliers.view` | global |
| `purchases.documents.list/get`, `purchases.items.list`, `purchases.history`, `purchases.summary` | `PurchaseQueryService` | `purchases.view` | por acesso do usuário |
| `finance.payables.list/get`, `finance.payments.list` | `FinanceQueryService` | permissões financeiras específicas | por acesso do usuário |
| `production.orders.query` | `ProductionQueryService` / `ProductionOrder` | `production.orders.view` | obrigatória |
| `losses.query` | `ProductLossQueryService` | `losses.view` | obrigatória |
| `transfers.list` | `StockTransferQueryService` | `transfers.view` | obrigatória |

As Tools recebem contratos restritos. Períodos relativos são resolvidos no backend no timezone `America/Sao_Paulo`; períodos personalizados exigem `from` e `to` no formato `AAAA-MM-DD`. Produto usa nome oficial ou alias exato. Similaridade apenas produz pedido de esclarecimento.

Nenhuma Tool genérica de SQL existe ou deve ser adicionada.

## Escritas e confirmação

Toda Tool marcada como `writesData` e `confirmationRequired` segue:

```text
ErpAgentService
  -> PendingAgentActionService::prepare
  -> prévia para o usuário
  -> confirmação explícita no mesmo usuário e conversa
  -> AgentToolExecutor(confirmed: true)
  -> Service oficial
```

A ação guarda usuário, conversa, unidade/parâmetros, expiração, status e chave de idempotência. Confirmação repetida não duplica a operação. Ação expirada recebe status `expired`, não executa e exige novo comando. Uma identidade/conversa não enxerga a pendência de outra.

Na confirmação, o backend revalida usuário ativo, permissão, unidades, entidade, estado operacional e saldo. Mais de uma pendência na mesma conversa nunca é escolhida implicitamente por uma resposta como “sim”.

### Write Tools operacionais

| Domínio | Tools | Service oficial | Efeito após confirmação |
|---|---|---|---|
| Produtos | `catalog.products.create/update/update_price`, `catalog.product_aliases.create` | `ProductCatalogService` / `ProductPriceService` | cadastro ou novo evento de preço; histórico preservado |
| Insumos | `catalog.ingredients.create/update`, `catalog.ingredient_prices.add` | `IngredientCatalogService` / `IngredientPriceService` | cadastro ou novo preço normalizado; histórico preservado |
| Fornecedores | `catalog.suppliers.create/update` | `SupplierCatalogService` | cadastro validado, sem duplicidade normalizada/CNPJ |
| Compras | `purchases.documents.create` | `CreatePurchaseDocumentService` | cria documento e histórico de custo; não movimenta estoque |
| Recebimentos | `purchases.receipts.receive` | `PurchaseReceiptService` | recebimento total/parcial idempotente e movimento oficial de insumo |
| Contas a pagar | `finance.payables.create` | `CreatePayableService` | cria conta pendente validada |
| Pagamentos | `finance.payments.record` | `RegisterPaymentService` | pagamento total/parcial, limitado ao saldo remanescente |
| Produção | `production.orders.plan`, `production.orders.complete_batch` | `ProductionOrderService` | snapshot da ficha; conclusão consome insumos e adiciona produto |
| Perdas | `losses.record` | `ProductLossService` | movimento oficial negativo após revalidar saldo |
| Transferências | `transfers.create`, `transfers.dispatch`, `transfers.receive` | `StockTransferService` | cria pendência; saída só na expedição; entrada só no recebimento |

`transfers.complete` permanece compatível com fluxos internos existentes, mas o Agente orienta e inicia novas transferências pelo ciclo criar → expedir → receber. `stock.opening_balance.record` continua excepcional e protegido por permissão própria.

### Produção operacional e legado

O comando conversacional comum de produção usa somente `production.orders.complete_batch`. Essa Tool exige simultaneamente `production.orders.create` e `production.orders.complete`, captura o snapshot da ficha atual pelo `ProductionRecipeSnapshotService` e delega a operação ao `ProductionOrderService`. Na confirmação, saldo e estado são revalidados dentro da transação; cada insumo recebe uma baixa oficial idempotente e o produto recebe uma única entrada oficial.

`ProductionService` e as rotas web antigas de `/producao` permanecem preservados por compatibilidade. Eles registram `ProductionRecord` e, na conclusão, entrada do produto sem baixa de insumos. Por isso `production.plan` e `production.complete` não fazem parte do catálogo do Agente e não podem ser escolhidas pelo parser, Fake ou OpenAI.

Produto sem ficha válida, ficha com componente sem preço atual ou saldo insuficiente falha sem criar ordem parcial ou movimento. Repetir a confirmação retorna o resultado anterior e não duplica movimentos.

### Fichas e componentes

`catalog.preparations.create/update` delega ao `PreparationCatalogService`; listas de ingredientes só são substituídas quando o payload as fornece explicitamente. `catalog.product_recipes.create/update` delega ao `ProductRecipeService`; criação e atualização são distintas, e uma atualização apenas de cabeçalho preserva os componentes. Quando `ingredients` ou `preparations` são enviados explicitamente, representam a lista completa desejada, podendo adicionar, alterar ou remover componentes.

Ordens já planejadas mantêm seu `recipe_snapshot`. Alterar a ficha atual nunca reescreve snapshot, consumo ou custo histórico de produção anterior. O checklist de coleta dos dados reais está em `docs/ERP_MASTER_DATA_ONBOARDING.md`.

## OpenAI

- `OpenAiProvider` usa Responses API com saída estruturada por JSON Schema.
- O catálogo enviado ao modelo contém somente Tools autorizadas para o usuário.
- Texto, imagem, PDF e transcrição usam a infraestrutura existente.
- Timeout, tentativas para falhas transitórias, validação da resposta e provider indisponível falham com segurança.
- Tokens, modelo, duração e custo são registrados por `AgentCostService`, com orçamento e modo de economia.
- `FakeAiProvider` permite testes locais sem rede nem custo.
- O provider real permanece desabilitado por padrão e o Live Test exige ambiente local, Admin Master, flags, modelo, chave configurada e orçamento disponível.

## WhatsApp / Meta

- GET do webhook valida o verify token com comparação segura.
- POST valida limite de payload e assinatura HMAC antes de enfileirar.
- `ProcessWhatsAppWebhook` e `WhatsAppChannelAdapter` persistem evento, inbox, tentativas e status.
- Eventos e mensagens possuem chaves únicas contra replay.
- `WhatsAppOutboundService` cria uma única outbox por evento e reusa o registro nos retries.
- O cliente Meta e o downloader de mídia só são resolvidos quando as flags explícitas estão ativas; testes usam fakes.
- Mídia é vinculada à identidade já autorizada do inbound, validada por MIME real, extensão, tamanho, host HTTPS permitido e armazenamento privado.
- Número desconhecido não cria inbox, conversa, custo, download ou resposta com dados empresariais.

## Áudio, imagem e PDF

- Áudio: download seguro, armazenamento privado, transcrição fake/OpenAI, cache por hash, custo e envio do texto transcrito ao mesmo agente.
- Imagem/PDF: anexos privados, autorização por usuário/unidade e interpretação estruturada existente.
- Documentos e imagens são conteúdo não confiável. Instruções encontradas dentro deles não substituem system prompt, autorização ou confirmação.
- Imagem e PDF podem gerar somente interpretação, rascunho/prévia e ação pendente. Nenhum documento, preço, recebimento ou pagamento é gravado sem confirmação humana posterior.

## Auditoria, observabilidade e erros

`AgentEvent`, conversas, mensagens, pending actions, custos, inbox e outbox permitem rastrear mensagem, Tool, unidade, resultado, resposta, retry e falha sem registrar credenciais.

A área administrativa existente mostra provider/modelo, mensagens, Tools, custos, falhas, retries, ações pendentes/confirmadas/expiradas e métricas por domínio operacional. Respostas operacionais usam DTOs explícitos ou apenas contagens; nunca incluem tokens, credenciais do PDV, cookies, notas internas ou segredos de integração.

Falhas equivalentes cobertas: identidade desconhecida, canal não liberado, autorização negada, unidade ausente/ambígua/não autorizada, Tool não permitida, validação, provider indisponível, rate limit, confirmação pendente e ação expirada.

## Ativação segura

O padrão continua sem serviços externos:

```dotenv
AGENT_AI_PROVIDER=fake
OPENAI_ENABLED=false
AGENT_AI_LIVE_TEST_ENABLED=false
WHATSAPP_ENABLED=false
WHATSAPP_CLIENT=disabled
WHATSAPP_MEDIA_DOWNLOADER=disabled
WHATSAPP_MEDIA_DOWNLOAD_ENABLED=false
```

Credenciais ficam apenas no `.env` local/ambiente de execução e nunca em código, `.env.example`, log ou resposta. OpenAI real, Meta real, webhook público, push e deploy exigem autorização separada.

## Validação

Os testes usam PostgreSQL de teste, fakes de OpenAI/WhatsApp e fixtures isoladas. Cobrem consultas, ausência de dados, multiunidade, permissão, adulteração de argumentos, identidade desconhecida, confirmação, ator incorreto, expiração, replay, retry, falha de provider, HMAC, limite de payload e segurança de mídia. Nenhum teste deve chamar OpenAI, Meta ou GrandChef reais.

## Limites deliberados

- Não existe Tool genérica de SQL, edição direta de saldo ou escrita direta do modelo de IA.
- O Agente não inventa ficha técnica, preço, fornecedor, conta, unidade, quantidade ou vínculo aproximado.
- Cadastro/edição completa de ficha técnica permanece disponível somente quando o contrato oficial existente é inequívoco; snapshots históricos de produção nunca são reescritos.
- Integrações OpenAI, Meta e GrandChef continuam sujeitas às flags e autorizações independentes já documentadas.
