# Go-live dos canais do Agente ERP

Este documento descreve a arquitetura oficial e os passos humanos para ativar OpenAI e Meta WhatsApp Cloud API. Nenhum telefone, token, App Secret, código OAuth ou QR real deve ser versionado.

## Identidades distintas

```text
MASTER_USER_PHONE
  -> envia mensagem para BUSINESS_CHANNEL_PHONE
  -> Meta WhatsApp Cloud API
  -> webhook HTTPS do ERP
  -> Identity Gate
  -> User / Permissions / Locations
  -> Agent / OpenAI / Tool / Service ERP
  -> Meta outbound
  -> MASTER_USER_PHONE
```

`MASTER_USER_PHONE` é uma `UserExternalIdentity` vinculada ao usuário oficial do ERP. `BUSINESS_CHANNEL_PHONE` pertence a `WhatsAppConnection` e nunca recebe cargo, permissão ou unidade.

## Gate antes de IA

O adapter valida, nesta ordem: destino empresarial, bloqueio de eco do próprio número, identidade, usuário, `respond_enabled`, rate limit e permissão do tipo de mídia. Um remetente desconhecido não cria mensagem inbound, não baixa mídia, não chama transcrição, visão, OpenAI, Tool nem outbound.

Saudações simples e ajuda são determinísticas. Consultas numéricas usam Tools de leitura oficiais. Escritas exigem preview, ação pendente, confirmação, revalidação do ator/permissão/unidade e Service oficial idempotente.

## OpenAI

O provider existente usa `POST /v1/responses`, Structured Output com JSON Schema, entrada de texto/imagem/arquivo e métricas de uso. Transcrição usa `POST /v1/audio/transcriptions`. O live test local depende de todas estas condições:

- `OPENAI_ENABLED=true` e `OPENAI_API_KEY` configurada somente no ambiente;
- `AGENT_AI_LIVE_TEST_ENABLED=true`;
- modelo e preço cadastrados;
- câmbio configurado;
- orçamento local positivo e disponível;
- usuário superadministrador;
- ambiente `local`.

Referências oficiais: [Responses API](https://developers.openai.com/api/reference/resources/responses/methods/create) e [modelos](https://developers.openai.com/api/docs/models).

## Meta WhatsApp Cloud API

Configuração de servidor esperada:

```dotenv
WHATSAPP_ENABLED=true
WHATSAPP_PROVIDER=meta
WHATSAPP_CLIENT=meta
WHATSAPP_MEDIA_DOWNLOADER=meta
WHATSAPP_MEDIA_DOWNLOAD_ENABLED=true
WHATSAPP_APP_ID=
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_ACCESS_TOKEN_TYPE=system_user
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=
```

O número humano não substitui `WHATSAPP_PHONE_NUMBER_ID`, e o WABA ID também deve vir da Meta. Credenciais ficam no ambiente; a connection persiste somente o telefone empresarial normalizado, estados e timestamps operacionais.

Rotas oficiais do webhook:

- verificação: `GET /api/webhooks/whatsapp`;
- eventos: `POST /api/webhooks/whatsapp`;
- assinatura: `X-Hub-Signature-256`, HMAC SHA-256 com o App Secret.

O callback precisa estar em HTTPS público. O projeto não instala túnel, não automatiza WhatsApp Web e não persiste QR. A referência oficial do canal é a [coleção Meta WhatsApp Cloud API](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api).

## Embedded Signup e Coexistence

O código não inicia Embedded Signup sem `Meta App ID`, Configuration ID, HTTPS público e contrato oficial confirmado. A elegibilidade de um número já usado no WhatsApp Business App só pode ser confirmada durante o onboarding oficial da Meta. Até essa confirmação, o estado correto é `INCONCLUSIVE` e qualquer QR depende da Meta; o ERP não fabrica QR próprio.

## Processo permanente

O canal não depende de navegador. Em operação, executar:

```bash
php artisan queue:work --sleep=3 --tries=3 --backoff=5,30,120 --timeout=120 --max-time=3600
php artisan schedule:work
```

O worker processa o webhook e seus retries. O scheduler já atende tarefas operacionais gerais do ERP; não existe polling do WhatsApp porque a origem é webhook.

## Checklist humano de ativação

1. **ERP UI:** confirmar o número empresarial e autorizar `MASTER_USER_PHONE` no usuário correto.
2. **Meta Developer Console:** criar/selecionar o Meta App, adicionar WhatsApp e configurar App ID, App Secret e Embedded Signup quando elegível.
3. **Meta Business Manager / WhatsApp Manager:** confirmar Business Portfolio, WABA, número empresarial, verificação e Phone Number ID.
4. **Meta Business Manager:** gerar token de System User com permissões mínimas necessárias; não depender de token temporário.
5. **Servidor:** publicar HTTPS, configurar as variáveis e executar worker/scheduler.
6. **Meta Developer Console:** informar callback `/api/webhooks/whatsapp`, Verify Token e assinar os eventos necessários.
7. **ERP UI:** executar health check somente leitura.
8. **Teste humano autorizado:** enviar uma saudação de `MASTER_USER_PHONE` para `BUSINESS_CHANNEL_PHONE`; só depois validar uma consulta de leitura.

Nenhum envio Meta real deve ocorrer antes de o painel indicar conexão pronta e de uma autorização humana específica para o primeiro outbound.
