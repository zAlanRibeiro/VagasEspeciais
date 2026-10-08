<?php
/**
 * Regras de negócio compartilhadas: rótulos, validação de vagas e
 * armazenamento de comprovações.
 */
declare(strict_types=1);

/** Tipos de vaga. Cada um corresponde a um perfil de usuário com o mesmo código. */
const CATEGORIAS = [
    'PCD'      => 'Pessoa com deficiência',
    'IDOSO'    => 'Pessoa idosa',
    'ROTATIVO' => 'Rotativo',
];

/** Perfis que dependem de comprovação e análise da equipe. O ROTATIVO é de todos e já nasce aprovado. */
const PERFIS_COM_COMPROVACAO = ['PCD', 'IDOSO'];

/** Como cada perfil aparece para a pessoa (nome e o que ele libera). */
const PERFIS = [
    'PCD'      => ['nome' => 'Pessoa com deficiência', 'libera' => 'vagas para pessoa com deficiência',
                   'documento' => 'Laudo médico ou credencial PCD'],
    'IDOSO'    => ['nome' => 'Pessoa idosa', 'libera' => 'vagas para pessoa idosa',
                   'documento' => 'Documento com foto ou credencial de idoso'],
    'ROTATIVO' => ['nome' => 'Rotativo', 'libera' => 'vagas do estacionamento rotativo',
                   'documento' => null],
];

/** Situação de um perfil (e de cada comprovação enviada). */
const STATUS_PERFIL = [
    'pendente'   => 'Falta a comprovação',
    'em_analise' => 'Em análise',
    'aprovado'   => 'Aprovado',
    'recusado'   => 'Recusado',
];

const TIPOS_RELATO = [
    'localizacao_incorreta' => 'Localização incorreta',
    'vaga_inexistente'      => 'Vaga inexistente',
    'sinalizacao_incorreta' => 'Sinalização incorreta ou ausente',
    'vaga_obstruida'        => 'Vaga obstruída',
    'outro'                 => 'Outro problema',
];

const STATUS_RELATO = [
    'aberto'     => 'Aberto',
    'em_analise' => 'Em análise',
    'resolvido'  => 'Resolvido',
    'descartado' => 'Descartado',
];

/** Limites aproximados do município de Niterói (com margem). */
const NITEROI_LAT = [-23.05, -22.80];
const NITEROI_LNG = [-43.20, -42.90];

const DOC_MAX_BYTES = 5 * 1024 * 1024;

/** Ponto dentro da área aproximada de Niterói (com margem). */
function dentro_de_niteroi(float $lat, float $lng): bool
{
    return $lat >= NITEROI_LAT[0] && $lat <= NITEROI_LAT[1] && $lng >= NITEROI_LNG[0] && $lng <= NITEROI_LNG[1];
}

function rotulo(array $mapa, ?string $chave): string
{
    return $mapa[$chave] ?? (string) $chave;
}

function data_br(?string $data, bool $hora = true): string
{
    if (!$data) {
        return '—';
    }
    $ts = strtotime($data);
    return $ts ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—';
}

/* ---------- Vagas ---------- */

function sem_acentos(string $s): string
{
    $mapa = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i',
             'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c'];
    return strtr(mb_strtolower(trim($s)), $mapa);
}

function normalizar_categoria(string $valor): ?string
{
    $v = sem_acentos($valor);
    if ($v === '') {
        return null;
    }
    if (preg_match('/(pcd|defici|pne|cadeirante)/', $v)) {
        return 'PCD';
    }
    if (preg_match('/(idos|60\+|senior)/', $v)) {
        return 'IDOSO';
    }
    if (preg_match('/(rotativ|comum|regular|geral|zona azul)/', $v)) {
        return 'ROTATIVO';
    }
    return null;
}

function normalizar_coordenada(string|float|int|null $valor): ?float
{
    if ($valor === null) {
        return null;
    }
    $v = str_replace([' ', ','], ['', '.'], trim((string) $valor));
    return is_numeric($v) ? (float) $v : null;
}

/**
 * Valida e normaliza os dados de uma vaga.
 * @return array{0: array, 1: string[]} [vaga normalizada, erros]
 */
