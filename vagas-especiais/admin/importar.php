<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/admin_auth.php';
require APP_ROOT . '/includes/importador.php';

const IMPORT_MAX_BYTES = 10 * 1024 * 1024;

// Modelo de planilha para download.
if (isset($_GET['modelo'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_vagas.csv"');
    echo "\xEF\xBB\xBF";
    echo "id;logradouro;numero;bairro;categoria;latitude;longitude;status\n";
    echo "NIT-0001;Rua Gavião Peixoto;100;Icaraí;PCD;-22.9042;-43.1089;ativa\n";
    echo "NIT-0002;Rua da Conceição;25;Centro;IDOSO;-22.8946;-43.1239;ativa\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'analisar') {
        unset($_SESSION['importacao']);
        $arq = $_FILES['arquivo'] ?? null;
        if (!is_array($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
            flash('erro', 'Selecione o arquivo da base (CSV ou XLSX).');
            redirecionar('importar.php');
        }
        if ($arq['size'] > IMPORT_MAX_BYTES) {
            flash('erro', 'Arquivo maior que 10 MB.');
            redirecionar('importar.php');
        }
        try {
            $linhas = ler_planilha($arq['tmp_name'], (string) $arq['name']);
        } catch (RuntimeException $e) {
            flash('erro', $e->getMessage());
            redirecionar('importar.php');
        }
        $resultado = validar_importacao($linhas);
        $_SESSION['importacao'] = [
            'arquivo' => mb_substr(basename((string) $arq['name']), 0, 120),
            'total'   => count($linhas),
            'validas' => $resultado['validas'],
            'erros'   => array_slice($resultado['erros'], 0, 500),
            'n_erros' => count($resultado['erros']),
        ];
        registrar_log('importacao_analisada', (int) $admin['id'], $_SESSION['importacao']['arquivo']);
        redirecionar('importar.php?previa=1');
    }

    if ($acao === 'confirmar' && !empty($_SESSION['importacao']['validas'])) {
        $imp = $_SESSION['importacao'];
        unset($_SESSION['importacao']);
        $r = aplicar_importacao($imp['validas'], !empty($_POST['inativar_ausentes']));
        registrar_log('importacao_aplicada', (int) $admin['id'],
            "{$imp['arquivo']}: {$r['inseridas']} novas, {$r['atualizadas']} atualizadas, {$r['inativadas']} inativadas");
        flash('ok', "Base importada: {$r['inseridas']} vagas novas, {$r['atualizadas']} atualizadas"
            . ($r['inativadas'] ? ", {$r['inativadas']} inativadas por não constarem no arquivo" : '') . '.');
        redirecionar('vagas.php');
    }

    unset($_SESSION['importacao']);
    redirecionar('importar.php');
}

$imp = isset($_GET['previa']) ? ($_SESSION['importacao'] ?? null) : null;

admin_inicio('Importar base de vagas', 'importar');

if ($imp): ?>
  <section class="bloco">
    <h2>Prévia: <?= e($imp['arquivo']) ?></h2>
    <div class="indicadores">
      <div class="indicador"><strong><?= (int) $imp['total'] ?></strong><span>Linhas lidas</span></div>
      <div class="indicador"><strong><?= count($imp['validas']) ?></strong><span>Válidas</span></div>
      <div class="indicador<?= $imp['n_erros'] ? ' indicador-alerta' : '' ?>"><strong><?= (int) $imp['n_erros'] ?></strong><span>Com erro (serão ignoradas)</span></div>
    </div>

    <?php if ($imp['validas']): ?>
      <form method="post" class="form-admin">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="confirmar">
        <label class="opcao">
          <input type="checkbox" name="inativar_ausentes" value="1">
          Inativar as vagas ativas que <strong>não</strong> estão neste arquivo (use quando o arquivo for a base completa)
        </label>
        <div class="acoes-linha acoes-form">
          <button class="botao botao-primario" type="submit">Importar <?= count($imp['validas']) ?> vaga(s)</button>
          <button class="botao" type="submit" name="acao" value="cancelar">Cancelar</button>
        </div>
      </form>
    <?php else: ?>
      <p>Nenhuma linha válida para importar.</p>
      <p><a class="botao" href="importar.php">Enviar outro arquivo</a></p>
    <?php endif; ?>
  </section>

  <?php if ($imp['erros']): ?>
  <section class="bloco">
    <h2>Linhas com erro</h2>
    <table>
      <thead><tr><th>Linha</th><th>Problema</th><th>Logradouro</th><th>Bairro</th><th>Categoria</th><th>Lat</th><th>Lng</th></tr></thead>
      <tbody>
      <?php foreach ($imp['erros'] as $er): ?>
        <tr class="linha-erro">
          <td><?= (int) $er['linha'] ?></td>
          <td><?= e(implode('; ', $er['erros'])) ?></td>
          <td><?= e($er['dados']['logradouro'] ?? '') ?></td>
          <td><?= e($er['dados']['bairro'] ?? '') ?></td>
          <td><?= e($er['dados']['categoria'] ?? '') ?></td>
          <td><?= e($er['dados']['latitude'] ?? '') ?></td>
          <td><?= e($er['dados']['longitude'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($imp['n_erros'] > count($imp['erros'])): ?><p class="dica">Exibindo os primeiros <?= count($imp['erros']) ?> erros.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($imp['validas']): ?>
  <section class="bloco">
    <h2>Amostra das linhas válidas</h2>
    <table>
      <thead><tr><th>ID</th><th>Logradouro</th><th>Nº</th><th>Bairro</th><th>Categoria</th><th>Lat</th><th>Lng</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($imp['validas'], 0, 20) as $v): ?>
        <tr>
          <td><?= e((string) $v['codigo']) ?></td><td><?= e($v['logradouro']) ?></td><td><?= e((string) $v['numero']) ?></td>
          <td><?= e($v['bairro']) ?></td><td><?= e($v['categoria']) ?></td>
          <td><?= e((string) $v['latitude']) ?></td><td><?= e((string) $v['longitude']) ?></td><td><?= e($v['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

<?php else: ?>
  <section class="bloco">
    <form method="post" enctype="multipart/form-data" class="form-admin">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="analisar">
      <label for="arquivo">Arquivo da base (CSV ou Excel .xlsx, até 10 MB)</label>
      <input id="arquivo" name="arquivo" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <button class="botao botao-primario" type="submit">Analisar arquivo</button>
    </form>
  </section>

  <section class="bloco texto">
    <h2>Formato esperado</h2>
    <p>A primeira linha deve ser o cabeçalho. Colunas reconhecidas (maiúsculas e acentos são ignorados):</p>
    <table>
      <thead><tr><th>Coluna</th><th>Obrigatória</th><th>Observação</th></tr></thead>
      <tbody>
        <tr><td>id</td><td>Recomendada</td><td>ID da vaga na base Nittrans. Usado para atualizar a vaga em importações futuras.</td></tr>
        <tr><td>logradouro</td><td>Sim</td><td>Também aceita “endereco” ou “rua”.</td></tr>
        <tr><td>numero</td><td>Não</td><td>Número ou referência.</td></tr>
        <tr><td>bairro</td><td>Sim</td><td></td></tr>
        <tr><td>categoria</td><td>Sim</td><td>PCD, IDOSO ou ROTATIVO (aceita variações como “Deficiente”, “Idosos”, “Rotativa”).</td></tr>
        <tr><td>latitude / longitude</td><td>Sim</td><td>Graus decimais; vírgula ou ponto. Devem estar dentro de Niterói.</td></tr>
        <tr><td>status</td><td>Não</td><td>“ativa” (padrão) ou “inativa”.</td></tr>
      </tbody>
    </table>
    <p>CSV separado por ponto e vírgula, vírgula ou tabulação; codificação UTF-8 ou a padrão do Excel.</p>
    <p><a class="botao" href="importar.php?modelo=1">Baixar planilha modelo</a></p>
    <p class="dica">O arquivo é validado antes da gravação: você verá uma prévia com os erros e só então confirma a importação.</p>
  </section>
<?php endif;

admin_fim();
