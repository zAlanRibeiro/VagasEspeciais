<?php
/**
 * Estatísticas de uso para o painel administrativo.
 *
 * Tudo é agregado por dia — o sistema guarda "quantas vezes a vaga X foi vista hoje", nunca "quem viu".
 * A única informação ligada a um usuário é uso_diario (em que dias usou o mapa), para contar usuários
 * ativos; ela é apagada após 90 dias e junto com a conta.
 */
declare(strict_types=1);

const TIPOS_EVENTO = [
    'vaga_vista'     => 'Vaga vista',
    'rota'           => 'Rota traçada',
    'navegacao'      => 'Navegação iniciada',
    'chegada'        => 'Chegada à vaga',
    'waze'           => 'Abriu no Waze',
    'gmaps'          => 'Abriu no Google Maps',
    'busca'          => 'Busca de destino',
    'busca_sem_vaga' => 'Busca sem vaga a menos de 1 km',
];
const USO_DIARIO_DIAS = 90;

/** INSERT que soma 1 se a linha já existe (sintaxe do MySQL ou do SQLite). */
function somar_um(string $tabela, array $chave, array $extras = []): void
{
    $colunas = array_merge(array_keys($chave), array_keys($extras), ['total']);
    $valores = array_merge(array_values($chave), array_values($extras), [1]);
    $marcas = implode(', ', array_fill(0, count($colunas), '?'));
    $sql = "INSERT INTO $tabela (" . implode(', ', $colunas) . ") VALUES ($marcas) "
         . (DB_DRIVER === 'sqlite'
             ? 'ON CONFLICT(' . implode(', ', array_keys($chave)) . ') DO UPDATE SET total = total + 1'
             : 'ON DUPLICATE KEY UPDATE total = total + 1');
    db()->prepare($sql)->execute($valores);
}

function contar_evento(string $tipo, int $vaga_id = 0): void
{
    somar_um('estatisticas', ['dia' => date('Y-m-d'), 'tipo' => $tipo, 'vaga_id' => $vaga_id]);
}

/** Destino buscado sem vaga por perto (posição arredondada para ~100 m: aponta a região, não o endereço exato). */
function registrar_demanda(float $lat, float $lng, string $nome): void
{
    somar_um('demanda_sem_vaga',
        ['dia' => date('Y-m-d'), 'lat' => round($lat, 3), 'lng' => round($lng, 3)],
        ['nome' => mb_substr(trim($nome), 0, 120)]);
}

function registrar_uso(int $usuario_id): void
{
    $sql = DB_DRIVER === 'sqlite'
        ? 'INSERT OR IGNORE INTO uso_diario (dia, usuario_id) VALUES (?, ?)'
        : 'INSERT IGNORE INTO uso_diario (dia, usuario_id) VALUES (?, ?)';
    db()->prepare($sql)->execute([date('Y-m-d'), $usuario_id]);
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM uso_diario WHERE dia < ?')
            ->execute([date('Y-m-d', strtotime('-' . USO_DIARIO_DIAS . ' days'))]);
    }
}

/* ---------- Consultas do painel ---------- */

function inicio_periodo(int $dias): string
{
    return date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
}

/** Totais por tipo de evento no período. */
function totais_eventos(int $dias): array
{
    $st = db()->prepare('SELECT tipo, SUM(total) AS n FROM estatisticas WHERE dia >= ? GROUP BY tipo');
    $st->execute([inicio_periodo($dias)]);
    $totais = array_fill_keys(array_keys(TIPOS_EVENTO), 0);
    foreach ($st->fetchAll() as $l) {
        $totais[$l['tipo']] = (int) $l['n'];
    }
    return $totais;
}

/** Série diária (todos os dias do período, inclusive os sem uso). */
function serie_diaria(int $dias, array $tipos): array
{
    $st = db()->prepare('SELECT dia, tipo, SUM(total) AS n FROM estatisticas WHERE dia >= ? AND tipo IN ('
        . implode(',', array_fill(0, count($tipos), '?')) . ') GROUP BY dia, tipo');
    $st->execute(array_merge([inicio_periodo($dias)], $tipos));
    $porDia = [];
    foreach ($st->fetchAll() as $l) {
        $porDia[substr((string) $l['dia'], 0, 10)][$l['tipo']] = (int) $l['n'];
    }
    $serie = [];
    for ($i = $dias - 1; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-$i days"));
        $linha = ['dia' => $dia];
        foreach ($tipos as $t) {
            $linha[$t] = $porDia[$dia][$t] ?? 0;
        }
        $serie[] = $linha;
    }
    return $serie;
}