function validar_vaga(array $d): array
{
    $erros = [];
    $vaga = [
        'codigo'     => trim((string) ($d['codigo'] ?? '')) ?: null,
        'logradouro' => trim((string) ($d['logradouro'] ?? '')),
        'numero'     => trim((string) ($d['numero'] ?? '')) ?: null,
        'bairro'     => trim((string) ($d['bairro'] ?? '')),
        'categoria'  => normalizar_categoria((string) ($d['categoria'] ?? '')),
        'latitude'   => normalizar_coordenada($d['latitude'] ?? null),
        'longitude'  => normalizar_coordenada($d['longitude'] ?? null),
        'status'     => 'ativa',
    ];

    $status = sem_acentos((string) ($d['status'] ?? ''));
    if ($status !== '' && preg_match('/^(inativ|desativ|0|nao|removid)/', $status)) {
        $vaga['status'] = 'inativa';
    }

    if ($vaga['codigo'] !== null && mb_strlen($vaga['codigo']) > 50) {
        $erros[] = 'ID com mais de 50 caracteres';
    }
    if ($vaga['logradouro'] === '') {
        $erros[] = 'logradouro vazio';
    }
    if ($vaga['bairro'] === '') {
        $erros[] = 'bairro vazio';
    }
    if ($vaga['categoria'] === null) {
        $erros[] = 'categoria inválida (use PCD, IDOSO ou ROTATIVO)';
    }
    if ($vaga['latitude'] === null || $vaga['longitude'] === null) {
        $erros[] = 'latitude/longitude inválida';
    } elseif ($vaga['latitude'] < NITEROI_LAT[0] || $vaga['latitude'] > NITEROI_LAT[1]
           || $vaga['longitude'] < NITEROI_LNG[0] || $vaga['longitude'] > NITEROI_LNG[1]) {
        $erros[] = 'coordenada fora de Niterói (verifique se latitude e longitude não estão invertidas)';
    }

    // Padroniza capitalização do bairro para o filtro não duplicar "ICARAÍ" e "Icaraí".
    if ($vaga['bairro'] !== '') {
        $bairro = mb_convert_case(mb_strtolower(preg_replace('/\s+/', ' ', $vaga['bairro'])), MB_CASE_TITLE);
        $vaga['bairro'] = preg_replace_callback('/ (Da|De|Do|Das|Dos|E) /u', fn($m) => mb_strtolower($m[0]), $bairro);
    }

    return [$vaga, $erros];
}

/* ---------- Perfis ---------- */

