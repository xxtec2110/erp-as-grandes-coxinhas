# Integração PDV / GrandChef

## Estado

A integração real está desativada. O ERP não contém endpoint, autenticação, token, assinatura ou payload supostamente oficial do GrandChef. `GrandChefPdvProvider` declara as capacidades como `unknown` e responde `not_configured` até recebermos documentação oficial.

Flags seguras: `PDV_ENABLED=false`, `PDV_SYNC_ENABLED=false` e `PDV_WEBHOOK_ENABLED=false`.

## Arquitetura

- `PdvProviderInterface` isola o domínio do fornecedor e prevê consulta de vendas, atualizações, cancelamentos, catálogos, health e normalização futura de webhook.
- `GrandChefPdvProvider` é deliberadamente não operacional. `FakePdvProvider` permite testes sem rede.
- `ExternalSaleData`, `ExternalSaleItemData` e `ExternalSalePaymentData` são o contrato normalizado; valores monetários e quantidades permanecem strings decimais.
- `PdvInboundEvent` é a inbox idempotente. O payload é sanitizado, limitado e nunca é despejado integralmente em logs.
- `PdvSyncCheckpoint` guarda cursor opaco por conexão, unidade e stream. O sincronizador aceita intervalo para backfill e só avança o checkpoint após sucesso.
- Mappings confirmados ligam IDs externos a `Location`, `Product` e forma de pagamento. Nenhum produto é criado automaticamente e sugestões aproximadas nunca são confirmadas sozinhas.
- `PdvSaleImportService` usa exclusivamente `ProductSaleService`; este continua sendo a fonte oficial da venda e cria o único `StockMovement` oficial.
- A chave `pdv:{connection}:{external_sale}:{external_item}` e constraints PostgreSQL impedem duplicar venda, estoque e faturamento em webhook, polling ou retry.
- Cancelamentos não apagam venda ou movimento: `ProductSaleService::reverse` cria movimento inverso idempotente e registra o cancelamento.

## Push, polling e backfill

O endpoint genérico de webhook retorna 404 enquanto `PDV_WEBHOOK_ENABLED=false`. Mesmo habilitado, retorna 501 até que a autenticação/assinatura oficial seja implementada. Polling também permanece desligado e só é agendado quando há intervalo positivo. A futura implementação deve registrar rapidamente a inbox, enfileirar processamento e aplicar retry/backoff apenas a erros transitórios. Erros de mapping ficam em `waiting_mapping` e podem ser reprocessados.

## Segurança e operação

HTTPS, validação de assinatura, replay protection e limites reais serão implementados conforme a documentação do fornecedor. Segredos futuros ficam fora do Git; se persistidos na conexão, usam cast criptografado. O `/up` do ERP não depende do PDV: o painel possui health próprio (`healthy`, `degraded`, `offline`, `not_configured`). Não há escrita de volta no GrandChef.

## Produção e relatórios

Vendas importadas entram nas tabelas oficiais, portanto alimentam dashboard, ranking, estoque e produção sugerida existentes. `DailyProductionBriefService` é determinístico. O perfil de produção restrita bloqueia texto livre, áudio e PDF antes da IA; a confirmação de quadro reutiliza `ProductionOrderService`, e a rejeição remove a foto privada mantendo auditoria mínima.

## Ativação futura

1. Validar documentação e sandbox.
2. Implementar autenticação e transporte dentro de `GrandChefPdvProvider`.
3. Adaptar somente o normalizador do payload real.
4. Configurar mappings e credenciais fora do Git.
5. Testar conexão, backfill pequeno, duplicidade e cancelamento no sandbox.
6. Habilitar polling ou webhook — conforme capacidade documentada — e monitorar reconciliação/lag.

## Aguardando GrandChef

Solicitar ao fornecedor:

- documentação técnica oficial e base URL;
- método de autenticação, credenciais e ambiente sandbox;
- endpoints de vendas, venda individual, produtos, unidades e formas de pagamento;
- paginação, filtros por data/`updated_at`, cursor e limites de requisição;
- IDs únicos e estabilidade dos IDs de venda, item, pagamento, produto e unidade;
- estados possíveis de venda, cancelamento, estorno e reembolso parcial;
- timestamps, timezone e preservação do horário original;
- suporte a backfill/histórico e janela máxima;
- existência de webhook, eventos, assinatura, replay protection e política de retry;
- custos adicionais, limites e SLA da API;
- canal técnico para homologação e troubleshooting.

Não assumir `/api/v1`, não fazer scraping e não automatizar o painel web. Se não houver API, avaliar apenas exportação CSV/arquivo ou acesso read-only oficialmente autorizado.
