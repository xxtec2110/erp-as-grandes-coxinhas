# Semântica de ingredientes

## Fontes oficiais

- `products.name`: nome comercial do produto ou sabor. O nome não cria nem reconstrói uma receita.
- `ingredients` e `preparations`: componentes operacionais reais usados na composição, no custo, nas compras e no estoque.
- `ingredients.brand`: marca informada explicitamente, separada do tipo do ingrediente.
- `product_recipes`: composição oficial atual, sempre vinculada por IDs reais de insumos e preparos.
- `ingredient_prices`: histórico oficial de preços do insumo real.
- `production_order_items.recipe_snapshot`: composição e custo históricos congelados no planejamento da produção.

## Catupiry como termo comercial

No domínio de As Grandes Coxinhas, `catupiry` é um termo comercial e operacional que representa o conceito de **requeijão usado na receita**. O termo isolado não identifica marca, fabricante, fornecedor nem um estoque independente.

Os nomes comerciais permanecem inalterados, por exemplo `Frango com catupiry`. A ficha técnica desse produto deve apontar para o cadastro real de requeijão escolhido pelo administrador. Alterar futuramente marca, fornecedor, preço ou vínculo operacional não renomeia o produto e não altera snapshots históricos.

O conceito protegido `requeijao` e seus termos `catupiry` e `requeijão` ficam centralizados nas tabelas de semântica. O vínculo com um insumo real é opcional e possui histórico próprio. Não existe interface casual para alterar a equivalência protegida.

### Resolução determinística

1. Um vínculo operacional vigente e explícito tem prioridade.
2. Sem vínculo, um único insumo ativo com nome conceitual exato pode ser usado.
3. Mais de um candidato exige definição administrativa; similaridade não escolhe nenhum deles.
4. Sem candidato, o sistema informa que é necessário definir qual cadastro de requeijão será utilizado.
5. Marca somente participa da resolução quando foi informada explicitamente.

Nenhum termo cria automaticamente insumo, marca, fornecedor, preço, ficha técnica ou quantidade. Texto, áudio e documentos passam pelo mesmo resolvedor depois da interpretação. Custo e estoque usam somente o ID real gravado na ficha; a matemática é determinística e não usa IA.
