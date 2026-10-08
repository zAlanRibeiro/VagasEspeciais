<?php
/**
 * Gráfico de linhas (SVG gerado no servidor) para o painel.
 * Segue o método de visualização do projeto: uma escala só, traços de 2 px, legenda acima, rótulos
 * diretos no fim das linhas (texto em cor neutra + ponto colorido), dica ao passar o mouse
 * (assets/js/admin.js) e a tabela com os mesmos números (cores das séries validadas para daltonismo).
 */
declare(strict_types=1);

/** Arredonda o topo do eixo para um número "redondo" (5, 10, 20, 50, 100…). */
function topo_redondo(int $maximo): int
{
    if ($maximo <= 4) {
        return 4;
    }
    $potencia = 10 ** (int) floor(log10($maximo));
    foreach ([1, 2, 2.5, 5, 10] as $fator) {
        if ($fator * $potencia >= $maximo) {
            return (int) ceil($fator * $potencia);
        }
    }
    return $maximo;
}

/**
 * @param array $serie  [['dia' => 'Y-m-d', 'chave1' => n, ...], ...]
 * @param array $linhas [['chave' => 'vaga_vista', 'nome' => 'Vagas vistas'], ...] (até 3; cores fixas na ordem)
 */
function grafico_linhas(array $serie, array $linhas, string $titulo): string
{
    $maximo = 0;
    foreach ($serie as $p) {
        foreach ($linhas as $l) {
            $maximo = max($maximo, (int) $p[$l['chave']]);
        }
    }
    if ($maximo === 0) {
        return '<p class="vazio-grafico">Ainda não há uso registrado neste período. Os números aparecem conforme as pessoas usam o mapa.</p>';
    }

    $W = 760; $H = 250; $esq = 44; $dir = 128; $topo = 14; $baixo = 30;
    $larg = $W - $esq - $dir;
    $alt = $H - $topo - $baixo;
    $n = count($serie);
    $topoEixo = topo_redondo($maximo);
    $x = fn(int $i) => $esq + ($n > 1 ? $i * $larg / ($n - 1) : $larg / 2);
    $y = fn(float $v) => $topo + $alt - ($v / $topoEixo) * $alt;

    $svg = '<svg class="grafico-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($titulo) . '">';
    // Grade recessiva e rótulos do eixo
    for ($k = 0; $k <= 4; $k++) {
        $valor = $topoEixo * $k / 4;
        $py = round($y($valor), 1);
        $svg .= '<line class="grade" x1="' . $esq . '" x2="' . ($W - $dir) . '" y1="' . $py . '" y2="' . $py . '"/>';
        $svg .= '<text class="eixo" x="' . ($esq - 8) . '" y="' . ($py + 4) . '" text-anchor="end">'
              . number_format($valor, $valor == (int) $valor ? 0 : 1, ',', '.') . '</text>';
    }
    $passoRotulo = max(1, (int) ceil($n / 7));
    foreach ($serie as $i => $p) {
        if ($i % $passoRotulo === 0 || $i === $n - 1) {
            $svg .= '<text class="eixo" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">'
                  . date('d/m', strtotime($p['dia'])) . '</text>';
        }
    }
    // Linhas
    $finais = [];
    foreach ($linhas as $s => $l) {
        $pontos = [];
        foreach ($serie as $i => $p) {
            $pontos[] = round($x($i), 1) . ',' . round($y((int) $p[$l['chave']]), 1);
        }
        $svg .= '<polyline class="linha serie-' . ($s + 1) . '" points="' . implode(' ', $pontos) . '"/>';
        $finais[] = ['s' => $s + 1, 'y' => $y((int) end($serie)[$l['chave']]), 'nome' => $l['nome'],
                     'valor' => (int) end($serie)[$l['chave']]];
    }
    // Rótulos diretos no fim das linhas, afastados para não se sobreporem
    usort($finais, fn($a, $b) => $a['y'] <=> $b['y']);
    $finais[0]['ly'] = $finais[0]['y'];
    for ($i = 1; $i < count($finais); $i++) {
        $finais[$i]['ly'] = max($finais[$i]['y'], $finais[$i - 1]['ly'] + 15);
    }
    foreach ($finais as $f) {
        $xf = round($x($n - 1), 1);
        $svg .= '<circle class="ponto serie-' . $f['s'] . '" cx="' . $xf . '" cy="' . round($f['y'], 1) . '" r="4"/>';
        $svg .= '<text class="rotulo-fim" x="' . ($xf + 10) . '" y="' . round($f['ly'] + 4, 1) . '">' . e($f['nome']) . '</text>';
    }
    // Camada de interação (cruz + pontos), controlada pelo admin.js
    $svg .= '<line class="cruz" x1="0" x2="0" y1="' . $topo . '" y2="' . ($topo + $alt) . '" hidden/>';
    foreach ($linhas as $s => $l) {
        $svg .= '<circle class="marca-hover serie-' . ($s + 1) . '" r="5" cx="0" cy="0" hidden/>';
    }
    $svg .= '<rect class="area-hover" x="' . $esq . '" y="' . $topo . '" width="' . $larg . '" height="' . $alt . '"/>';
    $svg .= '</svg>';

    $legenda = '<ul class="legenda">';
    foreach ($linhas as $s => $l) {
        $legenda .= '<li><span class="amostra serie-' . ($s + 1) . '"></span>' . e($l['nome']) . '</li>';
    }
    $legenda .= '</ul>';

    $tabela = '<details class="tabela-grafico"><summary>Ver os números em tabela</summary><table><thead><tr><th>Dia</th>';
    foreach ($linhas as $l) {
        $tabela .= '<th class="num">' . e($l['nome']) . '</th>';
    }
    $tabela .= '</tr></thead><tbody>';
    foreach (array_reverse($serie) as $p) {
        $tabela .= '<tr><td>' . date('d/m/Y', strtotime($p['dia'])) . '</td>';
        foreach ($linhas as $l) {
            $tabela .= '<td class="num">' . (int) $p[$l['chave']] . '</td>';
        }
        $tabela .= '</tr>';
    }
    $tabela .= '</tbody></table></details>';

    $dados = json_encode([
        'serie'  => $serie,
        'linhas' => $linhas,
        'geo'    => ['W' => $W, 'esq' => $esq, 'larg' => $larg, 'topo' => $topo, 'alt' => $alt, 'max' => $topoEixo],
    ], JSON_UNESCAPED_UNICODE);

    return '<figure class="grafico" data-grafico="' . e($dados) . '">' . $legenda
         . '<div class="grafico-area">' . $svg . '<div class="dica-grafico" role="status" hidden></div></div>'
         . $tabela . '</figure>';
}
