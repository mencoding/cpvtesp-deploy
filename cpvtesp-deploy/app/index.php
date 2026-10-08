<?php
// Cadastro mínimo da atividade de deploy da CPVTESP.
// Roda numa instância EC2 com Apache e PHP e grava num banco MySQL do RDS.
// A configuração vem de config.php, que o estudante cria a partir de config.example.php.
// O rodapé mostra dados do ambiente (instância, zona, servidor do banco e hora lida no banco):
// é a parte da captura de tela que comprova que a aplicação rodou na infraestrutura do estudante.
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';

/** Lê um item dos metadados da instância pelo IMDSv2; devolve "indisponível" fora da EC2. */
function metadado(string $caminho): string
{
    $pedeToken = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => "X-aws-ec2-metadata-token-ttl-seconds: 60\r\n",
        'timeout' => 1,
    ]]);
    $token = @file_get_contents('http://169.254.169.254/latest/api/token', false, $pedeToken);
    if ($token === false) {
        return 'indisponível';
    }
    $leDado = stream_context_create(['http' => [
        'header' => "X-aws-ec2-metadata-token: {$token}\r\n",
        'timeout' => 1,
    ]]);
    $valor = @file_get_contents("http://169.254.169.254/latest/meta-data/{$caminho}", false, $leDado);
    return $valor === false ? 'indisponível' : $valor;
}

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$erro = null;
$aviso = null;
$linhas = [];
$banco = ['servidor' => '-', 'versao' => '-', 'agora' => '-'];

try {
    // Tempo de conexão curto: sem regra de entrada no grupo do banco, a página mostra o erro em 5 s.
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['porta']};charset=utf8mb4",
        $cfg['usuario'],
        $cfg['senha'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    // O banco e a tabela nascem na primeira visita, para o foco ficar no deploy e não no SQL.
    $nomeBanco = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['banco']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$nomeBanco}`");
    $pdo->exec("USE `{$nomeBanco}`");
    $pdo->exec('CREATE TABLE IF NOT EXISTS cadastro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(200) NOT NULL,
        curso VARCHAR(200) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $curso = trim((string) ($_POST['curso'] ?? ''));
        if ($nome === '' || $curso === '' || strlen($nome) > 200 || strlen($curso) > 200) {
            $aviso = 'Preencha nome e curso, com até 200 caracteres cada.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO cadastro (nome, curso) VALUES (?, ?)');
            $stmt->execute([$nome, $curso]);
            // Redireciona depois de gravar, para recarregar a página não repetir o cadastro.
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), true, 303);
            exit;
        }
    }

    $linhas = $pdo->query('SELECT id, nome, curso, criado_em FROM cadastro ORDER BY id DESC LIMIT 20')
        ->fetchAll(PDO::FETCH_ASSOC);
    $banco = $pdo->query('SELECT @@hostname AS servidor, VERSION() AS versao, NOW() AS agora')
        ->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    // A mensagem do driver fica visível de propósito: é o ponto de partida do diagnóstico.
    $erro = $ex->getMessage();
}

$instancia = metadado('instance-id');
$zona = metadado('placement/availability-zone');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cadastro · CPVTESP</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 46rem; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; background: #fff; line-height: 1.5; }
  h1 { font-size: 1.6rem; }
  form { display: grid; gap: .75rem; margin: 1.5rem 0; }
  label { font-weight: 600; }
  input { font-size: 1rem; padding: .5rem; border: 1px solid #555; border-radius: 4px; }
  input:focus, button:focus { outline: 3px solid #0b57d0; outline-offset: 2px; }
  button { font-size: 1rem; padding: .6rem 1rem; background: #0b57d0; color: #fff; border: 0; border-radius: 4px; cursor: pointer; justify-self: start; }
  table { border-collapse: collapse; width: 100%; }
  th, td { text-align: left; padding: .4rem; border-bottom: 1px solid #ccc; }
  .erro { background: #fde8e8; border-left: 4px solid #b00020; padding: .75rem; }
  .aviso { background: #fff4d6; border-left: 4px solid #8a5a00; padding: .75rem; }
  footer { margin-top: 2rem; font-size: .9rem; color: #333; border-top: 1px solid #ccc; padding-top: 1rem; }
  code { font-size: .9rem; }
  .dica { margin: -.4rem 0 .2rem; font-size: .9rem; color: #333; }
  .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
</head>
<body>
<main>
  <h1>Cadastro</h1>

  <?php if ($erro !== null): ?>
    <div class="erro" role="alert">
      <p><strong>A aplicação não conseguiu falar com o banco.</strong></p>
      <p><code><?= e($erro) ?></code></p>
    </div>
  <?php else: ?>
    <?php if ($aviso !== null): ?>
      <div class="aviso" role="status"><?= e($aviso) ?></div>
    <?php endif; ?>
    <form method="post">
      <label for="nome">Nome</label>
      <input id="nome" name="nome" maxlength="200" required autocomplete="off" aria-describedby="dica-nome">
      <p id="dica-nome" class="dica">Primeiro nome e prontuário. Esta página é pública enquanto a instância existir.</p>
      <label for="curso">Curso</label>
      <input id="curso" name="curso" maxlength="200" required>
      <button type="submit">Cadastrar</button>
    </form>

    <h2>Últimos cadastros</h2>
    <?php if ($linhas === []): ?>
      <p>Nenhum cadastro ainda.</p>
    <?php else: ?>
      <table>
        <caption class="visually-hidden">Últimos 20 cadastros gravados no banco</caption>
        <thead><tr><th scope="col">Nº</th><th scope="col">Nome</th><th scope="col">Curso</th><th scope="col">Gravado em</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
          <tr><td><?= e((string) $l['id']) ?></td><td><?= e($l['nome']) ?></td><td><?= e($l['curso']) ?></td><td><?= e((string) $l['criado_em']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</main>

<footer>
  <p><strong>Ambiente</strong></p>
  <p>Instância: <code><?= e($instancia) ?></code> · Zona: <code><?= e($zona) ?></code></p>
  <p>Banco configurado: <code><?= e((string) $cfg['host']) ?></code></p>
  <p>Servidor do banco: <code><?= e((string) $banco['servidor']) ?></code> · Versão: <code><?= e((string) $banco['versao']) ?></code> · Hora no banco: <code><?= e((string) $banco['agora']) ?></code></p>
</footer>
</body>
</html>
