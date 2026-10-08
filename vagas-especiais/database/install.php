<?php
/**
 * Instalação pela linha de comando.
 *
 *   php database/install.php                       cria as tabelas
 *   php database/install.php --admin=EMAIL --senha=SENHA [--nome="Nome"]
 *   php database/install.php --exemplo             importa database/exemplo_vagas.csv (dados fictícios)
 *
 * As opções podem ser combinadas. Use VAGAS_DB_DRIVER=sqlite para desenvolvimento local.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');
define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/config/database.php';
require APP_ROOT . '/includes/security.php';
require APP_ROOT . '/includes/dominio.php';
require APP_ROOT . '/includes/importador.php';

$op = getopt('', ['admin:', 'senha:', 'nome:', 'exemplo']);

function executar_sql(PDO $pdo, string $arquivo): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($arquivo));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $comando) {
        $pdo->exec($comando);
    }
}

// 1. Tabelas
if (DB_DRIVER === 'sqlite') {
    executar_sql(db(), __DIR__ . '/schema.sqlite.sql');
    echo "✔ Banco SQLite pronto em " . DB_SQLITE_PATH . "\n";
} else {
    $servidor = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = str_replace('vagas_especiais', DB_NAME, (string) file_get_contents(__DIR__ . '/schema.sql'));
    $tmp = tempnam(sys_get_temp_dir(), 'schema');
    file_put_contents($tmp, $sql);
    executar_sql($servidor, $tmp);
    unlink($tmp);
    echo "✔ Banco MySQL '" . DB_NAME . "' pronto\n";
}

// 1b. Atualiza bancos criados por versões anteriores (pode rodar de novo sem efeito)
migrar_para_perfis(db());

function coluna_existe(PDO $pdo, string $tabela, string $coluna): bool
{
    if (DB_DRIVER === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info($tabela)") as $c) {
            if ($c['name'] === $coluna) {
                return true;
            }
        }
        return false;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabela, $coluna]);
    return (bool) $st->fetchColumn();
}

/** SQLite não altera CHECK de coluna: recria a tabela com a nova definição, preservando os dados. */
function recriar_tabela_sqlite(PDO $pdo, string $tabela, string $ddl): void
{
    $colunas = implode(', ', array_map(fn($c) => '"' . $c['name'] . '"', $pdo->query("PRAGMA table_info($tabela)")->fetchAll()));
    $pdo->exec('PRAGMA foreign_keys = OFF');   // sem isso, apagar a tabela antiga apagaria os relatos em cascata
    $pdo->beginTransaction();
    $pdo->exec(preg_replace('/^CREATE TABLE\s+"?' . $tabela . '"?/i', 'CREATE TABLE ' . $tabela . '_nova', $ddl));
    $pdo->exec("INSERT INTO {$tabela}_nova ($colunas) SELECT $colunas FROM $tabela");
    $pdo->exec("DROP TABLE $tabela");
    $pdo->exec("ALTER TABLE {$tabela}_nova RENAME TO $tabela");
    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
}

/**
 * Versão com perfis: a categoria/status únicos do usuário viram linhas em `perfis`, a comprovação passa a
 * dizer qual perfil comprova, a suspensão vira `usuarios.suspenso` e as vagas ganham o tipo ROTATIVO.
 */