/** Perfis da pessoa, na ordem PCD, IDOSO, ROTATIVO: [tipo => ['tipo', 'status', 'motivo_recusa', ...]]. */
function perfis_do_usuario(int $usuario_id): array
{
    $st = db()->prepare('SELECT tipo, status, motivo_recusa, criado_em, atualizado_em FROM perfis WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $perfis = array_column($st->fetchAll(), null, 'tipo');
    return array_intersect_key(array_replace(array_fill_keys(array_keys(PERFIS), null), $perfis), $perfis);
}

/** Tipos de vaga que a pessoa vê no mapa: os dos perfis aprovados (administradores veem todos). */
function categorias_visiveis(?array $u): array
{
    if ($u === null || !empty($u['suspenso'])) {
        return [];
    }
    if (($u['papel'] ?? '') === 'admin') {
        return array_keys(CATEGORIAS);
    }
    $perfis = $u['perfis'] ?? perfis_do_usuario((int) $u['id']);
    return array_keys(array_filter($perfis, fn($p) => $p['status'] === 'aprovado'));
}

/** Frase que explica à pessoa a situação de um perfil (igual no site e no app). */
function descricao_perfil(array $p): string
{
    $libera = PERFIS[$p['tipo']]['libera'];
    return match ($p['status']) {
        'aprovado'   => 'Você vê as ' . $libera . ' no mapa.',
        'em_analise' => 'A equipe está conferindo seu documento. Avisamos assim que terminar.',
        'recusado'   => 'Comprovação recusada. Motivo: ' . ($p['motivo_recusa'] ?: 'não informado') . '. Envie um novo documento.',
        default      => 'Envie a comprovação para liberar as ' . $libera . '.',
    };
}

/** Texto do selo de situação ("Ativo" para o rotativo, que não passa por análise). */
function status_perfil_texto(array $p): string
{
    return $p['tipo'] === 'ROTATIVO' && $p['status'] === 'aprovado' ? 'Ativo' : rotulo(STATUS_PERFIL, $p['status']);
}

/** A vaga existe e é de um tipo que a pessoa vê no mapa. */
function vaga_visivel(int $vaga_id, array $u): bool
{
    $categorias = categorias_visiveis($u);
    if (!$categorias) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM vagas WHERE id = ? AND categoria IN (' . implode(',', array_fill(0, count($categorias), '?')) . ')');
    $st->execute(array_merge([$vaga_id], $categorias));
    return (bool) $st->fetchColumn();
}

/** Perfil ainda aceita (novo) documento: falta enviar ou foi recusado. */
function perfil_aceita_documento(?array $perfil): bool
{
    return $perfil === null || in_array($perfil['status'], ['pendente', 'recusado'], true);
}

/** Adiciona o perfil rotativo (não depende de análise). */
function adicionar_perfil_rotativo(int $usuario_id): void
{
    $st = db()->prepare("SELECT 1 FROM perfis WHERE usuario_id = ? AND tipo = 'ROTATIVO'");
    $st->execute([$usuario_id]);
    if (!$st->fetchColumn()) {
        db()->prepare("INSERT INTO perfis (usuario_id, tipo, status, criado_em, atualizado_em) VALUES (?, 'ROTATIVO', 'aprovado', ?, ?)")
            ->execute([$usuario_id, agora(), agora()]);
        registrar_log('perfil_adicionado', $usuario_id, 'ROTATIVO');
    }
}

/* ---------- Comprovações ---------- */

/** Identifica o tipo pelo conteúdo do arquivo (não confia na extensão nem no MIME enviado). */
function detectar_tipo_documento(string $caminho): ?array
{
    $h = @fopen($caminho, 'rb');
    if (!$h) {
        return null;
    }
    $inicio = (string) fread($h, 8);
    fclose($h);

    if (str_starts_with($inicio, '%PDF-')) {
        return ['mime' => 'application/pdf', 'ext' => 'pdf'];
    }
    if (str_starts_with($inicio, "\xFF\xD8\xFF")) {
        return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    }
    if ($inicio === "\x89PNG\r\n\x1A\n") {
        return ['mime' => 'image/png', 'ext' => 'png'];
    }
    return null;
}

/**
 * Confere o arquivo enviado sem gravar nada (no cadastro, todos os documentos são validados antes de criar a conta).
 * @return string|null Mensagem de erro, ou null se o arquivo serve.
 */
function validar_documento(mixed $arquivo, string $perfil): ?string
{
    $qual = ' de ' . mb_strtolower(PERFIS[$perfil]['nome']);
    if (!is_array($arquivo) || !isset($arquivo['error']) || is_array($arquivo['error'])
        || $arquivo['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Envie o documento de comprovação' . $qual . '.';
    }
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'O documento' . $qual . ' excede o tamanho máximo de 5 MB.';
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        return 'Falha no envio do documento' . $qual . '. Tente novamente.';
    }
    if ($arquivo['size'] > DOC_MAX_BYTES) {
        return 'O documento' . $qual . ' excede o tamanho máximo de 5 MB.';
    }
    if (detectar_tipo_documento($arquivo['tmp_name']) === null) {
        return 'Formato não aceito no documento' . $qual . '. Envie PDF, JPG ou PNG.';
    }
    return null;
}

/**
 * Valida e grava a comprovação de um perfil (PCD ou IDOSO), colocando esse perfil em análise.
 * Cria o perfil se a pessoa ainda não o tinha.
 * @return string|null Mensagem de erro, ou null em caso de sucesso.
 */
function salvar_comprovacao(int $usuario_id, string $perfil, mixed $arquivo): ?string
{
    if (!in_array($perfil, PERFIS_COM_COMPROVACAO, true)) {
        return 'Este perfil não precisa de comprovação.';
    }
    $erro = validar_documento($arquivo, $perfil);
    if ($erro !== null) {
        return $erro;
    }
    $tipo = detectar_tipo_documento($arquivo['tmp_name']);

    if (!is_dir(STORAGE_DOCUMENTOS) && !mkdir(STORAGE_DOCUMENTOS, 0750, true)) {
        return 'Erro interno ao armazenar o documento.';
    }
    $nome = bin2hex(random_bytes(16)) . '.' . $tipo['ext'];
    if (!move_uploaded_file($arquivo['tmp_name'], STORAGE_DOCUMENTOS . '/' . $nome)) {
        return 'Erro interno ao armazenar o documento.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO comprovacoes (usuario_id, perfil, arquivo, nome_original, mime, tamanho, status, criado_em)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$usuario_id, $perfil, $nome, mb_substr(basename((string) $arquivo['name']), 0, 255),
                   $tipo['mime'], (int) $arquivo['size'], 'em_analise', agora()]);
    $st = $pdo->prepare("UPDATE perfis SET status = 'em_analise', motivo_recusa = NULL, atualizado_em = ? WHERE usuario_id = ? AND tipo = ?");
    $st->execute([agora(), $usuario_id, $perfil]);
    if ($st->rowCount() === 0) {
        $pdo->prepare("INSERT INTO perfis (usuario_id, tipo, status, criado_em, atualizado_em) VALUES (?, ?, 'em_analise', ?, ?)")
            ->execute([$usuario_id, $perfil, agora(), agora()]);
    }
    $pdo->commit();

    registrar_log('comprovacao_enviada', $usuario_id, $perfil);
    return null;
}

/* ---------- Exclusão de conta (LGPD / exigência das lojas) ---------- */

/**
 * Apaga o usuário, suas comprovações (registros e arquivos) e tokens.
 * Os relatos são mantidos sem identificação, pois servem à manutenção da base de vagas.
 */
function excluir_conta(int $usuario_id): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT arquivo FROM comprovacoes WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $arquivos = $st->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE relatos SET usuario_id = NULL WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare('DELETE FROM api_tokens WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare('DELETE FROM uso_diario WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare('DELETE FROM perfis WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare('DELETE FROM comprovacoes WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuario_id]);
    $pdo->commit();

    foreach ($arquivos as $arquivo) {
        $caminho = STORAGE_DOCUMENTOS . '/' . basename($arquivo);
        if (is_file($caminho)) {
            unlink($caminho);
        }
    }
    registrar_log('conta_excluida', null, "usuario $usuario_id");
}
