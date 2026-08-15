# Acesso seguro do agente pelo WhatsApp

## Princípio

Receber uma mensagem no número do WhatsApp não concede acesso ao ERP. A ordem obrigatória é identidade, autorização do canal, permissão empresarial e, somente então, agente, Tool ou IA.

## Allowlist e identidade

`UserExternalIdentity` é a fonte oficial para identidades externas. Uma entrada WhatsApp somente é autorizada quando:

- o telefone foi normalizado para o formato canônico internacional;
- existe identidade WhatsApp ativa e aprovada;
- a identidade está vinculada a um `User` ativo;
- a política do canal permite o tipo de mensagem;
- as permissões atuais do `User` permitem a operação e a unidade.

O telefone responde apenas quem é a pessoa. Roles, permissões individuais e escopo de unidades permanecem no `User` e são consultados a cada mensagem. Ativar um telefone não concede acesso administrativo, financeiro ou operacional.

## Normalização e unicidade

`PhoneNumberNormalizer` aceita pontuação e, para entradas brasileiras sem DDI, aplica `+55`. A persistência usa formato semelhante a E.164. Constraints parciais do PostgreSQL impedem simultaneamente:

- o mesmo telefone ativo em dois usuários;
- dois telefones WhatsApp ativos para o mesmo usuário.

Trocar o telefone desativa o anterior, ativa uma nova identidade e invalida ações WhatsApp pendentes do usuário.

## Fluxo inbound

`WhatsAppChannelAdapter` usa `WhatsAppIdentityResolver` e `AgentAccessPolicy` antes de registrar custo, persistir mensagem operacional ou preparar mídia. O fluxo é fail-closed.

Contatos desconhecidos, identidades inativas e usuários inativos:

- não recebem resposta automática por padrão;
- não criam identidade pendente automaticamente;
- não criam conversa, mensagem operacional ou ação pendente;
- não chamam Tool, OpenAI, transcrição ou interpretação de mídia;
- não baixam nem persistem foto, áudio, currículo ou PDF;
- não entram nas métricas de produtividade ou custo do agente.

É registrado somente um `AgentEvent` de segurança com motivo, horário, canal, ID da mensagem e hash do identificador. O conteúdo e o telefone completo não são armazenados nesse evento.

## Proveniência e loops

Mensagens operacionais persistidas possuem proveniência explícita:

- `authorized_user_inbound`;
- `system_agent_outbound`;
- `system_scheduled_outbound`;
- `human_manual_outbound`.

Status do provider são processados separadamente e nunca entram no agente. IDs externos e constraints existentes mantêm a idempotência do webhook. Mensagem manual deve usar `human_manual_outbound`; ela não gera custo de IA, Tool ou memória do agente.

## Mídia

A identidade e a permissão do tipo de mídia são verificadas antes do downloader. Um contato desconhecido que envie currículo, propaganda, foto, PDF ou áudio permanece completamente fora do pipeline operacional.

## Produção restrita

A política existente `ProductionUserPolicy` continua sendo aplicada depois da identidade e da autorização do canal. Ela não é contornada pela allowlist. Perfis restritos continuam limitados ao briefing, mídia e confirmações previstos no fluxo de produção.

## Boas-vindas

O administrador pode solicitar boas-vindas para uma identidade ativa. Com o cliente fake, a mensagem é verificável localmente. Com Meta desabilitada, o pedido permanece registrado e nenhuma chamada externa é feita. O nome vem do `User`, nunca do perfil informado pelo WhatsApp.

## Revogação, rate limit e ações pendentes

Desativação e troca de telefone cancelam imediatamente ações pendentes associadas às conversas WhatsApp do usuário. O rate limit é aplicado por identidade autorizada antes do agente. Confirmações continuam protegidas por usuário, conversa, status e chave idempotente.

## Administração e privacidade

As rotas administrativas exigem `whatsapp.identities.view` ou `whatsapp.identities.manage`. A listagem mascara o telefone. A página detalhada, acessível somente com permissão, mostra identidade, perfil, unidades, política do canal, permissões efetivas, ativação e boas-vindas.

## Mensagens manuais e contatos não autorizados

O ERP age somente para números cadastrados. Contatos externos continuam destinados ao tratamento humano e não são transformados em usuários do ERP. A convivência exata entre Cloud API, aplicativo e atendimento manual ainda deve ser validada com a documentação e a ativação oficial da Meta; este projeto não declara suporte operacional que ainda não foi testado.

## Flags seguras

- `WHATSAPP_ENABLED=false` mantém Meta real desligada.
- `WHATSAPP_UNKNOWN_CONTACT_AUTO_REPLY=false` mantém desconhecidos sem resposta automática.
- `WHATSAPP_DEFAULT_COUNTRY_CODE=55` define a região inicial sem impedir expansão futura.
- `WHATSAPP_IDENTITY_RATE_LIMIT_PER_MINUTE=30` limita flood por identidade.

## Limitações até a ativação oficial da Meta

Ainda precisam ser confirmados pela documentação oficial: diferenciação garantida de mensagens humanas manuais, eventos `echo`, política de retries/status, convivência com atendimento no aplicativo e custos específicos da Meta. Na ausência de origem confiável, o comportamento deve permanecer bloqueado (`unknown_origin`).
