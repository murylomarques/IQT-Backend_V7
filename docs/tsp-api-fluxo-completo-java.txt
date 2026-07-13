# API TSP - fluxo completo e roteiro para isolar em Java

Documento gerado em 2026-07-07 a partir da leitura do codigo atual do projeto Laravel.
Ele descreve como a API TSP funciona hoje e o que precisa ser preservado ou melhorado
quando ela for separada em uma API Java focada em seguranca.

Este arquivo nao contem segredos. Nao copiar valores reais de `.env`, `client_secret`,
tokens ou credenciais Salesforce para este documento, repositorio ou logs.

## Objetivo

A API TSP atual fica dentro do backend Laravel do IQT. Ela expoe uma API externa em
`/api/v1/tsp` para:

- emitir token para um client externo TSP;
- listar agendamentos TSP/Sorocaba dos proximos 15 dias;
- fixar/desfixar um agendamento;
- mudar tecnico;
- mudar horario;
- suspender um agendamento com descricao e imagem.

A leitura da agenda vem de uma tabela local/sincronizada. As escritas reais acontecem no
Salesforce, depois de validacoes locais e validacoes de escopo no proprio Salesforce.

## Arquivos principais no Laravel

- `routes/api.php`: registra as rotas `/api/v1/tsp`.
- `app/Http/Controllers/Api/TspController.php`: valida requests, chama servicos e monta respostas.
- `app/Http/Middleware/TspBearerAuth.php`: valida Bearer token e escopos.
- `app/Services/Tsp/TspTokenService.php`: emite e autentica tokens da API TSP.
- `app/Services/Tsp/TspAppointmentService.php`: consulta agendamentos elegiveis.
- `app/Services/Tsp/TspAppointmentMapper.php`: define campos expostos e normaliza status/textos.
- `app/Services/Tsp/TspWriteService.php`: executa escritas no Salesforce e grava auditoria.
- `app/Services/Tsp/SalesforceClient.php`: client REST/OAuth2 para Salesforce.
- `app/Services/Tsp/TspRequestLogger.php`: log tecnico de requisicoes.
- `app/Services/Tsp/TspDemoService.php`: modo demo sem chamada real ao Salesforce.
- `config/tsp.php`: configuracoes da API.
- `config/database.php`: conexao MySQL `tsp`, banco padrao `dw_tsp`.
- `database/migrations/2026_06_30_000100_create_dw_tsp_tables.php`: tabelas da API TSP.
- `database/sql/dw_tsp_schema.sql`: criacao do banco `dw_tsp`.
- `app/Console/Commands/TspClientCommand.php`: comando `php artisan tsp:client`.
- `app/Providers/RouteServiceProvider.php`: rate limits `tsp-token` e `tsp-api`.
- `app/Http/Kernel.php`: alias de middleware `tsp.auth`.

## Visao geral do fluxo

1. O client externo chama `POST /api/v1/tsp/auth/token` com `client_id` e `client_secret`.
2. `TspTokenService` consulta `dw_tsp.api_clients`, exige client ativo e valida o secret
   usando hash Laravel.
3. Se valido, gera token opaco com `random_bytes(32)`, retorna o token em claro uma unica vez
   e grava apenas `sha256(token)` em `dw_tsp.api_tokens`.
4. O client envia `Authorization: Bearer <access_token>` nas demais rotas.
5. `TspBearerAuth` calcula `sha256` do token recebido, procura token nao revogado e nao expirado,
   carrega o client ativo e valida escopo.
6. O controller gera `request_id` UUID, valida entrada e chama o servico correto.
7. Leitura: `TspAppointmentService` consulta a fonte configurada, filtrando apenas TSP/Sorocaba.
8. Escrita: antes de alterar Salesforce, o sistema valida que o agendamento existe na fonte local
   e pertence ao escopo TSP/Sorocaba.
9. `TspWriteService` consulta o Salesforce, revalida empresa/cidade no objeto Salesforce e executa
   a alteracao.
10. Toda chamada gera log em `api_request_logs`; escritas geram tambem `write_operation_logs`.

## Base URL e versionamento

Base path atual:

```text
/api/v1/tsp
```

Rotas atuais:

```text
POST /api/v1/tsp/auth/token
GET  /api/v1/tsp/agendamentos/proximos-15-dias
POST /api/v1/tsp/agendamentos/{id}/fixar
POST /api/v1/tsp/agendamentos/{id}/mudar-tecnico
POST /api/v1/tsp/agendamentos/{id}/mudar-horario
POST /api/v1/tsp/agendamentos/{id}/suspender
```

Para a API Java, manter o mesmo path e os mesmos nomes de campos facilita a migracao.

## Autenticacao e autorizacao

### Cadastro de client

Hoje o client e criado/atualizado por:

```bash
php artisan tsp:client tsp --name="TSP"
```

O comando:

- recebe `client_id`;
- recebe ou gera `client_secret`;
- grava `secret_hash` em `dw_tsp.api_clients`;
- define escopos padrao `["tsp:read", "tsp:write"]`;
- define `token_ttl_minutes`;
- imprime o `client_secret` uma unica vez.

Na API Java, o equivalente deve ser uma rotina administrativa segura, por exemplo CLI interna,
endpoint admin protegido por MFA, ou migracao/manual em cofre. Nunca expor criacao de client em
endpoint publico.

### Emissao de token

Endpoint:

```http
POST /api/v1/tsp/auth/token
Content-Type: application/json
```

Request:

```json
{
  "client_id": "tsp",
  "client_secret": "<secret>"
}
```

Validacoes:

- `client_id`: obrigatorio, string, maximo 255 caracteres;
- `client_secret`: obrigatorio, string, maximo 1024 caracteres;
- client precisa existir e estar ativo;
- secret precisa bater com o hash salvo.

Resposta de sucesso:

```json
{
  "request_id": "<uuid>",
  "access_token": "<token-opaco>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "expires_at": "2026-07-07T12:00:00-03:00",
  "scope": "tsp:read tsp:write"
}
```

Erros principais:

- `401 invalid_client`: `client_id` ou `client_secret` invalido;
- `422 validation_error`: payload invalido;
- `500 token_error`: falha interna ao emitir token.

### Uso do Bearer token

Header:

```http
Authorization: Bearer <access_token>
```

O middleware atual:

- rejeita ausencia de Bearer com `401 unauthorized`;
- rejeita token invalido ou expirado com `401 invalid_token`;
- rejeita falta de escopo com `403 forbidden`;
- atualiza `last_used_at` do token e do client.

Escopos:

- `tsp:read`: acesso ao endpoint de listagem;
- `tsp:write`: acesso aos endpoints de escrita.

## Rate limit efetivo

Rate limits especificos atuais:

- `tsp-token`: 20 requisicoes por minuto por IP;
- `tsp-api`: 120 requisicoes por minuto por hash do Bearer token, ou IP quando nao ha Bearer.

Ponto de atencao: as rotas tambem passam pelo grupo Laravel `api`, que aplica `throttle:api`
de 60 requisicoes por minuto por usuario/IP. No codigo atual, `routes/api.php` ainda envolve as
rotas em `Route::middleware('api')`, enquanto o `RouteServiceProvider` ja carrega o arquivo com
middleware `api`. Antes de portar, confirmar em runtime se esse throttle esta duplicado. Na API
Java, definir explicitamente o limite desejado para evitar comportamento acidental.

## Regras de escopo de negocio

Toda leitura e toda escrita TSP deve ficar restrita a:

```text
empresa_tecnico = TSP SERVICOS ELETRICOS LTDA
cidade = Sorocaba
```

