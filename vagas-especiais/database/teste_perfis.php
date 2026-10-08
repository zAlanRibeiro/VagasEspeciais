<?php
/**
 * Teste rápido (sem dependências) da migração para perfis e das regras de visibilidade.
 *   php database/teste_perfis.php
 * Usa um banco SQLite temporário no formato antigo, roda o install.php nele e confere o resultado.
 */
declare(strict_types=1);

$arquivo = sys_get_temp_dir() . '/vagas_teste_perfis.sqlite';
@unlink($arquivo);
putenv('VAGAS_DB_DRIVER=sqlite');
putenv('VAGAS_DB_SQLITE=' . $arquivo);
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');

// 1. Banco no formato anterior (uma categoria e um status por usuário)
$pdo = new PDO('sqlite:' . $arquivo, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE usuarios (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
  senha_hash TEXT NOT NULL, categoria TEXT NULL CHECK (categoria IN ('PCD','IDOSO')), papel TEXT NOT NULL DEFAULT 'usuario',
  status TEXT NOT NULL DEFAULT 'pendente', motivo_recusa TEXT NULL, aceite_termos_em TEXT NULL, criado_em TEXT NOT NULL, atualizado_em TEXT NOT NULL)");
$pdo->exec("CREATE TABLE comprovacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  arquivo TEXT NOT NULL, nome_original TEXT NOT NULL, mime TEXT NOT NULL, tamanho INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'em_analise',
  observacao TEXT NULL, analisado_por INTEGER NULL, analisado_em TEXT NULL, criado_em TEXT NOT NULL)");
$pdo->exec("CREATE TABLE vagas (id INTEGER PRIMARY KEY AUTOINCREMENT, codigo TEXT NULL UNIQUE, logradouro TEXT NOT NULL, numero TEXT NULL,
  bairro TEXT NOT NULL, categoria TEXT NOT NULL CHECK (categoria IN ('PCD','IDOSO')), latitude REAL NOT NULL, longitude REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'ativa', atualizado_em TEXT NOT NULL)");
$pdo->exec("CREATE TABLE relatos (id INTEGER PRIMARY KEY AUTOINCREMENT, vaga_id INTEGER NOT NULL REFERENCES vagas(id) ON DELETE CASCADE,
  usuario_id INTEGER NULL, tipo TEXT NOT NULL, descricao TEXT NULL, status TEXT NOT NULL DEFAULT 'aberto', resposta_admin TEXT NULL,
  criado_em TEXT NOT NULL, atualizado_em TEXT NOT NULL)");
$t = '2026-01-01 10:00:00';
$pdo->exec("INSERT INTO usuarios VALUES (1,'Ana','a@t','x','PCD','usuario','aprovado',NULL,NULL,'$t','$t'),
                                        (2,'Bia','b@t','x','IDOSO','usuario','recusado','Documento ilegível',NULL,'$t','$t'),
                                        (3,'Adm','c@t','x',NULL,'admin','aprovado',NULL,NULL,'$t','$t')");
$pdo->exec("INSERT INTO comprovacoes (usuario_id, arquivo, nome_original, mime, tamanho, status, criado_em) VALUES (1,'f','f.png','image/png',1,'aprovado','$t')");
$pdo->exec("INSERT INTO vagas VALUES (1,'X1','Rua A','1','Centro','PCD',-22.9,-43.1,'ativa','$t')");
$pdo->exec("INSERT INTO relatos (vaga_id, tipo, criado_em, atualizado_em) VALUES (1,'outro','$t','$t')");
$pdo = null;

// 2. Migração (duas vezes: a segunda não pode mudar nada)
foreach ([1, 2] as $vez) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php'), $codigo);
    assert($codigo === 0, "install.php falhou na execução $vez");
}

// 3. Resultado
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config/database.php';
require APP_ROOT . '/includes/security.php';
require APP_ROOT . '/includes/dominio.php';
require APP_ROOT . '/includes/auth.php';

$perfis = db()->query('SELECT usuario_id, tipo, status, motivo_recusa FROM perfis ORDER BY usuario_id')->fetchAll();
$tipos = array_map(fn($p) => $p['usuario_id'] . ':' . $p['tipo'] . ':' . $p['status'], $perfis);
sort($tipos);
assert($tipos === ['1:PCD:aprovado', '1:ROTATIVO:aprovado', '2:IDOSO:recusado', '2:ROTATIVO:aprovado'],
       'perfil antigo convertido + rotativo para todos (nenhum para o admin)');
assert(db()->query("SELECT motivo_recusa FROM perfis WHERE tipo = 'IDOSO'")->fetchColumn() === 'Documento ilegível');
assert(db()->query('SELECT perfil FROM comprovacoes')->fetchColumn() === 'PCD');
assert((int) db()->query('SELECT COUNT(*) FROM relatos')->fetchColumn() === 1, 'recriar a tabela de vagas não pode apagar relatos');
db()->exec("INSERT INTO vagas (logradouro, bairro, categoria, latitude, longitude, atualizado_em) VALUES ('Rua B','Centro','ROTATIVO',-22.9,-43.1,'$t')");

$usuario = fn(int $id) => db()->query("SELECT * FROM usuarios WHERE id = $id")->fetch();
assert(categorias_visiveis($usuario(1)) === ['PCD', 'ROTATIVO']);
assert(categorias_visiveis($usuario(2)) === ['ROTATIVO'], 'idoso recusado continua com o rotativo');
assert(categorias_visiveis($usuario(3)) === array_keys(CATEGORIAS), 'admin vê tudo');
assert(perfil_aceita_documento(perfis_do_usuario(2)['IDOSO']), 'recusado pode reenviar');
assert(!vaga_visivel(1, $usuario(2)) && vaga_visivel(1, $usuario(1)));
db()->exec('UPDATE usuarios SET suspenso = 1 WHERE id = 1');
assert(categorias_visiveis($usuario(1)) === [], 'conta suspensa não vê nada');

@unlink($arquivo);
echo "✔ perfis: migração e regras de visibilidade ok\n";
