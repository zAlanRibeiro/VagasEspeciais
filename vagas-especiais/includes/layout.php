<?php
/**
 * Cabeçalho/rodapé HTML compartilhados. Todas as páginas ficam um nível abaixo
 * da raiz (public/, admin/), por isso os caminhos relativos usam "../".
 */
declare(strict_types=1);

const LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
const LEAFLET_JS  = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
const CLUSTER_CSS = ['https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
                     'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css'];
const CLUSTER_JS  = 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js';

function cabecalho(string $titulo, array $op = []): void
{
    $css = $op['css'] ?? [];
    $classe = $op['classe_body'] ?? '';
    ?>
<!doctype html>
<html lang="pt-BR"<?= modo_acessivel() ? ' class="modo-acessivel"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="theme-color" content="#fbf8f3">
  <title><?= e($titulo) ?> · <?= e(APP_NOME) ?></title>
  <link rel="icon" href="../assets/img/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="../assets/img/icon-192.png">
  <?php if (!empty($op['pwa'])): ?>
  <link rel="manifest" href="manifest.webmanifest">
  <?php endif; ?>
  <?php foreach ($css as $href): ?>
  <link rel="stylesheet" href="<?= e($href) ?>">
  <?php endforeach; ?>
  <link rel="preload" href="../assets/fonts/nunito.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="<?= e($classe) ?>">
<?php readfile(APP_ROOT . '/assets/img/icones.svg'); ?>
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<?php
}

/** Preferência "letra maior e alto contraste" deste aparelho (cookie gravado pelo app.js). */
function modo_acessivel(): bool
{
    return ($_COOKIE['modo_acessivel'] ?? '') === '1';
}

/** Botão "Letra maior" das telas de entrada (antes do login, quem precisa dele já precisa). */
function botao_modo_acessivel(): string
{
    return '<button type="button" class="botao botao-pequeno botao-acessivel" data-modo-acessivel aria-pressed="'
        . (modo_acessivel() ? 'true' : 'false') . '">' . icone('letra') . ' Letra maior e alto contraste</button>';
}

/** Ícone do sprite (assets/img/icones.svg), decorativo. */
function icone(string $nome, string $classe = 'icone'): string
{
    return '<svg class="' . e($classe) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($nome) . '"></use></svg>';
}

/** Símbolo de cada perfil/tipo de vaga, o mesmo dos alfinetes do mapa (decorativo). */
function simbolo_perfil(string $tipo): string
{
    return match ($tipo) {
        'PCD'   => icone('pcd', 'icone-pcd'),
        'IDOSO' => '60+',
        default => 'P',
    };
}

function rodape(array $scripts = []): void
{
    foreach ($scripts as $src) {
        echo '<script src="' . e($src) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function render_flashes(): void
{
    foreach (flashes() as $f) {
        $classe = $f['tipo'] === 'erro' ? 'alerta alerta-erro' : 'alerta alerta-ok';
        echo '<div class="' . $classe . '" role="status">' . e($f['mensagem']) . '</div>';
    }
}

/* ---------- Painel administrativo ---------- */

function admin_inicio(string $titulo, string $ativo, array $op = []): void
{
    cabecalho($titulo . ' — Admin', $op + ['classe_body' => 'admin']);
    $itens = [
        'index'        => 'Dashboard',
        'usuarios'     => 'Usuários',
        'comprovacoes' => 'Comprovações',
        'vagas'        => 'Vagas',
        'importar'     => 'Importar base',
        'relatos'      => 'Relatos',
    ];
    ?>
<header class="admin-topo">
  <a class="marca" href="index.php"><img src="../assets/img/icon.svg" alt="" width="30" height="30"> Vagas Especiais · Admin</a>
  <nav aria-label="Administração">
    <?php foreach ($itens as $arq => $rotulo): ?>
      <a href="<?= $arq ?>.php"<?= $arq === $ativo ? ' aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="admin-sair">
    <a href="../public/mapa.php">Ver mapa</a>
    <a href="../public/logout.php">Sair</a>
  </div>
</header>
<main id="conteudo" class="admin-conteudo">
  <h1><?= e($titulo) ?></h1>
  <?php render_flashes(); ?>
<?php
}

function admin_fim(array $scripts = []): void
{
    echo "</main>\n";
    rodape($scripts);
}

/** Monta query string preservando os filtros atuais. */
function qs(array $alteracoes): string
{
    $q = array_merge($_GET, $alteracoes);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '?';
}

function paginacao(int $pagina, int $total, int $por_pagina): void
{
    $paginas = (int) ceil($total / $por_pagina);
    if ($paginas <= 1) {
        return;
    }
    echo '<nav class="paginacao" aria-label="Paginação">';
    if ($pagina > 1) {
        echo '<a href="' . e(qs(['p' => $pagina - 1])) . '">← Anterior</a>';
    }
    echo '<span>Página ' . $pagina . ' de ' . $paginas . '</span>';
    if ($pagina < $paginas) {
        echo '<a href="' . e(qs(['p' => $pagina + 1])) . '">Próxima →</a>';
    }
    echo '</nav>';
}
