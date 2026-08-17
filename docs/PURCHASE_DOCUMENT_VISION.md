# Fluxo de documento de compra por imagem

## Limite de confiança

A IA apenas classifica e propõe campos. Ela não autoriza, não cria fornecedor ou insumo, não confirma correspondência aproximada e não grava compra, preço, conta a pagar ou estoque.

Classificações aceitas:

- `purchase_invoice`;
- `purchase_receipt`;
- `purchase_order`;
- `quotation`;
- `production_board`;
- `unknown_document`;
- `non_business_image`.

O sistema valida deterministicamente fornecedor, itens, unidades, totais, páginas repetidas, campos ausentes e ambiguidades. Um quadro de produção não entra no fluxo de compra.

## Estados

`uploaded → interpreting → needs_review → ready_for_confirmation → confirmed`

Estados terminais alternativos: `cancelled`, `duplicate` e `failed`.

Arquivos com o mesmo conteúdo são identificados por SHA-256. A identidade fiscal usa, quando disponível, chave de acesso; caso contrário usa fornecedor, número, série, data e total. Uma revisão expira após sete dias. A confirmação é idempotente.

## Confirmação

Na revisão, o administrador compara:

- texto extraído;
- fornecedor e insumo vinculados pelo ERP;
- valor confirmado pelo administrador;
- custo anterior e custo normalizado proposto;
- alertas de reconciliação.

Somente a confirmação final cria `purchase_documents`, itens, histórico de preços e mapeamentos fornecedor–insumo. A pergunta sobre recebimento físico é obrigatória. Se a resposta for sim, o recebimento oficial idempotente movimenta o estoque; se for não, apenas o documento econômico é criado. Conta a pagar é sempre uma ação separada.

## Segurança e custo da IA

Os anexos ficam em disco privado e guardam UUID de caminho, hash, MIME validado por assinatura, tamanho, autor, unidade, status e retenção. Download exige autenticação e permissão. Bytes, base64, chave da API, token e cookies não são registrados em logs.

As permissões e a unidade são verificadas antes da interpretação. Uma única chamada é feita para o conjunto de páginas quando possível; a interpretação de um anexo único é reutilizada por cache. Confirmação e cancelamento não chamam IA. O uso real é contabilizado pelo `AgentCostService`. Testes usam exclusivamente providers fake/mock.
