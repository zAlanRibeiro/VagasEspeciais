<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/layout.php';

cabecalho('Privacidade e termos', ['classe_body' => 'pagina-centro']);
?>
<main id="conteudo" class="cartao cartao-largo texto">
  <a class="voltar" href="index.php">← Início</a>
  <h1>Política de privacidade e termos de uso</h1>
  <p class="aviso"><strong>Texto provisório do MVP.</strong> A versão final deve ser revisada pelo
     encarregado de dados (DPO) e pela assessoria jurídica responsável.</p>

  <h2>Quais dados coletamos</h2>
  <ul>
    <li>Nome, e-mail e senha (armazenada apenas em formato criptografado/hash).</li>
    <li>Perfis (o rotativo, comum a todos, e, se escolhidos, pessoa com deficiência e/ou pessoa idosa) e o documento de comprovação destes dois.</li>
    <li>Registros de acesso (data, hora, IP e navegador), para segurança do sistema.</li>
    <li>Relatos de problemas enviados sobre as vagas.</li>
  </ul>
  <p>Sua localização é usada no aparelho para mostrar as vagas próximas. Ela só sai do aparelho quando você pede
     uma <strong>rota até a vaga</strong>: aí o ponto de partida é enviado ao nosso servidor e ao serviço de rotas do
     OpenStreetMap (Valhalla, mantido pela FOSSGIS) apenas para calcular o caminho. A rota fica guardada por até
     24 horas, sem ligação com o seu nome ou e-mail, para agilizar pedidos iguais; depois é apagada.</p>
  <p>Quando você busca um endereço no mapa, o texto digitado é enviado ao serviço de endereços do OpenStreetMap
     (Nominatim) para encontrar o local. Nenhum dado pessoal seu acompanha essa busca.</p>
  <p><strong>Estatísticas de uso.</strong> Para planejar onde faltam vagas, contamos de forma agregada e anônima
     quantas vezes cada vaga é aberta, recebe uma rota ou é alcançada. Esses números são somas por dia, sem o seu
     nome, e-mail ou localização. Quando você busca um destino e não há vaga especial a menos de 1 km, guardamos
     o nome do lugar buscado e a posição arredondada (cerca de 100 metros), também sem identificar quem buscou.
     Para contar quantas pessoas usam o mapa, registramos apenas os dias em que cada conta abriu o mapa; esse
     registro é apagado após 90 dias ou quando a conta é excluída.</p>

  <h2>Finalidade</h2>
  <p>Os dados são usados exclusivamente para verificar o direito de uso das vagas especiais, liberar o acesso
     ao aplicativo e manter a base de vagas atualizada (base legal: art. 7º e art. 11 da LGPD — Lei 13.709/2018).</p>

  <h2>Armazenamento e acesso</h2>
  <p>Os documentos ficam em área privada do servidor, acessível somente por administradores autorizados.
     Todos os acessos administrativos são registrados.</p>

  <h2>Seus direitos e exclusão da conta</h2>
  <p>Você pode solicitar acesso, correção ou exclusão dos seus dados pelos canais oficiais da Nittrans.</p>
  <p id="excluir-conta"><strong>Para excluir sua conta</strong>, entre no aplicativo ou neste site e acesse
     <em>Perfil → Excluir minha conta</em>. Nome, e-mail, senha e documentos de comprovação são apagados na hora;
     os relatos de problemas nas vagas permanecem sem identificação. Registros de acesso são mantidos pelo prazo
     exigido por lei para segurança do sistema.</p>
  <p>Sua localização não fica registrada na sua conta. Durante a navegação até a vaga, ela é acompanhada só no aparelho.</p>

  <h2>Termos de uso</h2>
  <p>As informações das vagas vêm da base institucional e podem conter divergências. Respeite sempre a
     sinalização no local. A navegação é feita por aplicativos externos (Waze/Google Maps), sujeitos aos seus próprios termos.
     O uso indevido de vaga especial é infração de trânsito, conforme o Código de Trânsito Brasileiro.</p>

  <h2>Créditos</h2>
  <p>Mapas, nomes de ruas e lugares: © colaboradores do <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>,
     dados disponíveis sob a licença ODbL. Fonte Nunito sob licença SIL Open Font License.</p>
</main>
<?php
rodape();
