# API Moavi

API externa versionada em `/api/v1/moavi`.

## Regra de banco

A API nao altera tabelas existentes.

- Fonte somente leitura: `db_Melhoria_continua_operacoes.agendamentos_geovane`
- Banco novo da API: `dw_moavi`

Crie apenas o banco novo:

```sql
SOURCE database/sql/dw_moavi_schema.sql;
```

Depois cadastre o client:

```bash
php artisan moavi:client moavi --name="Moavi" --cnpj="08.170.849/0053-46"
```

O comando imprime o `client_secret` uma unica vez.

## Token

`POST /api/v1/moavi/auth/token`

```json
{
  "client_id": "moavi",
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
  "expires_at": "2026-06-26T12:00:00-03:00",
  "scope": "moavi:read moavi:changes"
}
```

Use o token nas demais rotas:

```http
Authorization: Bearer <access_token>
```

## Periodo

`GET /api/v1/moavi/agendamentos?inicio=2026-01-01&fim=2026-01-30&page_size=500`

Regras:

- `inicio` e `fim` em `YYYY-MM-DD`
- `inicio >= 2026-01-01`
- `fim >= inicio`
- janela maxima inclusiva de 30 dias
- registros sem `data_agendamento` nao entram

A resposta retorna `sync_key`, paginação e somente os campos contratados abaixo.

Cada item retorna somente:

```json
{
  "cnpj_empresa": "08.170.849/0053-46",
  "claseter": "PRAIA GRANDE",
  "id": "...",
  "numero_compromisso": "...",
  "dt_abertura": "2026-06-01 08:00:00",
  "data_primeiro_agendamento": "...",
  "tipo_trabalho": "Manutenção",
  "inicio_servico": "2026-06-01 09:00:00",
  "termino_servico": "2026-06-01 10:00:00",
  "status": "Concluída"
}
```

Filtros fixos aplicados na fonte:

- `micro_territorio = Praia Grande`
- `empresa_tecnico = Desktop`

Normalizacao de `status`:

- `Concluída` -> `concluido`
- `Canceled` -> `cancelado`
- `Agendado`, `Suspensa`, `Em deslocamento`, `Chegada no Local`, `Despachado`, `On Hold`, `Em execução` -> `agendado`

## Proximos 15 dias

`GET /api/v1/moavi/agendamentos/proximos-15-dias?page_size=500`

Usa `America/Sao_Paulo` e retorna de hoje ate hoje + 15 dias com os mesmos campos do item acima.

## Alteracoes

`POST /api/v1/moavi/agendamentos/alteracoes`

```json
{
  "sync_key": "msk_...",
  "page_size": 500,
  "page_token": null
}
```

Retorna somente:

- `created`: novo registro na janela
- `updated`: registro existente com hash diferente
- `removed`: registro removido da janela ou apagado da fonte

As alteracoes `created` e `updated` retornam o objeto `item` com os mesmos campos do item acima, incluindo `inicio_servico`, `termino_servico` e `claseter`.

Quando `pagination.has_more` for `false`, grave o `next_sync_key` para a proxima sincronizacao.