function vagas_mais_procuradas(int $dias, int $limite = 10): array
{
    $st = db()->prepare("SELECT v.id, v.logradouro, v.numero, v.bairro, v.categoria,
                                SUM(CASE WHEN e.tipo = 'vaga_vista' THEN e.total ELSE 0 END) AS vistas,
                                SUM(CASE WHEN e.tipo = 'rota' THEN e.total ELSE 0 END) AS rotas,
                                SUM(CASE WHEN e.tipo = 'chegada' THEN e.total ELSE 0 END) AS chegadas,
                                SUM(CASE WHEN e.tipo IN ('waze', 'gmaps') THEN e.total ELSE 0 END) AS externos
                         FROM estatisticas e JOIN vagas v ON v.id = e.vaga_id
                         WHERE e.dia >= ? AND e.vaga_id > 0
                         GROUP BY v.id, v.logradouro, v.numero, v.bairro, v.categoria
                         ORDER BY (SUM(CASE WHEN e.tipo IN ('rota', 'waze', 'gmaps') THEN e.total ELSE 0 END) * 3
                                   + SUM(CASE WHEN e.tipo = 'chegada' THEN e.total ELSE 0 END) * 5
                                   + SUM(CASE WHEN e.tipo = 'vaga_vista' THEN e.total ELSE 0 END)) DESC
                         LIMIT $limite");
    $st->execute([inicio_periodo($dias)]);
    return $st->fetchAll();
}

function procura_por_bairro(int $dias, int $limite = 10): array
{
    $st = db()->prepare("SELECT v.bairro,
                                SUM(CASE WHEN e.tipo = 'vaga_vista' THEN e.total ELSE 0 END) AS vistas,
                                SUM(CASE WHEN e.tipo IN ('rota', 'waze', 'gmaps') THEN e.total ELSE 0 END) AS rotas
                         FROM estatisticas e JOIN vagas v ON v.id = e.vaga_id
                         WHERE e.dia >= ? AND e.vaga_id > 0
                         GROUP BY v.bairro ORDER BY rotas DESC, vistas DESC LIMIT $limite");
    $st->execute([inicio_periodo($dias)]);
    return $st->fetchAll();
}

function demanda_sem_vaga(int $dias, int $limite = 15): array
{
    $st = db()->prepare("SELECT lat, lng, MAX(nome) AS nome, SUM(total) AS buscas
                         FROM demanda_sem_vaga WHERE dia >= ?
                         GROUP BY lat, lng ORDER BY buscas DESC LIMIT $limite");
    $st->execute([inicio_periodo($dias)]);
    return array_map(fn($d) => ['lat' => (float) $d['lat'], 'lng' => (float) $d['lng'], 'nome' => $d['nome'],
        'buscas' => (int) $d['buscas']], $st->fetchAll());
}

function usuarios_ativos(int $dias): int
{
    $st = db()->prepare('SELECT COUNT(DISTINCT usuario_id) FROM uso_diario WHERE dia >= ?');
    $st->execute([inicio_periodo($dias)]);
    return (int) $st->fetchColumn();
}

/** Tempo médio entre o envio da comprovação e a análise (em horas), no período. */
function tempo_medio_analise(int $dias): ?float
{
    $st = db()->prepare('SELECT criado_em, analisado_em FROM comprovacoes WHERE analisado_em IS NOT NULL AND analisado_em >= ?');
    $st->execute([inicio_periodo($dias) . ' 00:00:00']);
    $horas = array_map(fn($c) => (strtotime($c['analisado_em']) - strtotime($c['criado_em'])) / 3600, $st->fetchAll());
    return $horas ? array_sum($horas) / count($horas) : null;
}

function cadastros_no_periodo(int $dias): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE criado_em >= ? AND papel = ?');
    $st->execute([inicio_periodo($dias) . ' 00:00:00', 'usuario']);
    return (int) $st->fetchColumn();
}
