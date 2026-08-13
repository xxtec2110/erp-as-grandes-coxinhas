# Deploy do ERP

Este guia prepara uma publicação controlada. Ele não autoriza apagar banco, executar `migrate:fresh` ou ativar integrações externas.

## Serviços

- Web: imagem criada pelo `Dockerfile`, porta interna `80`, health check `GET /up`.
- Worker: mesma imagem, comando `php artisan queue:work --sleep=3 --tries=3 --backoff=10 --timeout=120 --max-time=3600`.
- Scheduler: mesma imagem, comando `php artisan schedule:work`.
- PostgreSQL e Redis: serviços separados, com volumes persistentes e credenciais fornecidas somente por variáveis de ambiente.

Na configuração de produção, use `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` e, se desejado, `SESSION_DRIVER=redis`. A imagem inclui `pdo_pgsql`, `pgsql` e `phpredis`.

## Variáveis e segredos

Use `.env.example` como inventário. Configure no orquestrador, nunca na imagem: `APP_KEY`, URL, PostgreSQL, Redis, mail e, somente quando autorizadas, chaves OpenAI/Meta. Mantenha `OPENAI_ENABLED=false`, `WHATSAPP_ENABLED=false` e `AGENT_AI_LIVE_TEST_ENABLED=false` na primeira publicação.

Use `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://erp.asgrandescoxinhas.com.br`, `SESSION_SECURE_COOKIE=true` e configure o proxy do orquestrador para encaminhar corretamente o protocolo HTTPS. Defina remetente e transporte SMTP reais no ambiente antes de depender de alertas por e-mail.

## Volumes

Monte volume persistente em `/var/www/html/storage/app/private`. Se a instalação armazenar outros uploads em `storage/app/public`, persista esse diretório também e execute `php artisan storage:link` uma vez. Logs podem ir para stderr ou para volume conforme a política do ambiente.

## Sequência controlada

1. Faça backup verificável do PostgreSQL e dos volumes de uploads.
2. Construa a imagem e execute os testes fora de produção.
3. Configure web, worker e scheduler com a mesma versão da imagem.
4. Inicie a aplicação com `RUN_MIGRATIONS=false`.
5. Execute `php artisan migrate --force` como tarefa única e acompanhe a saída.
6. Verifique `/up`, `/login`, filas, scheduler e logs.
7. Libere o domínio e SSL somente depois da validação.

`RUN_MIGRATIONS=true` existe para ambientes que exigem migração no start, mas a opção recomendada é uma tarefa única separada, evitando concorrência entre réplicas.

## Backup e restauração

- Banco: gere dump no formato customizado com `pg_dump --format=custom` e valide-o listando seu conteúdo com `pg_restore --list`.
- Arquivos: copie o volume privado preservando nomes, permissões e datas; valide quantidade e hash de amostra.
- Restauração deve ser ensaiada em banco/volume isolados antes de qualquer uso em produção.

## Logs e monitoramento

Direcione logs da aplicação para a saída coletada pelo orquestrador ou persista `storage/logs` conforme a política da empresa. Monitore `/up`, falhas do worker, `failed_jobs`, scheduler, uso de disco, conexões PostgreSQL/Redis e alertas do agente. Nunca registre tokens, chaves ou conteúdo integral de documentos privados.

## Rollback

Republique a imagem anterior. Se uma migration exigir reversão de dados, pare e use um plano específico validado; não execute rollback genérico automaticamente. Restaure backup apenas após confirmar impacto e janela de indisponibilidade.

## Ativação posterior da OpenAI

Primeiro valide localmente com limite pequeno. A chave fica apenas no ambiente. O simulador Live requer ambiente local, superadministrador, `OPENAI_ENABLED=true`, `AGENT_AI_LIVE_TEST_ENABLED=true`, modelos configurados e `AGENT_AI_LIVE_TEST_BUDGET_BRL` positivo.

## Ativação posterior da Meta

Somente após autorização: configure Access Token, App Secret, Verify Token, Phone Number ID, WABA ID e callback HTTPS; valide assinatura e desafio; mantenha idempotência e observabilidade; faça primeiro teste com número controlado. Nenhum desses valores pertence ao Git.
