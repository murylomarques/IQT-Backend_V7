WITH mapa_alias_raw AS (
    SELECT 'JOAO AMORIM NETO' AS login_tecnico, '4252' AS usuario

    UNION ALL SELECT 'MAYCON LUCAS AMORIN LIMA', '13188'
    UNION ALL SELECT 'CARLOS EDUARDO SANTOS', '9437'
    UNION ALL SELECT 'ANTONIO CARLOS VIANA OLIVEIRA', '1760'
    UNION ALL SELECT 'JADSON GARCIA MIRANDA OLIVEIRA', '12880'
    UNION ALL SELECT 'ROGER MAXISCUEL RODRIGUES DA SILVA', '13460'
    UNION ALL SELECT 'EVERTON LEMOS DA SILVA BONAITA ANDRADE', '11272'
    UNION ALL SELECT 'JOSE MARCOS F. DOS SANTOS', '2151'
    UNION ALL SELECT 'WELITON LEONARDO SANTOS CONCEICAO', '13540'
    UNION ALL SELECT 'ANDERSON DA COSTA LEITE', '12911'
),

mapa_alias AS (
    SELECT
        CAST(
            UPPER(TRIM(login_tecnico))
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS login_key,

        CAST(
            TRIM(usuario)
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS usuario_key

    FROM mapa_alias_raw
),

usuarios_fca AS (
    SELECT
        fu.id,

        CAST(
            TRIM(fu.name)
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS name,

        CAST(
            UPPER(TRIM(fu.name))
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS name_key,

        CAST(
            TRIM(fu.usuario)
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS usuario,

        CAST(
            TRIM(fu.usuario)
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS usuario_key,

        CAST(
            fu.employee_id
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS employee_id,

        CAST(
            fu.email
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS email,

        CAST(
            fu.role
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS role,

        CAST(
            fu.empresa
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS empresa,

        CAST(
            fu.territory
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS territory,

        CAST(
            fu.regional
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS regional,

        CAST(
            fu.title
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS title,

        fu.data_admissao,
        fu.data_demissao,
        fu.updated_at

    FROM iqtv2.fca_users fu

    WHERE fu.name IS NOT NULL
      AND TRIM(fu.name) <> ''
),

usuarios_ranqueados AS (
    SELECT
        uf.*,

        ROW_NUMBER() OVER (
            PARTITION BY uf.name_key
            ORDER BY
                CASE
                    WHEN uf.data_demissao IS NULL THEN 0
                    ELSE 1
                END,
                uf.updated_at DESC,
                uf.id DESC
        ) AS rn

    FROM usuarios_fca uf
),

usuarios_por_nome AS (
    SELECT
        *

    FROM usuarios_ranqueados

    WHERE rn = 1
),

fonte_normalizada AS (
    SELECT
        CAST(
            TRIM(v.LOGIN_TECNICO)
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS login_tecnico,

        CAST(
            UPPER(TRIM(v.LOGIN_TECNICO))
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS login_key,

        CAST(
            COALESCE(
                NULLIF(TRIM(v.NOME_TECNICO), ''),
                TRIM(v.LOGIN_TECNICO)
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS nome_tecnico_origem,

        DATE(
            COALESCE(
                STR_TO_DATE(
                    TRIM(v.DT_FECHAMENTO),
                    '%Y-%m-%d %H:%i:%s'
                ),
                STR_TO_DATE(
                    TRIM(v.DT_FECHAMENTO),
                    '%Y-%m-%d'
                ),
                STR_TO_DATE(
                    TRIM(v.DT_FECHAMENTO),
                    '%d/%m/%Y %H:%i:%s'
                ),
                STR_TO_DATE(
                    TRIM(v.DT_FECHAMENTO),
                    '%d/%m/%Y'
                )
            )
        ) AS data_fechamento,

        CAST(
            COALESCE(
                UPPER(TRIM(v.TIPO_OS)),
                ''
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS tipo_os,

        CAST(
            COALESCE(
                UPPER(TRIM(v.DESCRICAO_OS)),
                ''
            )
            AS CHAR(500) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS descricao_os,

        CAST(
            COALESCE(
                UPPER(TRIM(v.DETALHE_OS)),
                ''
            )
            AS CHAR(500) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS detalhe_os,

        CAST(
            UPPER(TRIM(v.TECNICO_PROPRIO_TERCEIRO))
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS tecnico_proprio_terceiro,

        CAST(
            UPPER(TRIM(v.EXECUTADO))
            AS CHAR(50) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS executado,

        CAST(
            REPLACE(
                COALESCE(v.PONTUACAO, '0'),
                ',',
                '.'
            ) AS DECIMAL(12,3)
        ) AS pontuacao_num,

        CAST(
            COALESCE(TRIM(v.Flag_IRR), '0')
            AS CHAR(10) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS flag_irr_texto

    FROM db_Melhoria_continua_operacoes.view_bsc_periodo_fechamento v
),

base AS (
    SELECT
        f.login_tecnico,
        f.login_key,
        f.nome_tecnico_origem,
        f.data_fechamento,
        f.tipo_os,
        f.descricao_os,
        f.detalhe_os,
        f.pontuacao_num,

        CASE
            WHEN f.flag_irr_texto = '1' THEN 1
            ELSE 0
        END AS flag_irr

    FROM fonte_normalizada f

    WHERE REPLACE(
              f.tecnico_proprio_terceiro,
              'Ó',
              'O'
          ) = 'PROPRIO'

      AND f.executado IN ('S', 'SIM')

      AND f.login_tecnico IS NOT NULL
      AND f.login_tecnico <> ''
),

base_periodo AS (
    SELECT
        *

    FROM base

    WHERE data_fechamento BETWEEN DATE('2026-07-01')
                              AND DATE('2026-08-31')
),

parametros AS (
    SELECT
        LEAST(
            DATE('2026-08-31'),
            CURRENT_DATE(),
            COALESCE(
                MAX(data_fechamento),
                CURRENT_DATE()
            )
        ) AS dt_corte,

        MAX(data_fechamento) AS ultima_data_na_base

    FROM base_periodo
),

agregado AS (
    SELECT
        login_key,

        MAX(login_tecnico) AS login_tecnico,

        MAX(nome_tecnico_origem) AS nome_tecnico_origem,

        COUNT(*) AS total_os_executadas,

        COUNT(
            DISTINCT data_fechamento
        ) AS dias_produzidos,

        MIN(data_fechamento) AS primeiro_dia_producao,

        MAX(data_fechamento) AS ultimo_dia_producao,

        ROUND(
            SUM(pontuacao_num),
            3
        ) AS pontuacao_total,

        SUM(flag_irr) AS qtd_irr,

        -- ====================================================
        -- QUANTIDADE POR TIPO DE SERVIÇO
        -- ====================================================

        SUM(
            CASE
                WHEN tipo_os = 'REPARO' THEN 1
                ELSE 0
            END
        ) AS qtd_reparo,

        SUM(
            CASE
                WHEN tipo_os = 'REPARO PREV' THEN 1
                ELSE 0
            END
        ) AS qtd_reparo_prev,

        SUM(
            CASE
                WHEN tipo_os = 'MUD END' THEN 1
                ELSE 0
            END
        ) AS qtd_mud_end,

        SUM(
            CASE
                WHEN tipo_os = 'OUTROS SERVIÇOS'
                 AND descricao_os <> 'SERVIÇOS ADICIONAIS - ENTREGA DE CHIP'
                 AND detalhe_os <> 'ENTREGA DE CHIP'
                    THEN 1
                ELSE 0
            END
        ) AS qtd_outros_servicos,

        SUM(
            CASE
                WHEN descricao_os = 'SERVIÇOS ADICIONAIS - ENTREGA DE CHIP'
                  OR detalhe_os = 'ENTREGA DE CHIP'
                    THEN 1
                ELSE 0
            END
        ) AS qtd_entrega_chip,

        SUM(
            CASE
                WHEN tipo_os IN ('ATIVAÇÃO', 'ATIVACAO')
                    THEN 1
                ELSE 0
            END
        ) AS qtd_ativacao,

        SUM(
            CASE
                WHEN tipo_os = 'RETIRADA'
                    THEN 1
                ELSE 0
            END
        ) AS qtd_retirada,

        SUM(
            CASE
                WHEN tipo_os IN ('INSTALAÇÃO', 'INSTALACAO')
                    THEN 1
                ELSE 0
            END
        ) AS qtd_instalacao,

        SUM(
            CASE
                WHEN tipo_os NOT IN (
                    'REPARO',
                    'REPARO PREV',
                    'MUD END',
                    'OUTROS SERVIÇOS',
                    'ATIVAÇÃO',
                    'ATIVACAO',
                    'RETIRADA',
                    'INSTALAÇÃO',
                    'INSTALACAO'
                )
                    THEN 1
                ELSE 0
            END
        ) AS qtd_demais_tipos,

        SUM(
            CASE
                WHEN tipo_os = 'REPARO'
                    THEN 1
                ELSE 0
            END
        ) AS os_reparo_executadas,

        -- Mix do prêmio principal
        SUM(
            CASE
                WHEN tipo_os IN (
                    'REPARO',
                    'REPARO PREV',
                    'MUD END',
                    'OUTROS SERVIÇOS'
                )
                 AND descricao_os <> 'SERVIÇOS ADICIONAIS - ENTREGA DE CHIP'
                 AND detalhe_os <> 'ENTREGA DE CHIP'
                    THEN 1
                ELSE 0
            END
        ) AS os_mix_principal,

        -- Mix Alelo
        SUM(
            CASE
                WHEN tipo_os IN (
                    'REPARO',
                    'REPARO PREV',
                    'MUD END',
                    'OUTROS SERVIÇOS'
                )
                    THEN 1
                ELSE 0
            END
        ) AS os_mix_alelo

    FROM base_periodo

    GROUP BY login_key
),

indicadores AS (
    SELECT
        a.*,

        CAST(
            COALESCE(
                ua.name,
                un.name,
                a.nome_tecnico_origem
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS nome_tecnico_correto,

        COALESCE(
            ua.id,
            un.id
        ) AS fca_user_id,

        CAST(
            COALESCE(
                ua.usuario,
                un.usuario
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS usuario,

        CAST(
            COALESCE(
                ua.employee_id,
                un.employee_id
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS employee_id,

        CAST(
            COALESCE(
                ua.email,
                un.email
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS email_usuario,

        CAST(
            COALESCE(
                ua.role,
                un.role
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS perfil_usuario,

        CAST(
            COALESCE(
                ua.empresa,
                un.empresa
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS empresa_usuario,

        CAST(
            COALESCE(
                ua.territory,
                un.territory
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS territorio_usuario,

        CAST(
            COALESCE(
                ua.regional,
                un.regional
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS regional_usuario,

        CAST(
            COALESCE(
                ua.title,
                un.title
            )
            AS CHAR(255) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS cargo_usuario,

        COALESCE(
            ua.data_admissao,
            un.data_admissao
        ) AS data_admissao_usuario,

        COALESCE(
            ua.data_demissao,
            un.data_demissao
        ) AS data_demissao_usuario,

        CAST(
            CASE
                WHEN ma.usuario_key IS NOT NULL
                 AND ua.id IS NOT NULL
                    THEN 'MAPA MANUAL'

                WHEN un.id IS NOT NULL
                    THEN 'NOME EXATO'

                WHEN ma.usuario_key IS NOT NULL
                 AND ua.id IS NULL
                    THEN 'USUARIO DO MAPA NAO ENCONTRADO'

                ELSE 'NAO ENCONTRADO'
            END
            AS CHAR(100) CHARACTER SET utf8mb4
        ) COLLATE utf8mb4_unicode_ci AS origem_cruzamento,

        p.dt_corte,
        p.ultima_data_na_base,

        ROUND(
            a.pontuacao_total /
            NULLIF(a.dias_produzidos, 0),
            3
        ) AS produtividade,

        ROUND(
            (a.qtd_irr * 100.0) /
            NULLIF(a.os_reparo_executadas, 0),
            3
        ) AS irr_pct,

        ROUND(
            (a.os_mix_principal * 100.0) /
            NULLIF(a.total_os_executadas, 0),
            3
        ) AS mix_pct_principal,

        ROUND(
            (a.os_mix_alelo * 100.0) /
            NULLIF(a.total_os_executadas, 0),
            3
        ) AS mix_pct_alelo

    FROM agregado a

    -- Procura o técnico no mapa manual
    LEFT JOIN mapa_alias ma
        ON ma.login_key = a.login_key

    -- Se estiver no mapa, procura pelo usuario único
    LEFT JOIN usuarios_fca ua
        ON ua.usuario_key = ma.usuario_key

    -- Se não estiver no mapa, procura pelo nome exato
    LEFT JOIN usuarios_por_nome un
        ON ma.usuario_key IS NULL
       AND un.name_key = a.login_key

    CROSS JOIN parametros p
),

scores AS (
    SELECT
        i.*,

        ROUND(
            i.produtividade / 6,
            3
        ) AS score_produtividade,

        ROUND(
            1 - (
                COALESCE(i.irr_pct, 0) / 20
            ),
            3
        ) AS score_irr,

        ROUND(
            (
                ROUND(
                    i.produtividade / 6,
                    3
                ) * 0.70
            )
            +
            (
                ROUND(
                    1 - (
                        COALESCE(i.irr_pct, 0) / 20
                    ),
                    3
                ) * 0.30
            ),
            3
        ) AS score_final

    FROM indicadores i

    WHERE i.dias_produzidos > 0
),

classificado AS (
    SELECT
        s.*,

        CASE
            WHEN s.mix_pct_principal >= 80 THEN 1
            ELSE 0
        END AS apto_principal_flag,

        CASE
            WHEN s.mix_pct_alelo >= 80 THEN 1
            ELSE 0
        END AS apto_alelo_flag

    FROM scores s
),

ranqueado AS (
    SELECT
        c.*,

        ROW_NUMBER() OVER (
            ORDER BY
                c.score_final DESC,
                c.produtividade DESC,
                COALESCE(c.irr_pct, 0) ASC,
                c.os_mix_principal DESC,
                c.nome_tecnico_correto ASC
        ) AS colocacao_geral,

        ROW_NUMBER() OVER (
            PARTITION BY c.apto_principal_flag
            ORDER BY
                c.score_final DESC,
                c.produtividade DESC,
                COALESCE(c.irr_pct, 0) ASC,
                c.os_mix_principal DESC,
                c.nome_tecnico_correto ASC
        ) AS colocacao_no_grupo

    FROM classificado c
)

SELECT
    r.colocacao_geral,

    CASE
        WHEN r.apto_principal_flag = 1
            THEN 'VALIDO'
        ELSE 'NAO VALIDO'
    END AS grupo,

    r.colocacao_no_grupo,

    -- Dados originais da view
    r.login_tecnico,
    r.nome_tecnico_origem,

    -- Nome correto encontrado na fca_users
    r.nome_tecnico_correto,

    -- Dados da iqtv2.fca_users
    r.fca_user_id,
    r.usuario,
    r.employee_id,
    r.email_usuario,
    r.perfil_usuario,
    r.empresa_usuario,
    r.territorio_usuario,
    r.regional_usuario,
    r.cargo_usuario,
    r.data_admissao_usuario,
    r.data_demissao_usuario,
    r.origem_cruzamento,

    r.dt_corte AS parcial_ate,

    r.dias_produzidos,
    r.primeiro_dia_producao,
    r.ultimo_dia_producao,
    r.total_os_executadas,

    -- Quantidade por tipo de serviço
    r.qtd_reparo,
    r.qtd_reparo_prev,
    r.qtd_mud_end,
    r.qtd_outros_servicos,
    r.qtd_entrega_chip,
    r.qtd_ativacao,
    r.qtd_retirada,
    r.qtd_instalacao,
    r.qtd_demais_tipos,

    r.pontuacao_total,
    r.produtividade,
    r.qtd_irr,
    r.irr_pct,
    r.score_produtividade,
    r.score_irr,
    r.score_final,

    r.os_mix_principal,
    r.mix_pct_principal,
    r.os_mix_alelo,
    r.mix_pct_alelo,

    CASE
        WHEN r.apto_principal_flag = 1
            THEN 'SIM'
        ELSE 'NAO'
    END AS apto_parcial_principal,

    CASE
        WHEN r.apto_alelo_flag = 1
            THEN 'SIM'
        ELSE 'NAO'
    END AS apto_parcial_alelo,

    CASE
        WHEN r.apto_principal_flag = 1
         AND r.colocacao_no_grupo = 1
            THEN '1º — MOTOCICLETA'

        WHEN r.apto_principal_flag = 1
         AND r.colocacao_no_grupo BETWEEN 2 AND 5
            THEN 'TV 43"'

        WHEN r.apto_principal_flag = 1
         AND r.colocacao_no_grupo BETWEEN 6 AND 10
            THEN 'R$ 200,00 — CARTÃO ALELO'

        ELSE NULL
    END AS premio_parcial,

    CASE
        WHEN r.apto_principal_flag = 1
            THEN CONCAT(
                'Mix elegível ',
                r.mix_pct_principal,
                '% (>= 80%). ',
                'Produtividade diária atendida por construção. ',
                'Pendente: validação RH.'
            )

        ELSE CONCAT(
            'Mix elegível ',
            COALESCE(r.mix_pct_principal, 0),
            '% — abaixo do mínimo de 80% (',
            r.os_mix_principal,
            ' de ',
            r.total_os_executadas,
            ' OS no mix)'
        )
    END AS obs_elegibilidade_principal,

    CASE
        WHEN r.mix_pct_alelo < 80
            THEN CONCAT(
                'Mix elegível ',
                COALESCE(r.mix_pct_alelo, 0),
                '% — abaixo do mínimo de 80%'
            )

        WHEN r.dias_produzidos >= 30
            THEN CONCAT(
                'Mix OK e ',
                r.dias_produzidos,
                ' dias produzidos. ',
                'Pendente: validação RH.'
            )

        ELSE CONCAT(
            'Mix OK; ',
            r.dias_produzidos,
            ' de 30 dias mínimos de atuação.'
        )
    END AS obs_elegibilidade_alelo,

    CONCAT(
        'Score Final ',
        r.score_final,
        ' = 70% × ',
        r.score_produtividade,
        ' (',
        r.pontuacao_total,
        ' pts ÷ ',
        r.dias_produzidos,
        ' dias ÷ 6)',
        ' + 30% × ',
        r.score_irr,
        ' (IRR ',
        COALESCE(r.irr_pct, 0),
        '% = ',
        r.qtd_irr,
        '/',
        r.os_reparo_executadas,
        ' reparos)'
    ) AS obs_colocacao,

    CASE
        WHEN r.usuario IS NULL
            THEN CONCAT(
                'Usuário não encontrado para: ',
                r.login_tecnico
            )

        WHEN r.origem_cruzamento = 'MAPA MANUAL'
            THEN CONCAT(
                'Usuário localizado pelo mapa manual: ',
                r.usuario,
                ' — nome correto: ',
                r.nome_tecnico_correto
            )

        ELSE CONCAT(
            'Usuário localizado pelo nome exato: ',
            r.usuario
        )
    END AS obs_usuario,

    CASE
        WHEN r.ultima_data_na_base IS NULL
            THEN 'Nenhum fechamento encontrado no período.'

        WHEN r.ultima_data_na_base <
             LEAST(
                 CURRENT_DATE(),
                 DATE('2026-08-31')
             )
            THEN CONCAT(
                'Base atualizada até ',
                r.ultima_data_na_base,
                ' — ranking reflete dados até essa data.'
            )

        ELSE 'Base atualizada.'
    END AS obs_atualizacao

FROM ranqueado r

ORDER BY r.colocacao_geral;