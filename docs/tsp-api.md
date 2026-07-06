# API TSP

API externa versionada em `/api/v1/tsp`.

## Regra de escopo

Todas as consultas e escritas ficam limitadas a:

- `empresa_tecnico = TSP SERVICOS ELETRICOS LTDA`
- `cidade = Sorocaba`

A fonte de leitura e validacao e somente leitura:

- `db_Melhoria_continua_operacoes.agendamentos_geovane`

As operacoes de escrita alteram o Salesforce apenas depois dessa validacao local.

## Banco da API

Crie o banco:

```sql
SOURCE database/sql/dw_tsp_schema.sql;
```

Rode a migration:

```bash
php artisan migrate --database=tsp --path=database/migrations/2026_06_30_000100_create_dw_tsp_tables.php
```

Cadastre o client:

```bash
php artisan tsp:client tsp --name="TSP"
```

O comando imprime o `client_secret` uma unica vez.

## Modo demonstracao

Enquanto o servidor nao puder consultar o Salesforce, ligue:

```env
TSP_DEMO_MODE=true
```

Com essa flag ativa:

- `GET /agendamentos/proximos-15-dias` retorna agendamentos ficticios no mesmo formato da API real
- `fixar`, `mudar-tecnico`, `mudar-horario` e `suspender` simulam sucesso sem chamar Salesforce
- a autenticacao, validacoes, rate limit e logs continuam passando pelas mesmas rotas

Para voltar ao fluxo real:

```env
TSP_DEMO_MODE=false
```

Se a config estiver cacheada, rode:

```bash
php artisan config:clear
```

## Token

`POST /api/v1/tsp/auth/token`

```json
{
  "client_id": "tsp",
  "client_secret": "..."
}
```

Resposta:

```json
{
  "request_id": "...",
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "expires_at": "2026-06-30T12:00:00-03:00",
  "scope": "tsp:read tsp:write"
}
```

Use nas demais rotas:

```http
Authorization: Bearer <access_token>
```

## Agenda proximos 15 dias

`GET /api/v1/tsp/agendamentos/proximos-15-dias?page_size=500`

Retorna de hoje ate hoje + 15 dias, usando `America/Sao_Paulo`.

Exemplo de item:

```json
{
  "empresa_api": "TSP",
  "id": "08pV200000P0QgrIAF",
  "numero_compromisso": "SA-2928813",
  "data_agendamento": "2026-06-30 07:00:00",
  "data_ultima_modificacao": "2026-06-30 01:01:26",
  "nome_tecnico": "HAMZA FATNASSI",
  "empresa_tecnico": "TSP SERVICOS ELETRICOS LTDA",
  "cidade": "Sorocaba",
  "micro_territorio": "BASE SOROCABA",
  "territorio_servico": "SOR003",
  "inicio_janela_chegada": "2026-06-30 07:00:00",
  "termino_janela_chegada": "2026-06-30 13:00:00",
  "inicio_agendado": "2026-07-03 10:39:00",
  "termino_agendado": "2026-07-03 11:54:00",
  "tipo_trabalho": "Ativacao",
  "subtipo_trabalho": "Internet",
  "status": "agendado",
  "fixado": false
}
```

## Fixar servico

`POST /api/v1/tsp/agendamentos/{id}/fixar`

`{id}` aceita `id` Salesforce da SA ou `numero_compromisso`.

```json
{
  "fixado": true,
  "motivo": "Fixado pela TSP"
}
```

Atualiza `ServiceAppointment.FSL__Pinned__c`.

## Mudar tecnico

`POST /api/v1/tsp/agendamentos/{id}/mudar-tecnico`

Mantendo horario:

```json
{
  "novo_tecnico_id": "0HnV20000004DOTKA2",
  "motivo": "Redistribuicao TSP"
}
```

Mudando tecnico e horario:

```json
{
  "novo_tecnico_id": "0HnV20000004DOTKA2",
  "novo_inicio": "2026-06-30 15:00:00",
  "novo_fim": "2026-06-30 16:30:00",
  "fixado": true,
  "motivo": "Redistribuicao TSP"
}
```

Valida que o `ServiceResource` esta ativo e pertence a `TSP SERVICOS ELETRICOS LTDA`.

## Mudar horario

`POST /api/v1/tsp/agendamentos/{id}/mudar-horario`

```json
{
  "novo_inicio": "2026-06-30 12:00:00",
  "novo_fim": "2026-06-30 13:30:00",
  "fixado": true,
  "motivo": "Cliente pediu alteracao"
}
```

Atualiza `ServiceAppointment.SchedStartTime` e `ServiceAppointment.SchedEndTime`.

## Suspender

`POST /api/v1/tsp/agendamentos/{id}/suspender`

Use `multipart/form-data`:

```http
descricao="Cliente ausente no local"
imagem=@foto.jpg
motivo="Suspensao solicitada pela TSP"
```

A operacao:

- atualiza `ServiceAppointment.Status = Suspensa`
- cria `ContentNote` formatada no Case pai
- inclui a imagem dentro do corpo da nota
- vincula a nota ao Case com `ContentDocumentLink`
