<?php
/**
 * Leitura e aplicação da base de vagas (CSV ou Excel .xlsx), sem bibliotecas externas.
 */
declare(strict_types=1);

/** Nomes aceitos no cabeçalho da planilha → campo interno. */
const COLUNAS_IMPORTACAO = [
    'id' => 'codigo', 'codigo' => 'codigo', 'cod' => 'codigo', 'idvaga' => 'codigo',
    'logradouro' => 'logradouro', 'endereco' => 'logradouro', 'rua' => 'logradouro', 'via' => 'logradouro',
    'numero' => 'numero', 'num' => 'numero', 'n' => 'numero', 'no' => 'numero', 'nro' => 'numero',
    'bairro' => 'bairro',
    'categoria' => 'categoria', 'tipo' => 'categoria', 'tipovaga' => 'categoria',
    'latitude' => 'latitude', 'lat' => 'latitude',
    'longitude' => 'longitude', 'lng' => 'longitude', 'lon' => 'longitude', 'long' => 'longitude',
    'status' => 'status', 'situacao' => 'status',
];

const COLUNAS_OBRIGATORIAS = ['logradouro', 'bairro', 'categoria', 'latitude', 'longitude'];

/**
 * Lê a planilha e devolve as linhas como arrays associativos (campo interno → valor).
 * Cada linha inclui '_linha' com o número da linha no arquivo.
 * @throws RuntimeException
 */
function ler_planilha(string $caminho, string $nome_original): array
{
    $ext = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
    $matriz = match ($ext) {
        'csv', 'txt' => ler_csv($caminho),
        'xlsx'       => ler_xlsx($caminho),
        default      => throw new RuntimeException('Formato não suportado. Envie um arquivo .csv ou .xlsx.'),
    };

    if (count($matriz) < 2) {
        throw new RuntimeException('A planilha está vazia ou só tem o cabeçalho.');
    }

    $cabecalho = array_shift($matriz);
    $mapa = [];
    foreach ($cabecalho as $i => $nome) {
        $chave = preg_replace('/[^a-z0-9]/', '', sem_acentos((string) $nome));
        if (isset(COLUNAS_IMPORTACAO[$chave]) && !in_array(COLUNAS_IMPORTACAO[$chave], $mapa, true)) {
            $mapa[$i] = COLUNAS_IMPORTACAO[$chave];
        }
    }
    $faltando = array_diff(COLUNAS_OBRIGATORIAS, $mapa);
    if ($faltando) {
        throw new RuntimeException('Colunas obrigatórias ausentes no cabeçalho: ' . implode(', ', $faltando) . '.');
    }

    $linhas = [];
    foreach ($matriz as $n => $valores) {
        $linha = ['_linha' => $n + 2];
        $vazia = true;
        foreach ($mapa as $i => $campo) {
            $valor = trim((string) ($valores[$i] ?? ''));
            $linha[$campo] = $valor;
            $vazia = $vazia && $valor === '';
        }
        if (!$vazia) {
            $linhas[] = $linha;
        }
    }
    return $linhas;
}

function ler_csv(string $caminho): array
{
    $conteudo = (string) file_get_contents($caminho);
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    // Excel no Windows costuma salvar CSV em Windows-1252.
    if (!preg_match('//u', $conteudo)) {
        $conteudo = (string) iconv('Windows-1252', 'UTF-8//IGNORE', $conteudo);
    }

    $primeira = strtok($conteudo, "\r\n") ?: '';
    $contagens = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")];
    arsort($contagens);
    $separador = (string) array_key_first($contagens);

    $h = fopen('php://temp', 'r+');
    fwrite($h, $conteudo);
    rewind($h);
    $matriz = [];
    while (($linha = fgetcsv($h, 0, $separador, '"', '')) !== false) {
        if ($linha !== [null]) {
            $matriz[] = $linha;
        }
    }
    fclose($h);
    return $matriz;
}