function migrar_para_perfis(PDO $pdo): void
{
    $sqlite = DB_DRIVER === 'sqlite';

    // Vagas: tipo ROTATIVO
    if ($sqlite) {
        $ddl = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'vagas'")->fetchColumn();
        if (!str_contains($ddl, 'ROTATIVO')) {
            recriar_tabela_sqlite($pdo, 'vagas', str_replace("IN ('PCD','IDOSO')", "IN ('PCD','IDOSO','ROTATIVO')", $ddl));
            executar_sql($pdo, __DIR__ . '/schema.sqlite.sql');   // recria os índices da tabela
            echo "✔ Vagas: tipo ROTATIVO adicionado\n";
        }
    } else {
        $tipo = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vagas' AND COLUMN_NAME = 'categoria'")->fetchColumn();
        if (!str_contains($tipo, 'ROTATIVO')) {
            $pdo->exec("ALTER TABLE vagas MODIFY categoria ENUM('PCD','IDOSO','ROTATIVO') NOT NULL");
            echo "✔ Vagas: tipo ROTATIVO adicionado\n";
        }
    }

    if (!coluna_existe($pdo, 'usuarios', 'suspenso')) {
        $pdo->exec($sqlite ? 'ALTER TABLE usuarios ADD COLUMN suspenso INTEGER NOT NULL DEFAULT 0'
                           : 'ALTER TABLE usuarios ADD COLUMN suspenso TINYINT(1) NOT NULL DEFAULT 0 AFTER papel');
    }
    if (!coluna_existe($pdo, 'comprovacoes', 'perfil')) {
        $pdo->exec($sqlite ? "ALTER TABLE comprovacoes ADD COLUMN perfil TEXT NULL CHECK (perfil IN ('PCD','IDOSO'))"
                           : "ALTER TABLE comprovacoes ADD COLUMN perfil ENUM('PCD','IDOSO') NULL AFTER usuario_id");
    }

    // Usuários do modelo antigo (uma categoria e um status por pessoa)
    if (coluna_existe($pdo, 'usuarios', 'categoria')) {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO perfis (usuario_id, tipo, status, motivo_recusa, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($pdo->query("SELECT id, categoria, status, motivo_recusa, criado_em, atualizado_em FROM usuarios
                              WHERE categoria IS NOT NULL AND papel = 'usuario'
                                AND id NOT IN (SELECT usuario_id FROM perfis)")->fetchAll() as $u) {
            $ins->execute([$u['id'], $u['categoria'], $u['status'], $u['motivo_recusa'], $u['criado_em'], $u['atualizado_em']]);
            $n++;
        }
        $pdo->exec('UPDATE comprovacoes SET perfil = (SELECT u.categoria FROM usuarios u WHERE u.id = comprovacoes.usuario_id)
                    WHERE perfil IS NULL');
        $pdo->commit();
        foreach (['categoria', 'status', 'motivo_recusa'] as $coluna) {
            $pdo->exec("ALTER TABLE usuarios DROP COLUMN $coluna");
        }
        echo "✔ Perfis: $n usuário(s) convertidos para o modelo com perfis\n";
    }

    // O rotativo é de todos (depois da conversão acima, que só converte quem ainda não tem perfil nenhum)
    $n = $pdo->exec("INSERT INTO perfis (usuario_id, tipo, status, criado_em, atualizado_em)
                     SELECT u.id, 'ROTATIVO', 'aprovado', u.criado_em, u.criado_em FROM usuarios u
                     WHERE u.papel = 'usuario' AND NOT EXISTS (SELECT 1 FROM perfis p WHERE p.usuario_id = u.id AND p.tipo = 'ROTATIVO')");
    if ($n) {
        echo "✔ Rotativo liberado para $n usuário(s)\n";
    }
}

// 2. Administrador
if (isset($op['admin'])) {
    $email = mb_strtolower(trim((string) $op['admin']));
    $senha = (string) ($op['senha'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($senha) < 8) {
        fwrite(STDERR, "✘ Informe --admin com e-mail válido e --senha com pelo menos 8 caracteres.\n");
        exit(1);
    }
    $st = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    if ($id = $st->fetchColumn()) {
        db()->prepare("UPDATE usuarios SET papel = 'admin', suspenso = 0, senha_hash = ?, atualizado_em = ? WHERE id = ?")
            ->execute([password_hash($senha, PASSWORD_DEFAULT), agora(), $id]);
        echo "✔ Usuário existente promovido a administrador: $email\n";
    } else {
        db()->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel, criado_em, atualizado_em)
                       VALUES (?, ?, ?, 'admin', ?, ?)")
            ->execute([(string) ($op['nome'] ?? 'Administrador'), $email, password_hash($senha, PASSWORD_DEFAULT), agora(), agora()]);
        echo "✔ Administrador criado: $email\n";
    }
}

// 3. Vagas de exemplo
if (isset($op['exemplo'])) {
    $linhas = ler_planilha(__DIR__ . '/exemplo_vagas.csv', 'exemplo_vagas.csv');
    $v = validar_importacao($linhas);
    foreach ($v['erros'] as $er) {
        echo "  linha {$er['linha']}: " . implode('; ', $er['erros']) . "\n";
    }
    $r = aplicar_importacao($v['validas'], false);
    echo "✔ Vagas de exemplo: {$r['inseridas']} inseridas, {$r['atualizadas']} atualizadas\n";
}