A comparacao no codigo atual normaliza texto:

- converte para minusculo;
- remove espacos, `_` e `-`;
- tenta transliterar/remover acentos;
- tambem trata alguns caracteres com encoding quebrado.

Isso significa que variacoes como espaco, hifen e caixa nao devem burlar nem quebrar a regra.

## Fonte de leitura

Configuracao padrao:

```text
connection: TSP_SOURCE_CONNECTION, padrao melhoria_continua
table:      TSP_SOURCE_TABLE, padrao agendamentos_geovane
```

O documento antigo menciona a fonte como:

```text
db_Melhoria_continua_operacoes.agendamentos_geovane
```

Na API Java, validar o nome real do schema no ambiente antes de subir. O usuario do banco usado
para essa fonte deve ser somente leitura.

Campos selecionados e expostos pela API:

```text
id
numero_compromisso
data_agendamento
data_ultima_modificacao
nome_tecnico
empresa_tecnico
cidade
micro_territorio
territorio_servico
inicio_janela_chegada
termino_janela_chegada
inicio_agendado
termino_agendado
tipo_trabalho
subtipo_trabalho
status
fixado
numero_ordem_trabalho
numero_caso
nome_conta
prioridade
```

O mapper tambem adiciona:

- `empresa_api: "TSP"`;
- `cnpj_empresa`, se `TSP_COMPANY_CNPJ` estiver configurado.

## SQL da fonte local

Essas queries representam o que a API Java precisa fazer contra a fonte de agenda
`TSP_SOURCE_TABLE`, hoje `agendamentos_geovane`. Usar sempre parametros bindados, nunca
concatenar valores recebidos do client.

### Normalizacao usada nos filtros

O Laravel compara empresa e cidade usando a expressao abaixo. Ela remove espaco, `_`, `-` e
coloca tudo em minusculo.

```sql
LOWER(REPLACE(REPLACE(REPLACE(COALESCE(campo, ''), ' ', ''), '_', ''), '-', ''))
```

Valores normalizados esperados:

```text
TSP SERVICOS ELETRICOS LTDA -> tspservicoseletricosltda
Sorocaba                    -> sorocaba
```

Na API Java, implementar a mesma normalizacao em codigo e passar o valor normalizado como
parametro. Se o banco permitir coluna gerada ou indice funcional, criar indice para essa
normalizacao para evitar full scan.

### Query de listagem dos proximos 15 dias

Sem cursor:

```sql
SELECT
  id,
  numero_compromisso,
  data_agendamento,
  data_ultima_modificacao,
  nome_tecnico,
  empresa_tecnico,
  cidade,
  micro_territorio,
  territorio_servico,
  inicio_janela_chegada,
  termino_janela_chegada,
  inicio_agendado,
  termino_agendado,
  tipo_trabalho,
  subtipo_trabalho,
  status,
  fixado,
  numero_ordem_trabalho,
  numero_caso,
  nome_conta,
  prioridade
FROM agendamentos_geovane
WHERE data_agendamento IS NOT NULL
  AND data_agendamento >= :from_datetime
  AND data_agendamento < :to_datetime
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', '')) = :empresa_normalizada
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', '')) = :cidade_normalizada
ORDER BY data_agendamento ASC, id ASC
LIMIT :page_size_plus_one;
```

Parametros:

```text
:from_datetime          hoje 00:00:00 no timezone TSP_TIMEZONE
:to_datetime            hoje + 16 dias 00:00:00 no timezone TSP_TIMEZONE
:empresa_normalizada    tspservicoseletricosltda
:cidade_normalizada     sorocaba
:page_size_plus_one     page_size + 1
```

Com cursor:

```sql
SELECT
  id,
  numero_compromisso,
  data_agendamento,
  data_ultima_modificacao,
  nome_tecnico,
  empresa_tecnico,
  cidade,
  micro_territorio,
  territorio_servico,
  inicio_janela_chegada,
  termino_janela_chegada,
  inicio_agendado,
  termino_agendado,
  tipo_trabalho,
  subtipo_trabalho,
  status,
  fixado,
  numero_ordem_trabalho,
  numero_caso,
  nome_conta,
  prioridade
FROM agendamentos_geovane
WHERE data_agendamento IS NOT NULL
  AND data_agendamento >= :from_datetime
  AND data_agendamento < :to_datetime
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', '')) = :empresa_normalizada
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', '')) = :cidade_normalizada
  AND (
    data_agendamento > :last_data_agendamento
    OR (
      data_agendamento = :last_data_agendamento
      AND id > :last_id
    )
  )
ORDER BY data_agendamento ASC, id ASC
LIMIT :page_size_plus_one;
```

O `page_token` atual guarda:

```json
{
  "kind": "tsp_next15",
  "last_data_agendamento": "2026-07-07 10:00:00",
  "last_id": "08p..."
}
```

Para Java, usar esse mesmo conteudo, mas assinado com HMAC ou salvo server-side.

### Query para resolver `{id}` antes de escrita

`{id}` pode ser `id` ou `numero_compromisso`.

```sql
SELECT
  id,
  numero_compromisso,
  data_agendamento,
  data_ultima_modificacao,
  nome_tecnico,
  empresa_tecnico,
  cidade,
  micro_territorio,
  territorio_servico,
  inicio_janela_chegada,
  termino_janela_chegada,
  inicio_agendado,
  termino_agendado,
  tipo_trabalho,
  subtipo_trabalho,
  status,
  fixado,
  numero_ordem_trabalho,
  numero_caso,
  nome_conta,
  prioridade
FROM agendamentos_geovane
WHERE (id = :id_or_numero_compromisso OR numero_compromisso = :id_or_numero_compromisso)
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', '')) = :empresa_normalizada
  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', '')) = :cidade_normalizada
LIMIT 1;
```

Se nao retornar registro, responder `400 bad_request` com mensagem equivalente a:

```text
Agendamento nao encontrado ou fora da regra TSP/Sorocaba.
```

### Indices recomendados na fonte

Se a tabela `agendamentos_geovane` puder receber indice sem impacto no sistema de origem:

```sql
CREATE INDEX ag_tsp_period_idx
  ON agendamentos_geovane (data_agendamento, id);

CREATE INDEX ag_tsp_id_idx
  ON agendamentos_geovane (id);

CREATE INDEX ag_tsp_numero_compromisso_idx
  ON agendamentos_geovane (numero_compromisso);
```

Para MySQL 8+, se for permitido usar colunas geradas para filtros normalizados:

```sql
ALTER TABLE agendamentos_geovane
  ADD COLUMN empresa_tecnico_norm varchar(255)
    GENERATED ALWAYS AS (LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', ''))) STORED,
  ADD COLUMN cidade_norm varchar(255)
    GENERATED ALWAYS AS (LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', ''))) STORED,
  ADD INDEX ag_tsp_scope_period_idx (empresa_tecnico_norm, cidade_norm, data_agendamento, id);
```

So aplicar esses indices depois de confirmar permissao e impacto no banco de origem.

## Listagem de agendamentos

Endpoint:

```http
GET /api/v1/tsp/agendamentos/proximos-15-dias?page_size=500&page_token=<token>
Authorization: Bearer <access_token>
```

Escopo exigido:

```text
tsp:read
```

Validacoes:

- `page_size`: opcional, inteiro, minimo 1, maximo `TSP_PAGE_SIZE_MAX`;
- `page_token`: opcional, string, maximo 4096 caracteres.

Janela de data:

- timezone: `TSP_TIMEZONE`, padrao `America/Sao_Paulo`;
- inicio: hoje `00:00:00`;
- fim exibido: hoje + 15 dias;
- consulta inclui o dia final inteiro, porque filtra `< (fim + 1 dia 00:00:00)`;
- registros sem `data_agendamento` nao entram.

Ordenacao:

```text
data_agendamento ASC, id ASC
```

Paginacao atual:

- busca `page_size + 1`;
- se sobrar registro, retorna `has_more: true`;
- `next_page_token` e um base64url de JSON com:

```json
{
  "kind": "tsp_next15",
  "last_data_agendamento": "2026-07-07 10:00:00",
  "last_id": "08p..."
}
```

Ponto de seguranca para Java: hoje o cursor e apenas codificado em base64url, nao assinado. Na
reimplementacao, usar cursor opaco assinado com HMAC ou armazenado server-side para impedir
alteracao pelo client.

Resposta:

```json
{
  "request_id": "<uuid>",
  "periodo": {
    "inicio": "2026-07-07",
    "fim": "2026-07-22"
  },
  "filters": {
    "empresa_tecnico": "TSP SERVICOS ELETRICOS LTDA",
    "cidade": "Sorocaba"
  },
  "pagination": {
    "page_size": 500,
    "has_more": false,
    "next_page_token": null
  },
  "items": [
    {
      "empresa_api": "TSP",
      "id": "08p...",
      "numero_compromisso": "SA-...",
      "data_agendamento": "2026-07-07 07:00:00",
      "data_ultima_modificacao": "2026-07-07 01:01:26",
      "nome_tecnico": "NOME",
      "empresa_tecnico": "TSP SERVICOS ELETRICOS LTDA",
      "cidade": "Sorocaba",
      "micro_territorio": "BASE SOROCABA",
      "territorio_servico": "SOR003",
      "inicio_janela_chegada": "2026-07-07 07:00:00",
      "termino_janela_chegada": "2026-07-07 13:00:00",
      "inicio_agendado": "2026-07-07 10:39:00",
      "termino_agendado": "2026-07-07 11:54:00",
      "tipo_trabalho": "Ativacao",
      "subtipo_trabalho": "Internet",
      "status": "agendado",
      "fixado": false,
      "numero_ordem_trabalho": "WO-...",
      "numero_caso": "CASE-...",
      "nome_conta": "Cliente",
      "prioridade": "Normal"
    }
  ]
}
```

Normalizacao de status:

- concluida/concluido -> `concluido`;
- cancelado/cancelada/canceled -> `cancelado`;
- suspensa/suspenso -> `suspenso`;
- agendado, despachado, em deslocamento, chegada no local, em execucao, nao iniciado,
  em espera -> `agendado`;
- qualquer outro status volta como veio da fonte.

## Identificador `{id}` nas escritas

Os endpoints de escrita aceitam no path:

- `id` Salesforce/local do `ServiceAppointment`; ou
- `numero_compromisso`.

Antes de qualquer escrita, o Laravel chama `eligibleAppointment($id)`, que busca na fonte local
por `id` ou `numero_compromisso` e exige que o registro seja TSP/Sorocaba.

## Escrita: fixar/desfixar

Endpoint:

```http
POST /api/v1/tsp/agendamentos/{id}/fixar
Authorization: Bearer <access_token>
Content-Type: application/json
```

Escopo exigido:

```text
tsp:write
```

Request:

```json
{
  "fixado": true,
  "motivo": "Fixado pela TSP"
}
```

Validacoes:

- `fixado`: obrigatorio, boolean;
- `motivo`: opcional, string, maximo 1000.

Ponto importante: no codigo atual, `motivo` e validado e salvo no log de escrita, mas nao e
enviado ao Salesforce.

Fluxo Salesforce:

1. Consulta `ServiceAppointment` pelo `source.id`.
2. Revalida `TechniciansCompany__c` contra empresa TSP.
3. Revalida `WorkOrder__r.City` contra cidade permitida.
4. Executa PATCH em:

```text
ServiceAppointment.FSL__Pinned__c = fixado
```

Resposta de sucesso:

```json
{
  "request_id": "<uuid>",
  "success": true,
  "operation": "fixar",
  "id": "08p...",
  "numero_compromisso": "SA-...",
  "before": {
    "fixado": false
  },
  "after": {
    "fixado": true
  }
}
```

## Escrita: mudar tecnico

Endpoint:

```http
POST /api/v1/tsp/agendamentos/{id}/mudar-tecnico
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request mantendo horario:

```json
{
  "novo_tecnico_id": "0Hn...",
  "motivo": "Redistribuicao TSP"
}
```

Request mudando tecnico e horario:

```json
{
  "novo_tecnico_id": "0Hn...",
  "novo_inicio": "2026-07-07 15:00:00",
  "novo_fim": "2026-07-07 16:30:00",
  "fixado": true,
  "motivo": "Redistribuicao TSP"
}
```

Validacoes:

- `novo_tecnico_id`: obrigatorio, string, maximo 18;
- `novo_tecnico_nome`: opcional, string, maximo 255;
- `novo_inicio`: opcional, obrigatorio se `novo_fim` vier, formato `Y-m-d H:i:s`;
- `novo_fim`: opcional, obrigatorio se `novo_inicio` vier, formato `Y-m-d H:i:s`;
- `fixado`: opcional, boolean;
- `motivo`: opcional, string, maximo 1000;
- se horario for alterado, `novo_fim` deve ser maior que `novo_inicio`.

Pontos importantes:

- `novo_tecnico_nome` e aceito pelo controller, mas nao e usado pelo servico atual.
- `motivo` e salvo no log, mas nao e enviado ao Salesforce.

Fluxo Salesforce:

1. Consulta `ServiceAppointment`.
2. Revalida empresa/cidade do `ServiceAppointment`.
3. Consulta `ServiceResource` por `novo_tecnico_id`.
4. Exige `ServiceResource.IsActive = true`.
5. Exige `ServiceResource.ResourceCompany__c` igual a empresa TSP.
6. Consulta `AssignedResource` do `ServiceAppointment`.
7. Se existir um unico `AssignedResource`, faz PATCH trocando `ServiceResourceId`.
8. Se nao existir, cria `AssignedResource`.
9. Se vier horario ou `fixado`, tambem atualiza `ServiceAppointment` via Salesforce Composite.
10. Se houver mais de um `AssignedResource`, bloqueia a operacao.

Objetos/fields:

```text
AssignedResource.ServiceAppointmentId
AssignedResource.ServiceResourceId
ServiceAppointment.SchedStartTime
ServiceAppointment.SchedEndTime
ServiceAppointment.FSL__Pinned__c
```

Resposta inclui `before` e `after` com tecnico, resource id, horario e fixado.

## Escrita: mudar horario

Endpoint:

```http
POST /api/v1/tsp/agendamentos/{id}/mudar-horario
Authorization: Bearer <access_token>
Content-Type: application/json
```

Request:

```json
{
  "novo_inicio": "2026-07-07 12:00:00",
  "novo_fim": "2026-07-07 13:30:00",
  "fixado": true,
  "motivo": "Cliente pediu alteracao"
}
```

Validacoes:

- `novo_inicio`: obrigatorio, formato `Y-m-d H:i:s`;
- `novo_fim`: obrigatorio, formato `Y-m-d H:i:s`;
- `fixado`: opcional, boolean;
- `motivo`: opcional, string, maximo 1000;
- `novo_fim` deve ser maior que `novo_inicio`.

Fluxo Salesforce:

1. Consulta e revalida `ServiceAppointment`.
2. Converte datas do timezone configurado para ISO-8601.
3. PATCH em `ServiceAppointment`:

```text
SchedStartTime = novo_inicio convertido
SchedEndTime   = novo_fim convertido
FSL__Pinned__c = fixado, se enviado
```

## Escrita: suspender

Endpoint:

```http
POST /api/v1/tsp/agendamentos/{id}/suspender
Authorization: Bearer <access_token>
Content-Type: multipart/form-data
```

Request multipart:

```text
descricao = "Cliente ausente no local"
imagem    = @foto.jpg
motivo    = "Suspensao solicitada pela TSP"
```

Validacoes:

- `descricao`: obrigatorio, string, maximo 5000;
- `imagem`: obrigatoria, arquivo de imagem, maximo `TSP_MAX_IMAGE_KB`;
- `motivo`: opcional, string, maximo 1000.

Ponto importante: `motivo` e salvo no log, mas nao e enviado ao Salesforce.

Fluxo Salesforce:

1. Consulta e revalida `ServiceAppointment`.
2. Le `WorkOrder__r.CaseId`; se nao existir Case pai, bloqueia a operacao.
3. Monta HTML da nota com compromisso, tecnico, descricao e imagem embutida em base64.
4. Executa Salesforce Composite com `allOrNone = true`:

```text
PATCH ServiceAppointment.Status = "Suspensa"
POST  ContentNote.Title = "Suspensao TSP - {numero_compromisso}"
POST  ContentNote.Content = base64(html)
POST  ContentDocumentLink.LinkedEntityId = CaseId
POST  ContentDocumentLink.ContentDocumentId = @{SuspensionNote.id}
POST  ContentDocumentLink.ShareType = V
POST  ContentDocumentLink.Visibility = AllUsers
```

Resposta inclui `case_id`, status anterior/novo e ids da nota/link criados.

## Formato padrao de erro

Para erros gerados pelo controller:

```json
{
  "request_id": "<uuid>",
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "Dados invalidos.",
    "details": {
      "campo": ["mensagem"]
    }
  }
}
```

Codigos atuais:

- `validation_error`: entrada invalida, HTTP 422;
- `invalid_client`: client invalido, HTTP 401;
- `bad_request`: regra de negocio ou cursor invalido, HTTP 400;
- `token_error`: falha ao emitir token, HTTP 500;
- `next15_error`: falha na consulta de agenda, HTTP 500;
- `write_error`: falha na escrita, HTTP 500.

Erros do middleware de Bearer token hoje nao incluem `request_id`:

```json
{
  "error": {
    "code": "invalid_token",
    "message": "Token invalido ou expirado."
  }
}
```

Na API Java, e melhor padronizar todos os erros com `request_id`, inclusive middleware/filtro.

## Banco `dw_tsp`

Banco separado para autenticacao e auditoria da API TSP.

### `api_clients`

Uso: clients externos autorizados a pedir token.

Campos principais:

- `id`;
- `client_id`, unico;
- `name`;
- `secret_hash`;
- `scopes`, JSON;
- `active`;
- `token_ttl_minutes`;
- `last_used_at`;
- `created_at`, `updated_at`.

### `api_tokens`

Uso: tokens opacos emitidos.

Campos principais:

- `id`;
- `api_client_id`;
- `token_hash`, SHA-256 do token opaco, unico;
- `name`;
- `scopes`, JSON;
- `expires_at`;
- `revoked_at`;
- `last_used_at`;
- `created_at`, `updated_at`.

### `api_request_logs`

Uso: auditoria tecnica de todas as chamadas.

Campos principais:

- `request_id`, UUID unico;
- `api_client_id`;
- `endpoint`;
- `method`;
- `status_code`;
- `duration_ms`;
- `ip_hash`, SHA-256 do IP;
- `user_agent`;
- `error_code`;
- `metadata`;
- `created_at`.

### `write_operation_logs`

Uso: trilha de auditoria de alteracoes.

Campos principais:

- `api_client_id`;
- `request_id`;
- `operation`;
- `source_id`;
- `numero_compromisso`;
- `salesforce_id`;
- `status`, `success` ou `failed`;
- `error_code`;
- `request_payload`;
- `response_payload`;
- `created_at`.

Observacao: no codigo atual, `salesforce_id` recebe o mesmo valor de `source.id`.

### DDL MySQL compativel

Este DDL reproduz a estrutura criada pela migration Laravel. Para Java, ele pode virar uma
migration Flyway, por exemplo `V1__create_dw_tsp.sql`.

```sql
CREATE DATABASE IF NOT EXISTS `dw_tsp`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dw_tsp`;

CREATE TABLE `api_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `name` varchar(255) NULL,
  `secret_hash` varchar(255) NOT NULL,
  `scopes` json NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `token_ttl_minutes` smallint unsigned NOT NULL DEFAULT 60,
  `last_used_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_clients_client_id_unique` (`client_id`),
  KEY `api_clients_active_client_id_index` (`active`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_client_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `name` varchar(255) NULL,
  `scopes` json NULL,
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL,
  `last_used_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_tokens_token_hash_unique` (`token_hash`),
  KEY `api_tokens_api_client_id_expires_at_index` (`api_client_id`, `expires_at`),
  KEY `api_tokens_revoked_at_expires_at_index` (`revoked_at`, `expires_at`),
  CONSTRAINT `api_tokens_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_request_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` char(36) NOT NULL,
  `api_client_id` bigint unsigned NULL,
  `endpoint` varchar(128) NOT NULL,
  `method` varchar(16) NOT NULL,
  `status_code` smallint unsigned NOT NULL,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `ip_hash` varchar(64) NULL,
  `user_agent` varchar(512) NULL,
  `error_code` varchar(64) NULL,
  `metadata` json NULL,
  `created_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_request_logs_request_id_unique` (`request_id`),
  KEY `tsp_log_client_created_idx` (`api_client_id`, `created_at`),
  KEY `tsp_log_endpoint_created_idx` (`endpoint`, `created_at`),
  CONSTRAINT `api_request_logs_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `write_operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_client_id` bigint unsigned NULL,
  `request_id` varchar(36) NOT NULL,
  `operation` varchar(64) NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `numero_compromisso` varchar(255) NULL,
  `salesforce_id` varchar(255) NULL,
  `status` varchar(32) NOT NULL,
  `error_code` varchar(64) NULL,
  `request_payload` json NULL,
  `response_payload` json NULL,
  `created_at` timestamp NULL,
  PRIMARY KEY (`id`),
  KEY `tsp_write_operation_created_idx` (`operation`, `created_at`),
  KEY `tsp_write_source_created_idx` (`source_id`, `created_at`),
  KEY `write_operation_logs_api_client_id_foreign` (`api_client_id`),
  CONSTRAINT `write_operation_logs_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### SQL administrativo para criar client

Nao salvar `client_secret` em claro. O valor abaixo `:secret_hash` deve ser BCrypt/Argon2id
gerado pela aplicacao/CLI Java.

```sql
INSERT INTO api_clients (
  client_id,
  name,
  secret_hash,
  scopes,
  active,
  token_ttl_minutes,
  created_at,
  updated_at
) VALUES (
  :client_id,
  :name,
  :secret_hash,
  JSON_ARRAY('tsp:read', 'tsp:write'),
  1,
  :token_ttl_minutes,
  CURRENT_TIMESTAMP,
  CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  secret_hash = VALUES(secret_hash),
  scopes = VALUES(scopes),
  active = VALUES(active),
  token_ttl_minutes = VALUES(token_ttl_minutes),
  updated_at = CURRENT_TIMESTAMP;
```

### SQL de token

Buscar client ativo:

```sql
SELECT id, client_id, name, secret_hash, scopes, active, token_ttl_minutes
FROM api_clients
WHERE client_id = :client_id
  AND active = 1
LIMIT 1;
```

Inserir token emitido:

```sql
INSERT INTO api_tokens (
  api_client_id,
  token_hash,
  name,
  scopes,
  expires_at,
  created_at,
  updated_at
) VALUES (
  :api_client_id,
  :token_hash_sha256_hex,
  'tsp-api',
  :scopes_json,
  :expires_at,
  CURRENT_TIMESTAMP,
  CURRENT_TIMESTAMP
);
```

Autenticar Bearer token:

```sql
SELECT
  t.id AS token_id,
  t.api_client_id,
  t.scopes AS token_scopes,
  t.expires_at,
  c.client_id,
  c.name,
  c.scopes AS client_scopes,
  c.active
FROM api_tokens t
JOIN api_clients c ON c.id = t.api_client_id
WHERE t.token_hash = :token_hash_sha256_hex
  AND t.revoked_at IS NULL
  AND t.expires_at > CURRENT_TIMESTAMP
  AND c.active = 1
LIMIT 1;
```

Atualizar ultimo uso:

```sql
UPDATE api_tokens
SET last_used_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :token_id;

UPDATE api_clients
SET last_used_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :api_client_id;
```

Revogar token:

```sql
UPDATE api_tokens
SET revoked_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE token_hash = :token_hash_sha256_hex
  AND revoked_at IS NULL;
```

Limpar tokens expirados antigos:

```sql
DELETE FROM api_tokens
WHERE expires_at < (CURRENT_TIMESTAMP - INTERVAL 30 DAY);
```

### SQL de auditoria

Log de request:

```sql
INSERT INTO api_request_logs (
  request_id,
  api_client_id,
  endpoint,
  method,
  status_code,
  duration_ms,
  ip_hash,
  user_agent,
  error_code,
  metadata,
  created_at
) VALUES (
  :request_id,
  :api_client_id,
  :endpoint,
  :method,
  :status_code,
  :duration_ms,
  :ip_hash_sha256_hex,
  :user_agent,
  :error_code,
  :metadata_json,
  CURRENT_TIMESTAMP
);
```

Log de escrita:

```sql
INSERT INTO write_operation_logs (
  api_client_id,
  request_id,
  operation,
  source_id,
  numero_compromisso,
  salesforce_id,
  status,
  error_code,
  request_payload,
  response_payload,
  created_at
) VALUES (
  :api_client_id,
  :request_id,
  :operation,
  :source_id,
  :numero_compromisso,
  :salesforce_id,
  :status,
  :error_code,
  :request_payload_json,
  :response_payload_json,
  CURRENT_TIMESTAMP
);
```

## Salesforce

Esta e a parte mais importante para a API Java conseguir escrever. A API TSP nao grava direto
na tabela local de agenda. Ela consulta a tabela local para validar escopo e depois escreve no
Salesforce pelos objetos abaixo.

### Configuracao Salesforce

```text
SALESFORCE_DOMAIN=<https://...my.salesforce.com>
SALESFORCE_CLIENT_ID=<secret>
SALESFORCE_CLIENT_SECRET=<secret>
SALESFORCE_API_VERSION=v60.0
```

Base REST depois de autenticar:

```text
{instance_url}/services/data/{SALESFORCE_API_VERSION}
```

Objetos usados:

```text
ServiceAppointment
AssignedResource
ServiceResource
ContentNote
ContentDocumentLink
```

### Autenticacao OAuth2 Client Credentials

Request:

```http
POST {SALESFORCE_DOMAIN}/services/oauth2/token
Content-Type: application/x-www-form-urlencoded
```

Body:

```text
grant_type=client_credentials
client_id=<SALESFORCE_CLIENT_ID>
client_secret=<SALESFORCE_CLIENT_SECRET>
```

Resposta esperada:

```json
{
  "access_token": "<salesforce-access-token>",
  "instance_url": "https://...my.salesforce.com",
  "token_type": "Bearer",
  "issued_at": "...",
  "signature": "..."
}
```

Na API Java:

- cachear esse token em memoria ate expirar ou ate receber 401 do Salesforce;
- nao gravar `access_token`, `client_secret` ou resposta completa em log;
- usar timeout curto na chamada de token;
- usar circuit breaker para nao travar a API se Salesforce cair.

### Como executar SOQL via REST

Endpoint:

```http
GET {instance_url}/services/data/{SALESFORCE_API_VERSION}/query?q=<SOQL_URL_ENCODED>
Authorization: Bearer <salesforce-access-token>
Accept: application/json
```

Exemplo de URL montada:

```text
/services/data/v60.0/query?q=SELECT%20Id%20FROM%20ServiceAppointment%20WHERE%20Id%20%3D%20%2708p...%27%20LIMIT%201
```

Resposta padrao de query:

```json
{
  "totalSize": 1,
  "done": true,
  "records": [
    {
      "attributes": {
        "type": "ServiceAppointment",
        "url": "/services/data/v60.0/sobjects/ServiceAppointment/08p..."
      },
      "Id": "08p..."
    }
  ]
}
```

### Escape e validacao antes de SOQL

O codigo Laravel atual escapa `\` e `'`:

```text
\  -> \\
'  -> \'
```

Para Java, alem do escape, validar formato dos ids Salesforce:

```regex
^[a-zA-Z0-9]{15,18}$
```

Para `numero_compromisso`, nao usar diretamente em SOQL no fluxo atual. Primeiro resolver na
tabela local e usar o `id` retornado da fonte.

### SOQL 1 - buscar e validar ServiceAppointment

Usado por todas as escritas (`fixar`, `mudar_horario`, `mudar_tecnico`, `suspender`).

```sql
SELECT
  Id,
  AppointmentNumber,
  Status,
  TechniciansCompany__c,
  TechnicianName__c,
  SchedStartTime,
  SchedEndTime,
  FSL__Pinned__c,
  WorkOrder__c,
  WorkOrder__r.CaseId,
  WorkOrder__r.City
FROM ServiceAppointment
WHERE Id = '{service_appointment_id}'
LIMIT 1
```

Validacoes apos a query:

```text
records.length precisa ser 1
TechniciansCompany__c normalizado precisa ser TSP SERVICOS ELETRICOS LTDA
WorkOrder__r.City normalizado precisa ser Sorocaba
```

Se falhar:

```text
ServiceAppointment nao encontrado no Salesforce.
ServiceAppointment no Salesforce nao pertence a TSP.
ServiceAppointment no Salesforce nao pertence a cidade permitida.
```

Campos usados no retorno `before`:

```text
Status
TechnicianName__c
SchedStartTime
SchedEndTime
FSL__Pinned__c
WorkOrder__r.CaseId
```

### SOQL 2 - buscar AssignedResource atual

Usado em `mudar_tecnico`.

```sql
SELECT
  Id,
  ServiceAppointmentId,
  ServiceResourceId,
  ServiceResource.Name
FROM AssignedResource
WHERE ServiceAppointmentId = '{service_appointment_id}'
```

Validacoes:

```text
0 registros -> criar AssignedResource novo
1 registro  -> atualizar AssignedResource existente
2+ registros -> bloquear operacao
```

Erro para 2+ registros:

```text
Agendamento possui multiplos recursos atribuidos; operacao bloqueada.
```

### SOQL 3 - buscar e validar novo tecnico

Usado em `mudar_tecnico`.

```sql
SELECT
  Id,
  Name,
  IsActive,
  ResourceCompany__c
FROM ServiceResource
WHERE Id = '{service_resource_id}'
LIMIT 1
```

Validacoes:

```text
records.length precisa ser 1
IsActive precisa ser true
ResourceCompany__c normalizado precisa ser TSP SERVICOS ELETRICOS LTDA
```

Erros equivalentes:

```text
Tecnico nao encontrado no Salesforce.
Tecnico inativo no Salesforce.
Tecnico nao pertence a TSP.
```

### SOQL opcional - listar tecnicos TSP ativos para teste/admin

Nao existe endpoint atual para isso, mas ajuda na criacao e teste da API Java.

```sql
SELECT
  Id,
  Name,
  IsActive,
  ResourceCompany__c
FROM ServiceResource
WHERE IsActive = true
  AND ResourceCompany__c = 'TSP SERVICOS ELETRICOS LTDA'
ORDER BY Name
LIMIT 200
```

Se a comparacao exata por acento/capitalizacao falhar no ambiente, fazer a filtragem final no
Java com a normalizacao da API.

### REST 1 - fixar/desfixar ServiceAppointment

Operacao da rota:

```text
POST /api/v1/tsp/agendamentos/{id}/fixar
```

Depois da query `SOQL 1`, executar:

```http
PATCH {instance_url}/services/data/{SALESFORCE_API_VERSION}/sobjects/ServiceAppointment/{service_appointment_id}
Authorization: Bearer <salesforce-access-token>
Content-Type: application/json
```

Body:

```json
{
  "FSL__Pinned__c": true
}
```

Status esperado:

```text
204 No Content
```

### REST 2 - mudar horario do ServiceAppointment

Operacao da rota:

```text
POST /api/v1/tsp/agendamentos/{id}/mudar-horario
```

Converter datas recebidas no timezone `America/Sao_Paulo` para ISO-8601 antes de enviar.

Exemplo:

```text
2026-07-07 12:00:00 -> 2026-07-07T12:00:00-03:00
```

Request Salesforce:

```http
PATCH {instance_url}/services/data/{SALESFORCE_API_VERSION}/sobjects/ServiceAppointment/{service_appointment_id}
Authorization: Bearer <salesforce-access-token>
Content-Type: application/json
```

Body sem `fixado`:

```json
{
  "SchedStartTime": "2026-07-07T12:00:00-03:00",
  "SchedEndTime": "2026-07-07T13:30:00-03:00"
}
```

Body com `fixado`:

```json
{
  "SchedStartTime": "2026-07-07T12:00:00-03:00",
  "SchedEndTime": "2026-07-07T13:30:00-03:00",
  "FSL__Pinned__c": true
}
```

Status esperado:

```text
204 No Content
```

### REST 3 - mudar tecnico com Salesforce Composite

Operacao da rota:

```text
POST /api/v1/tsp/agendamentos/{id}/mudar-tecnico
```

Endpoint:

```http
POST {instance_url}/services/data/{SALESFORCE_API_VERSION}/composite
Authorization: Bearer <salesforce-access-token>
Content-Type: application/json
```

Caso exista `AssignedResource`, atualizar:

```json
{
  "allOrNone": true,
  "compositeRequest": [
    {
      "method": "PATCH",
      "url": "/services/data/v60.0/sobjects/AssignedResource/{assigned_resource_id}",
      "referenceId": "UpdateAssignedResource",
      "body": {
        "ServiceResourceId": "{novo_service_resource_id}"
      }
    }
  ]
}
```

Caso nao exista `AssignedResource`, criar:

```json
{
  "allOrNone": true,
  "compositeRequest": [
    {
      "method": "POST",
      "url": "/services/data/v60.0/sobjects/AssignedResource",
      "referenceId": "CreateAssignedResource",
      "body": {
        "ServiceAppointmentId": "{service_appointment_id}",
        "ServiceResourceId": "{novo_service_resource_id}"
      }
    }
  ]
}
```

Se tambem vier horario e/ou `fixado`, adicionar uma segunda parte ao composite:

```json
{
  "method": "PATCH",
  "url": "/services/data/v60.0/sobjects/ServiceAppointment/{service_appointment_id}",
  "referenceId": "UpdateServiceAppointment",
  "body": {
    "SchedStartTime": "2026-07-07T15:00:00-03:00",
    "SchedEndTime": "2026-07-07T16:30:00-03:00",
    "FSL__Pinned__c": true
  }
}
```

Composite completo com AssignedResource existente + horario:

```json
{
  "allOrNone": true,
  "compositeRequest": [
    {
      "method": "PATCH",
      "url": "/services/data/v60.0/sobjects/AssignedResource/{assigned_resource_id}",
      "referenceId": "UpdateAssignedResource",
      "body": {
        "ServiceResourceId": "{novo_service_resource_id}"
      }
    },
    {
      "method": "PATCH",
      "url": "/services/data/v60.0/sobjects/ServiceAppointment/{service_appointment_id}",
      "referenceId": "UpdateServiceAppointment",
      "body": {
        "SchedStartTime": "2026-07-07T15:00:00-03:00",
        "SchedEndTime": "2026-07-07T16:30:00-03:00",
        "FSL__Pinned__c": true
      }
    }
  ]
}
```

Resposta esperada:

```json
{
  "compositeResponse": [
    {
      "body": null,
      "httpHeaders": {},
      "httpStatusCode": 204,
      "referenceId": "UpdateAssignedResource"
    },
    {
      "body": null,
      "httpHeaders": {},
      "httpStatusCode": 204,
      "referenceId": "UpdateServiceAppointment"
    }
  ]
}
```

Regra de sucesso:

```text
Todas as partes do composite precisam ter httpStatusCode entre 200 e 299.
```

### REST 4 - suspender com Salesforce Composite

Operacao da rota:

```text
POST /api/v1/tsp/agendamentos/{id}/suspender
```

Pre-condicao:

```text
SOQL 1 precisa retornar WorkOrder__r.CaseId.
```

Se nao houver Case:

```text
Agendamento sem Case pai para criar nota.
```

Endpoint:

```http
POST {instance_url}/services/data/{SALESFORCE_API_VERSION}/composite
Authorization: Bearer <salesforce-access-token>
Content-Type: application/json
```

Body:

```json
{
  "allOrNone": true,
  "compositeRequest": [
    {
      "method": "PATCH",
      "url": "/services/data/v60.0/sobjects/ServiceAppointment/{service_appointment_id}",
      "referenceId": "SuspendServiceAppointment",
      "body": {
        "Status": "Suspensa"
      }
    },
    {
      "method": "POST",
      "url": "/services/data/v60.0/sobjects/ContentNote",
      "referenceId": "SuspensionNote",
      "body": {
        "Title": "Suspensao TSP - {numero_compromisso}",
        "Content": "{base64_do_html_da_nota}"
      }
    },
    {
      "method": "POST",
      "url": "/services/data/v60.0/sobjects/ContentDocumentLink",
      "referenceId": "SuspensionNoteLink",
      "body": {
        "LinkedEntityId": "{case_id}",
        "ContentDocumentId": "@{SuspensionNote.id}",
        "ShareType": "V",
        "Visibility": "AllUsers"
      }
    }
  ]
}
```

Resposta esperada:

```json
{
  "compositeResponse": [
    {
      "httpStatusCode": 204,
      "referenceId": "SuspendServiceAppointment"
    },
    {
      "body": {
        "id": "069...",
        "success": true,
        "errors": []
      },
      "httpStatusCode": 201,
      "referenceId": "SuspensionNote"
    },
    {
      "body": {
        "id": "06A...",
        "success": true,
        "errors": []
      },
      "httpStatusCode": 201,
      "referenceId": "SuspensionNoteLink"
    }
  ]
}
```

Regra de sucesso:

```text
Todas as partes do composite precisam ter httpStatusCode entre 200 e 299.
```

### HTML da nota de suspensao

O Laravel cria HTML e envia esse HTML em base64 no campo `ContentNote.Content`.

Modelo logico:

```html
<div style="font-family: Arial, sans-serif; line-height: 1.45; color: #1f2933;">
  <h2 style="margin: 0 0 12px 0; font-size: 18px;">Suspensao TSP</h2>
  <p style="margin: 0 0 8px 0;"><strong>Compromisso:</strong> {numero_compromisso}</p>
  <p style="margin: 0 0 14px 0;"><strong>Tecnico:</strong> {nome_tecnico}</p>
  <div style="margin: 0 0 14px 0;">
    <strong>Descricao:</strong><br />
    {descricao_com_quebras_convertidas_para_br}
  </div>
  <figure style="margin: 0; padding: 12px; border: 1px solid #d8dde6; border-radius: 6px; background: #f8fafc; display: inline-block;">
    <img src="data:{mime};base64,{imagem_base64}" alt="{nome_arquivo}" style="display: block; width: 520px; max-width: 100%; height: auto;" />
    <figcaption style="margin-top: 8px; font-size: 12px; color: #5f6b7a;">Imagem: {nome_arquivo}</figcaption>
  </figure>
</div>
```

Regras de seguranca para montar:

- escapar HTML em `numero_compromisso`, `nome_tecnico`, `descricao` e `nome_arquivo`;
- validar o MIME real da imagem;
- limitar tamanho por `TSP_MAX_IMAGE_KB`;
- nao gravar `{imagem_base64}` em log;
- considerar anexar imagem separada em uma evolucao futura, porque embutir base64 aumenta o
  tamanho da nota.

### Tabela de operacao para Salesforce

| Operacao API | SOQL antes | REST/Composite | Objeto alterado |
| --- | --- | --- | --- |
| `fixar` | SOQL 1 | PATCH sObject | `ServiceAppointment.FSL__Pinned__c` |
| `mudar_horario` | SOQL 1 | PATCH sObject | `ServiceAppointment.SchedStartTime`, `SchedEndTime`, opcional `FSL__Pinned__c` |
| `mudar_tecnico` | SOQL 1, SOQL 2, SOQL 3 | Composite | `AssignedResource.ServiceResourceId`, opcional `ServiceAppointment` |
| `suspender` | SOQL 1 | Composite | `ServiceAppointment.Status`, `ContentNote`, `ContentDocumentLink` |

### Tratamento de erro Salesforce

O Laravel atual transforma qualquer erro Salesforce inesperado em `500 write_error`, mas grava
falha no log de escrita quando ja conseguiu resolver o agendamento local.

Na API Java, retornar para o client uma mensagem generica:

```json
{
  "request_id": "<uuid>",
  "success": false,
  "error": {
    "code": "write_error",
    "message": "Falha ao executar operacao TSP.",
    "details": null
  }
}
```

No log interno, guardar somente:

```text
request_id
operation
salesforce_http_status
salesforce_error_code, se houver
referenceId, se for composite
```

Nao gravar:

```text
Authorization
access_token
client_secret
imagem_base64
resposta Salesforce completa se contiver dados sensiveis
```

## Modo demo

Configuracao:

```env
TSP_DEMO_MODE=true
```

Com modo demo ativo:

- listagem retorna agendamentos ficticios;
- escritas simulam sucesso;
- nao chama Salesforce;
- autenticacao, validacao, rate limit e logs continuam passando pelas mesmas rotas.

O ambiente local analisado estava com `TSP_DEMO_MODE=true`. Para fluxo real:

```env
TSP_DEMO_MODE=false
```

Se a configuracao estiver cacheada no Laravel:

```bash
php artisan config:clear
```

Na API Java, manter um modo demo pode ser util, mas deve ficar bloqueado por profile/ambiente e
jamais ser ativado acidentalmente em producao.

## Variaveis de ambiente

Nao colocar valores reais neste arquivo.

```env
TSP_DB_CONNECTION=tsp
TSP_DB_HOST=<host>
TSP_DB_PORT=3306
TSP_DB_DATABASE=dw_tsp
TSP_DB_USERNAME=<usuario>
TSP_DB_PASSWORD=<secret>
TSP_DB_SOCKET=

TSP_SOURCE_CONNECTION=melhoria_continua
TSP_SOURCE_TABLE=agendamentos_geovane
TSP_FILTER_EMPRESA_TECNICO=TSP SERVICOS ELETRICOS LTDA
TSP_FILTER_CIDADE=Sorocaba
TSP_COMPANY_CNPJ=<opcional>
TSP_TIMEZONE=America/Sao_Paulo
TSP_DEMO_MODE=false
TSP_TOKEN_TTL_MINUTES=60
TSP_PAGE_SIZE_DEFAULT=500
TSP_PAGE_SIZE_MAX=2000
TSP_MAX_IMAGE_KB=2048

SALESFORCE_DOMAIN=<https://...my.salesforce.com>
SALESFORCE_CLIENT_ID=<secret>
SALESFORCE_CLIENT_SECRET=<secret>
SALESFORCE_API_VERSION=v60.0
```

Para Java, preferir secret manager/cofre em vez de `.env` com segredo em disco.

## Controles de seguranca existentes

O que ja existe hoje:

- `client_secret` nao e salvo em claro, apenas hash.
- Access token e opaco, gerado com entropia forte.
- Access token nao e salvo em claro, apenas SHA-256.
- Tokens possuem expiracao e podem ser revogados por `revoked_at`.
- Clients podem ser desativados por `active = false`.
- Escopos separam leitura e escrita.
- Rate limit especifico para token e API.
- Logs guardam hash do IP, nao IP em claro.
- Logs de escrita nao salvam conteudo binario da imagem, apenas nome, tamanho e MIME.
- Toda resposta controlada pelo controller recebe `request_id`.
- Escritas validam fonte local antes de chamar Salesforce.
- Escritas revalidam empresa/cidade no Salesforce.
- Novo tecnico precisa estar ativo e pertencer a TSP.
- Operacao bloqueia agendamento com multiplos recursos atribuidos.
- Datas de alteracao de horario exigem fim maior que inicio.
- Campos incluidos na nota HTML passam por escaping.
- SOQL de ids faz escape basico.
- Headers de seguranca sao adicionados pelo middleware `SecurityHeaders`.

## Pontos para endurecer na API Java

Recomendacoes para uma implementacao 100% focada em seguranca:

1. Usar HTTPS obrigatorio, HSTS no proxy e TLS moderno.
2. Considerar mTLS entre cliente TSP e API, alem do Bearer token.
3. Aplicar allowlist de IP por client quando possivel.
4. Guardar credenciais Salesforce e secrets de client em cofre/KMS, nao em arquivo local.
5. Separar clients por permissao: um client so leitura e outro escrita, se operacionalmente possivel.
6. Reduzir TTL de token para escrita, por exemplo 10 a 15 minutos, se a TSP suportar.
7. Implementar revogacao e limpeza periodica de tokens expirados.
8. Assinar `page_token` com HMAC ou fazer cursor server-side.
9. Validar formato dos ids Salesforce com regex antes de montar SOQL.
10. Usar client Salesforce com timeout curto, retry controlado, circuit breaker e sem logar body sensivel.
11. Sanitizar mensagens de erro do Salesforce antes de gravar/retornar.
12. Implementar `Idempotency-Key` nos endpoints de escrita para evitar duplicidade em retry.
13. Persistir auditoria de escrita de forma append-only, com retencao e controle de acesso.
14. Nao registrar `Authorization`, access token, client secret, imagem em base64 ou credenciais em log.
15. Fazer validacao real de imagem por conteudo, nao so extensao/MIME informado.
16. Definir limite de dimensao de imagem e, se necessario, antivirus/sandbox para upload.
17. Restringir CORS ou desabilitar CORS se a API for service-to-service.
18. Criar usuario de banco read-only para a fonte de agendamentos.
19. Criar usuario separado para `dw_tsp` com permissoes minimas.
20. Padronizar todo erro com `request_id`, inclusive filtro de autenticacao.
21. Adicionar metricas e alertas para 401/403/429/5xx e falhas Salesforce.
22. Usar OpenAPI como contrato congelado antes do cutover.

## Arquitetura sugerida em Java

Uma implementacao Spring Boot segura poderia ser organizada assim:

```text
com.empresa.tspapi
  config
    SecurityConfig
    RateLimitConfig
    SalesforceConfig
    DatabaseConfig
  auth
    TspAuthController
    BearerTokenFilter
    TokenService
    ClientSecretHasher
  appointments
    TspAppointmentController
    AppointmentQueryService
    AppointmentMapper
    SourceAppointmentRepository
  writes
    TspWriteController
    TspWriteService
    WriteAuditService
    IdempotencyService
  salesforce
    SalesforceClient
    SalesforceTokenProvider
    SalesforceModels
  audit
    RequestAuditFilter
    RequestLogRepository
  demo
    TspDemoService
  common
    ErrorResponse
    RequestIdFilter
    TextNormalizer
    ClockProvider
```

Bibliotecas recomendadas:

- Spring Boot 3;
- Spring Security;
- JDBC/JPA ou jOOQ para queries tipadas;
- Flyway ou Liquibase para schema;
- Resilience4j para timeout/retry/circuit breaker;
- Bucket4j ou rate limit no gateway/API gateway;
- Micrometer para metricas;
- OpenAPI/Swagger para contrato.

## Fluxo Java recomendado por camada

### Filtro de request

1. Gerar `request_id` se nao vier de um header confiavel.
2. Colocar `request_id` no MDC/log context.
3. Bloquear body acima do limite.
4. Aplicar rate limit.
5. Para rotas protegidas, validar Bearer token e escopos.
6. Ao finalizar, gravar `api_request_logs`.

### TokenService

1. Buscar client por `client_id`.
2. Exigir `active = true`.
3. Verificar secret com algoritmo forte, por exemplo Argon2id ou BCrypt.
4. Gerar token opaco com CSPRNG.
5. Salvar SHA-256 do token, scopes e expiracao.
6. Retornar token em claro somente na resposta.

### AppointmentQueryService

1. Calcular janela no timezone configurado.
2. Validar e decodificar cursor.
3. Consultar fonte read-only com parametros bindados.
4. Aplicar filtro normalizado TSP/Sorocaba.
5. Ordenar por `data_agendamento`, `id`.
6. Retornar DTO no mesmo formato atual.

### TspWriteService

1. Validar request.
2. Resolver `{id}` na fonte local por `id` ou `numero_compromisso`.
3. Exigir escopo TSP/Sorocaba na fonte local.
4. Consultar Salesforce.
5. Revalidar escopo no Salesforce.
6. Executar mudanca.
7. Gravar auditoria de sucesso/falha.
8. Retornar `before` e `after`.

## Plano de migracao recomendado

1. Congelar contrato atual em OpenAPI, incluindo exemplos de sucesso e erro.
2. Criar API Java isolada em novo repositorio/projeto.
3. Recriar schema `dw_tsp` com Flyway/Liquibase, mantendo compatibilidade de dados.
4. Implementar primeiro token e `GET proximos-15-dias`.
5. Comparar respostas Laravel vs Java em modo read-only, sem escrever no Salesforce.
6. Implementar Salesforce client usando sandbox ou credencial de menor privilegio.
7. Implementar cada escrita atras de feature flag.
8. Adicionar `Idempotency-Key` nas escritas antes de liberar producao.
9. Fazer testes de carga e rate limit.
10. Fazer revisao de seguranca: secrets, logs, TLS, CORS, permissoes DB, permissoes Salesforce.
11. Publicar Java em URL nova e rodar shadow traffic de leitura.
12. Virar DNS/gateway para Java quando leitura e escrita estiverem validadas.
13. Manter Laravel TSP em modo fallback por um periodo curto.
14. Remover rotas TSP do Laravel quando o novo servico estiver estavel.

## Checklist de testes obrigatorios

- token valido retorna Bearer e expiracao correta;
- `client_id` invalido retorna 401;
- `client_secret` invalido retorna 401;
- client inativo nao autentica;
- token expirado retorna 401;
- token revogado retorna 401;
- token sem escopo retorna 403;
- endpoint sem Bearer retorna 401;
- rate limit retorna 429;
- `page_size` acima do maximo retorna 422;
- `page_token` invalido retorna 400;
- listagem nunca retorna empresa/cidade fora de TSP/Sorocaba;
- `{id}` aceita `id` e `numero_compromisso`;
- escrita em agendamento fora do escopo retorna 400;
- `mudar_horario` rejeita fim menor ou igual ao inicio;
- `mudar_tecnico` rejeita tecnico inativo;
- `mudar_tecnico` rejeita tecnico fora da TSP;
- `mudar_tecnico` bloqueia multiplos AssignedResource;
- `suspender` rejeita sem imagem;
- `suspender` rejeita imagem acima do limite;
- `suspender` rejeita agendamento sem Case pai;
- falha Salesforce nao vaza segredo no response/log;
- logs de request sao gravados com hash de IP;
- logs de escrita nao salvam imagem em base64;
- idempotencia evita duplicar operacao em retry;
- modo demo nao fica ativo em producao.

## Decisoes de compatibilidade para confirmar antes de codar Java

- Manter `motivo` apenas em log ou passar a registrar esse motivo no Salesforce?
- Remover `novo_tecnico_nome` do contrato ou usar somente para auditoria?
- Manter token opaco em banco ou trocar para JWT assinado com revogacao complementar?
- Manter `page_token` compativel com o Laravel ou trocar para cursor assinado?
- Manter `TSP_TOKEN_TTL_MINUTES=60` ou reduzir TTL?
- Manter CORS aberto para o frontend atual ou tratar a TSP como API service-to-service sem CORS?
- Manter `ContentNote` com imagem embutida em HTML ou anexar imagem como arquivo separado?

## Resumo para implementacao Java

O comportamento minimo a preservar e:

- mesmos endpoints e mesmos campos JSON;
- autenticacao por `client_id/client_secret` gerando Bearer token;
- token opaco salvo apenas como hash;
- escopos `tsp:read` e `tsp:write`;
- filtro forte TSP/Sorocaba na fonte local e no Salesforce;
- listagem paginada dos proximos 15 dias;
- escritas em `ServiceAppointment`, `AssignedResource`, `ContentNote` e `ContentDocumentLink`;
- auditoria de request e auditoria de escrita;
- sem secrets ou tokens em logs.

O que vale melhorar na separacao:

- cursor assinado;
- idempotencia nas escritas;
- mTLS/allowlist;
- secret manager;
- permissoes minimas de banco e Salesforce;
- erro padronizado com `request_id` em todos os casos;
- validacao mais forte de upload de imagem;
- testes automatizados cobrindo seguranca e regras de escopo.