function ler_xlsx(string $caminho): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Leitura de Excel indisponível (habilite a extensão zip do PHP) — envie em CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo Excel.');
    }

    $compartilhadas = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $sst = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sst->si as $si) {
            if (isset($si->t)) {
                $compartilhadas[] = (string) $si->t;
            } else {
                $texto = '';
                foreach ($si->r as $r) {
                    $texto .= (string) $r->t;
                }
                $compartilhadas[] = $texto;
            }
        }
    }

    $planilha = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($planilha === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $nome)) {
                $planilha = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if ($planilha === false) {
        throw new RuntimeException('Nenhuma planilha encontrada no arquivo Excel.');
    }

    $sheet = simplexml_load_string($planilha, 'SimpleXMLElement', LIBXML_NONET);
    $matriz = [];
    foreach ($sheet->sheetData->row as $row) {
        $linha = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = coluna_excel_para_indice(preg_replace('/\d+/', '', $ref));
            $tipo = (string) $c['t'];
            $valor = match ($tipo) {
                's'         => $compartilhadas[(int) $c->v] ?? '',
                'inlineStr' => (string) $c->is->t,
                default     => (string) $c->v,
            };
            $linha[$col] = $valor;
        }
        if ($linha) {
            $max = max(array_keys($linha));
            $matriz[] = array_replace(array_fill(0, $max + 1, ''), $linha);
        }
    }
    return $matriz;
}

function coluna_excel_para_indice(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) {
        $n = $n * 26 + (ord($l) - 64);
    }
    return $n - 1;
}

/**
 * Valida as linhas lidas. Devolve ['validas' => [...], 'erros' => [['linha' => n, 'erros' => [...]]]].
 */
function validar_importacao(array $linhas): array
{
    $validas = [];
    $erros = [];
    $codigos = [];
    foreach ($linhas as $l) {
        [$vaga, $errosLinha] = validar_vaga($l);
        if ($vaga['codigo'] !== null) {
            if (isset($codigos[$vaga['codigo']])) {
                $errosLinha[] = 'ID repetido (também na linha ' . $codigos[$vaga['codigo']] . ')';
            } else {
                $codigos[$vaga['codigo']] = $l['_linha'];
            }
        }
        if ($errosLinha) {
            $erros[] = ['linha' => $l['_linha'], 'erros' => $errosLinha, 'dados' => $l];
        } else {
            $validas[] = $vaga;
        }
    }
    return ['validas' => $validas, 'erros' => $erros];
}

function chave_coordenada(float $lat, float $lng, string $categoria): string
{
    return sprintf('%.6f|%.6f|%s', $lat, $lng, $categoria);
}

/**
 * Grava as vagas válidas: atualiza pelo ID da base (ou pela coordenada, quando não há ID) e insere as novas.
 * @return array{inseridas:int, atualizadas:int, inativadas:int}
 */
function aplicar_importacao(array $vagas, bool $inativar_ausentes): array
{
    $pdo = db();
    $porCodigo = [];
    $porCoordenada = [];
    foreach ($pdo->query('SELECT id, codigo, latitude, longitude, categoria FROM vagas') as $v) {
        if ($v['codigo'] !== null) {
            $porCodigo[$v['codigo']] = (int) $v['id'];
        }
        $porCoordenada[chave_coordenada((float) $v['latitude'], (float) $v['longitude'], $v['categoria'])] = (int) $v['id'];
    }

    $ins = $pdo->prepare('INSERT INTO vagas (codigo, logradouro, numero, bairro, categoria, latitude, longitude, status, atualizado_em)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE vagas SET codigo = COALESCE(?, codigo), logradouro = ?, numero = ?, bairro = ?, categoria = ?,
                          latitude = ?, longitude = ?, status = ?, atualizado_em = ? WHERE id = ?');

    $res = ['inseridas' => 0, 'atualizadas' => 0, 'inativadas' => 0];
    $tocadas = [];
    $agora = agora();

    $pdo->beginTransaction();
    try {
        foreach ($vagas as $v) {
            $id = $v['codigo'] !== null
                ? ($porCodigo[$v['codigo']] ?? null)
                : ($porCoordenada[chave_coordenada($v['latitude'], $v['longitude'], $v['categoria'])] ?? null);
            $dados = [$v['logradouro'], $v['numero'], $v['bairro'], $v['categoria'], $v['latitude'], $v['longitude'], $v['status'], $agora];

            if ($id !== null) {
                $upd->execute(array_merge([$v['codigo']], $dados, [$id]));
                $res['atualizadas']++;
            } else {
                $ins->execute(array_merge([$v['codigo']], $dados));
                $id = (int) $pdo->lastInsertId();
                $res['inseridas']++;
                $porCoordenada[chave_coordenada($v['latitude'], $v['longitude'], $v['categoria'])] = $id;
            }
            $tocadas[$id] = true;
        }

        if ($inativar_ausentes) {
            $inat = $pdo->prepare("UPDATE vagas SET status = 'inativa', atualizado_em = ? WHERE id = ?");
            foreach ($pdo->query("SELECT id FROM vagas WHERE status = 'ativa'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!isset($tocadas[(int) $id])) {
                    $inat->execute([$agora, $id]);
                    $res['inativadas']++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $res;
}
