# Acesso seguro do agente pelo WhatsApp

## Princípio

Receber uma mensagem no número do WhatsApp não concede acesso ao ERP. A ordem obrigatória é identidade, autorização do canal, permissão empresarial e, somente então, agente, Tool ou IA.

## Allowlist e identidade

`UserExternalIdentity` é a fonte oficial para identidades externas. Uma entrada WhatsApp somente é autorizada quando:

- o telefone foi normalizado para o formato canônico internacional;
- existe identidade WhatsApp ativa e aprovada;
- `respond_enabled` está habilitado;
- a identidade está vinculada a um `User` ativo;
- a política do canal permite o tipo de mensagem;
- as permissões atuais do `User` permitem a operação e a unidade.

O telefone responde apenas quem é a pessoa. Roles, permissões individuais e escopo de unidades permanecem no `User` e são consultados a cada mensagem. Ativar um telefone não concede acesso administrativo, financeiro ou operacional.

## Normalização e unicidade

`PhoneNumberNormalizer` aceita pontuação e, para entradas brasileiras sem DDI, aplica `+55`. A persistência usa formato semelhante a E.164. Constraints parciais do PostgreSQL impedem simultaneamente:

- o mesmo telefone ativo em dois usuários;
- dois telefones WhatsApp ativos para o mesmo usuário.

Trocar o telefone desativa o vínculo anterior, cria uma nova identidade e invalida somente as ações pendentes iniciadas naquele vínculo. O histórico anterior não é reatribuído. Um telefone desativado pode ser vinculado explicitamente a outra pessoa; o resolver sempre prioriza o único vínculo ativo e o índice parcial continua impedindo dois usuários ativos com o mesmo número.

Reassociar uma identidade a outro usuário também preserva o registro anterior: ele é desativado, suas pendências são canceladas e um novo vínculo auditado é criado. Conversas e ações antigas mantêm o ator original.

## Fluxo inbound

`WhatsAppChannelAdapter` usa `WhatsAppIdentityResolver` e `AgentAccessPolicy` antes de registrar custo, persistir mensagem operacional ou preparar mídia. O fluxo é fail-closed.

Contatos desconhecidos, identidades inativas, usuários inativos e identidades com respostas desabilitadas:

- não recebem resposta automática por padrão;
- não criam identidade pendente automaticamente;
- não criam conversa, mensagem operacional ou ação pendente;
- não chamam Tool, OpenAI, transcrição ou interpretação de mídia;
- não baixam nem persistem foto, áudio, currículo ou PDF;
- não entram nas métricas de produtividade ou custo do agente.

É registrado somente um `AgentEvent` de segurança com motivo, horário, canal, ID da mensagem e hash do identificador. O conteúdo e o telefone completo não são armazenados nesse evento.

**Regra definitiva:** mensagens de números não autorizados são rejeitadas antes de qualquer chamada à OpenAI, transcrição, visão, execução de Tool ou consulta ao ERP.

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

## Saudação, ajuda e boas-vindas

Saudações simples são respondidas por template local determinístico, usando o nome amigável da identidade e sem OpenAI. `MENU` abre somente os atalhos já autorizados. As perguntas “ajuda”, “o que você faz?” e “o que posso consultar?” geram categorias amigáveis derivadas em tempo real de `User → Permissions → AgentToolRegistry`; nomes internos de permissões e Tools não são enviados ao usuário.

O administrador pode solicitar boas-vindas para uma identidade ativa, ligada a usuário ativo e com respostas habilitadas. Com o cliente fake, a mensagem é verificável localmente. Com Meta desabilitada, o pedido permanece registrado e nenhuma chamada externa é feita.

## Revogação, rate limit e ações pendentes

Desativação, bloqueio de respostas, troca de telefone e troca de usuário cancelam imediatamente as ações pendentes associadas àquela identidade/conversa. O rate limit é aplicado por identidade autorizada antes do agente. Confirmações continuam protegidas por usuário, conversa, status e chave idempotente; múltiplas pendências nunca são escolhidas de forma arbitrária.

## Administração e privacidade

As rotas administrativas exigem `whatsapp.identities.view` ou `whatsapp.identities.manage`. A listagem mascara o telefone e permite busca por nome, usuário ou número. A página detalhada, acessível somente com permissão, mostra identidade, perfil, unidades, política do canal, categorias amigáveis calculadas, ativação e boas-vindas. Não existe exclusão administrativa: a revogação é feita por desativação, preservando auditoria.

## Fluxo humano de ativação futura

1. Criar ou selecionar o `User` oficial do ERP.
2. Atribuir Roles/Permissions oficiais.
3. Atribuir Locations oficiais.
4. Cadastrar e normalizar o telefone WhatsApp.
5. Vincular o telefone ao `User`.
6. Ativar a identidade e o controle de resposta.
7. Validar saudação e ajuda determinísticas.
8. Validar uma consulta permitida e uma negada.
9. Validar uma escrita com preview, PendingAction e confirmação.

## Mensagens manuais e contatos não autorizados

O ERP age somente para números cadastrados. Contatos externos continuam destinados ao tratamento humano e não são transformados em usuários do ERP. A convivência exata entre Cloud API, aplicativo e atendimento manual ainda deve ser validada com a documentação e a ativação oficial da Meta; este projeto não declara suporte operacional que ainda não foi testado.

## Flags seguras

- `WHATSAPP_ENABLED=false` mantém Meta real desligada.
- `WHATSAPP_UNKNOWN_CONTACT_AUTO_REPLY=false` mantém desconhecidos sem resposta automática.
- `WHATSAPP_DEFAULT_COUNTRY_CODE=55` define a região inicial sem impedir expansão futura.
- `WHATSAPP_IDENTITY_RATE_LIMIT_PER_MINUTE=30` limita flood por identidade.

## Limitações até a ativação oficial da Meta

Ainda precisam ser confirmados pela documentação oficial: diferenciação garantida de mensagens humanas manuais, eventos `echo`, política de retries/status, convivência com atendimento no aplicativo e custos específicos da Meta. Na ausência de origem confiável, o comportamento deve permanecer bloqueado (`unknown_origin`).
