# Onboarding de dados mestres reais do ERP

## Regra de uso

Este documento é o checklist de coleta para o ambiente real. Ele não autoriza criar dados por inferência. Nome de produto, categoria ou costume operacional não substituem informação confirmada por Alexandre.

- Não inventar ingrediente, quantidade, rendimento, fornecedor, conta, motivo de perda, saldo ou preço.
- Não tratar `Location` como centro de custo sem confirmação da regra empresarial.
- Não criar ficha técnica para bebida ou classificá-la como revenda apenas por seu nome.
- Todo novo preço deve ser um evento histórico pelos Services oficiais; nunca sobrescrever o histórico.
- Estoque inicial só deve ser informado depois dos cadastros e conferências, usando o fluxo excepcional de prévia e confirmação.

Levantamento somente leitura realizado no PostgreSQL local em 24/08/2026. Nenhum dado empresarial foi gravado durante a auditoria.

## Estado real encontrado

| Cadastro | Quantidade | Situação |
|---|---:|---|
| Produtos ativos / total | 25 / 25 | todos ativos, unidade `un` |
| Categorias de produto | 2 | Coxinhas; Bebidas |
| Preços de produto | 25 | todos os produtos têm preço atual |
| Insumos | 0 | falta cadastrar os insumos reais |
| Preços de insumo | 0 | depende dos insumos e fornecedores reais |
| Fornecedores | 1 | ILHA DOS PESCADOS LTDA |
| Preparos intermediários | 0 | uso deve ser decidido pelas receitas reais |
| Fichas técnicas | 0 | nenhum produto possui ficha |
| Componentes diretos de ficha | 0 | nenhum componente cadastrado |
| Componentes de preparo em ficha | 0 | nenhum componente cadastrado |
| Contas financeiras | 0 | faltam as contas reais |
| Categorias financeiras | 12 | cadastro existente listado abaixo |
| Centros de custo | 5 | cadastro existente listado abaixo |
| Motivos de perda | 0 | falta catálogo real |
| Unidades/localizações | 3 | Fábrica Central, Catanduva e Ibirá |
| Movimentos de produto / insumo | 0 / 0 | nenhum saldo inicial ou operacional lançado |

As unidades de medida não formam um cadastro livre. O domínio atual admite famílias compatíveis: peso (`g`, `kg`), volume (`ml`, `l`) e contagem (`un`). A unidade-base do insumo é `g`, `ml` ou `un`; `UnitConversionService` converte apenas dentro da mesma família, com aritmética decimal.

## Completude nominal dos produtos ativos

Todos os itens abaixo têm preço atual, não têm ficha nem componentes, não têm custo calculável e são rastreáveis pelo ledger oficial de estoque por produto e localização. Como o modelo atual não possui um campo oficial produzido/revendido, a classificação operacional permanece pendente.

| ID | Produto | Categoria | Preço atual | Ficha/componentes | Custo | Status operacional |
|---:|---|---|---:|---|---|---|
| 1 | Frango tradicional | Coxinhas | R$ 15,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 2 | Frango com catupiry | Coxinhas | R$ 16,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 3 | Pernil com bacon | Coxinhas | R$ 16,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 4 | Calabresa com queijo | Coxinhas | R$ 16,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 5 | Carne tradicional | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 6 | Costela com queijo | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 7 | Alcatra com provolone | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 8 | Carne com queijo | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 9 | Carne, palmito e azeitonas | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 10 | Frango, milho, muçarela e catupiry | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 11 | Frango, bacon e cheddar | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 12 | Brócolis, muçarela e catupiry | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 13 | Quatro queijos | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 14 | Vegetariana | Coxinhas | R$ 22,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 15 | Carne seca com queijo | Coxinhas | R$ 28,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 16 | Camarão, muçarela e catupiry | Coxinhas | R$ 28,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 17 | Salmão com cream cheese | Coxinhas | R$ 28,00 | não / 0 | não calculável | classificação e ficha pendentes |
| 18 | Água de Coco | Bebidas | R$ 12,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 19 | Suco de Laranja 900 ml | Bebidas | R$ 16,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 20 | Coca-Cola Lata 350 ml | Bebidas | R$ 6,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 21 | Coca-Cola 1 Litro | Bebidas | R$ 10,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 22 | Água Mineral com Gás 510 ml | Bebidas | R$ 4,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 23 | Cerveja Antarctica Lata 350 ml | Bebidas | R$ 6,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 24 | Cerveja Heineken Lata 350 ml | Bebidas | R$ 8,00 | não / 0 | não calculável | classificação/fonte de custo pendente |
| 25 | Sprite Lata 350 ml | Bebidas | R$ 6,00 | não / 0 | não calculável | classificação/fonte de custo pendente |

Não existe produto hoje classificável como “completo para operação de custo/produção”. Há 25 produtos sem ficha e sem custo; não falta preço de venda em nenhum deles. Os 17 produtos na categoria Coxinhas são candidatos a produção interna, e os 8 da categoria Bebidas são candidatos a revenda, mas ambos os grupos exigem confirmação humana porque essa classificação não está modelada formalmente.

## Ordem recomendada de preenchimento

### 1. Confirmar unidades e classificação operacional

Confirmar os três locais reais e, para cada produto, informar se é produzido internamente, comprado/revendido ou se deve permanecer sem classificação. Não criar campo ou valor por suposição neste onboarding.

### 2. Fornecedores — `/fornecedores`

