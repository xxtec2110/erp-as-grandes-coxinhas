# Histórico de compras, custos e margens

## Fontes oficiais

- `purchase_documents` e `purchase_document_items`: documento comercial confirmado e seus itens.
- `purchase_receipts` e `purchase_receipt_items`: evento físico de recebimento. Só este fluxo movimenta `ingredient_stock_movements`.
- `ingredient_prices`: memória econômica imutável por insumo e fornecedor. Um novo registro nunca substitui nem apaga o anterior.
- `product_cost_snapshots`: custo calculado e composição da ficha em um instante.
- `product_sales.*_snapshot`: custo, CPV, lucro e margem preservados no momento da venda.

`purchase_document_imports` é apenas uma área de revisão. Não é uma segunda fonte de compras ou estoque. Depois da confirmação, aponta para o documento oficial criado.

## Custo de reposição

O custo atual inicial usa o último preço confirmado marcado como atual. Cotações são guardadas com `source_type=quote`, mas não substituem o custo atual. O custo médio do estoque é um conceito separado e não é sobrescrito por esta regra.

As unidades são normalizadas pelo `UnitConversionService`:

- kg → g;
- l → ml;
- un → un;
- peso, volume e contagem nunca são convertidos entre si.

O custo-base é `valor líquido / quantidade normalizada`. Quantidades e valores usam `numeric/decimal` e `Brick\Math`; valores financeiros não usam `float`.

## Histórico e fornecedores

Cada preço preserva fornecedor, unidade, documento/item de origem, data efetiva, canal, forma original da embalagem, totais e custo normalizado. A comparação de 30 dias separa cotações de compras efetivas e oferece mínimo, máximo, média simples, média ponderada por quantidade, variação e quantidade de registros.

`supplier_ingredient_mappings` resolve descrições e códigos específicos de cada fornecedor. Esse texto não vira um alias global. Marca permanece um atributo separado. O `IngredientSemanticResolver` continua sendo a autoridade para termos protegidos; “Catupiry” resolve para o conceito operacional Requeijão e não cria um insumo independente.

## Recalculo e snapshots

Ao mudar o preço atual de um insumo, somente produtos ligados diretamente a ele ou por um preparo relacionado recebem novo snapshot. O preço de venda nunca é alterado automaticamente.

Produto sem ficha é exibido como **Ficha técnica pendente**. Ficha com componente sem preço é exibida como custo incompleto. Nenhum dos casos é tratado como custo zero.

Margem atual:

`(preço de venda atual - custo atual) / preço de venda atual × 100`

Margem histórica usa o `ProductPrice` vigente e o `ProductCostSnapshot` vigente na data consultada. Vendas antigas mantêm seus próprios snapshots mesmo depois de novos preços de insumo.

## Extensões futuras

PDF e XML devem alimentar a mesma interpretação estruturada e a mesma confirmação transacional. Não devem criar tabelas paralelas de compra, preço ou estoque. O identificador fiscal, a chave de acesso e o hash do anexo continuam sendo as chaves de deduplicação.