Já existe: **ILHA DOS PESCADOS LTDA**.

Para cada novo fornecedor, Alexandre deve informar:

- nome;
- CNPJ somente quando disponível e verdadeiro;
- contato, telefone e observações, quando aplicáveis;
- ativo/inativo.

Não há como determinar antecipadamente quantos fornecedores faltam.

### 3. Insumos — `/insumos`

Hoje existem **0 insumos**. A quantidade faltante não pode ser inferida dos nomes dos 17 sabores.

Para cada insumo real, informar:

- nome oficial;
- unidade-base: `g`, `ml` ou `un`;
- categoria, se aplicável;
- marca somente quando relevante e explicitamente conhecida;
- observações, se necessárias;
- ativo/inativo.

### 4. Preços de insumo — `/insumos/{id}`

Para cada evento real de compra/preço, informar:

- fornecedor cadastrado;
- quantidade comprada/quantidade da embalagem em decimal;
- unidade da compra compatível: `kg`, `g`, `l`, `ml` ou `un`;
- preço total efetivamente pago;
- data efetiva do preço;
- se deve se tornar o preço atual.

Exemplo de formato de coleta, sem valor presumido: `Muçarela | fornecedor real | peça 4 kg | R$ ___ | data ___`. O Service normaliza kg para g sem usar `float` e preserva o preço anterior.

### 5. Preparos intermediários — `/preparos`

Usar `Preparation` somente quando Alexandre confirmar que massa, recheio ou outro componente é produzido como lote intermediário reutilizável.

Para cada preparo real, informar:

- nome e descrição;
- quantidade/unidade inicial, quando usada;
- rendimento esperado e unidade;
- quantidade final real, quando conhecida;
- tempo total de preparo;
- ingredientes com quantidade e unidade;
- equipamentos/energia e custos adicionais, quando reais;
- observações e ativo/inativo.

### 6. Fichas técnicas — `/produtos/{id}/ficha-tecnica`

Hoje os **25 produtos não possuem ficha**. Para cada produto confirmado como produzido, Alexandre deve fornecer:

- produto exato;
- rendimento da ficha/lote;
- peso final em gramas, quando aplicável;
- perda técnica percentual real;
- custo de embalagem real;
- cada componente direto: insumo, quantidade e unidade;
- cada preparo intermediário: preparo, quantidade e unidade;
- observações.

O contrato atual permite criar ou substituir explicitamente as listas completas de insumos e preparos. Atualização apenas do cabeçalho preserva os componentes existentes. A ficha atual é mutável, mas snapshots já capturados em ordens de produção permanecem imutáveis.

### 7. Produtos e preços faltantes — `/produtos`

Nenhum dos 25 produtos atuais está sem preço. Informar somente novos produtos ou alterações reais. Cada alteração de preço cria histórico; não editar o preço antigo.

### 8. Financeiro — `/financeiro/configuracoes`

Hoje existem **0 contas financeiras**. Para cada conta real, informar:

- nome;
- instituição, quando aplicável;
- tipo;
- titularidade;
- unidade vinculada ou escopo global, conforme a regra real;
- observações e ativo/inativo.

Categorias existentes: Funcionários; Aluguel/Ocupação; Energia; Água; Internet/Sistemas; Transporte/Logística; Manutenção; Impostos/Taxas; Fornecedores; Equipamentos; Marketing; Outros.

Centros existentes: Fábrica/Produção; Loja Ibirá; Loja Catanduva; Administrativo; Transporte/Logística. Alexandre deve confirmar se eles correspondem à contabilidade real; unidade e centro de custo não são automaticamente equivalentes.

### 9. Motivos de perda — `/configuracoes/motivos-perda`

O `ProductLoss` usa catálogo obrigatório. Hoje existem **0 motivos**. Alexandre deve fornecer os nomes reais e o estado ativo/inativo. Não criar motivo genérico automaticamente.

### 10. Estoque físico — `/estoque-inicial`

Somente depois de revisar cadastros, fichas e locais, informar por produto/local:

- localização;
- produto;
- quantidade física contada;
- data real da contagem;
- justificativa/origem.

O saldo não é editado diretamente. A prévia tem zero efeito; a confirmação cria movimento oficial imutável e idempotente. Estoque de insumos deve seguir os fluxos oficiais de recebimento/ajuste autorizados, nunca ser deduzido de uma ficha.

### 11. Início da operação

Antes da primeira produção, confirmar: ficha completa, preços atuais dos insumos, saldo suficiente na Fábrica Central e permissões de criar/concluir ordem. Produção pelo Agente usa exclusivamente `ProductionOrderService` e não pode gerar produto sem consumir os insumos do snapshot.

## Uso do Agente após os dados reais

Com Fake/local ou provider autorizado posteriormente, o Agente pode preparar e confirmar cadastros de produtos, fornecedores, insumos, preços, preparos e fichas pelos Services oficiais. Não há SQL genérico nem escrita direta do modelo. Correspondências aproximadas exigem esclarecimento; uma ficha ou lista de componentes só é substituída quando os componentes completos são enviados explicitamente.

O CRUD atual é suficiente para o primeiro preenchimento controlado e fornece revisão antes de salvar. Nenhum importador em lote foi criado: sem um conjunto real de dados, isso adicionaria risco e arquitetura desnecessária. Se o volume real tornar o processo impraticável, um fluxo de lote futuro deverá validar, mostrar prévia, exigir confirmação e chamar os mesmos Services registro a registro.
