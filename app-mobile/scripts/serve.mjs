/**
 * Servidor estático para testar as telas do app no navegador: http://localhost:8100
 * (no navegador, GPS e armazenamento usam as APIs web em vez dos plugins nativos).
 */
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const www = fileURLToPath(new URL('../www', import.meta.url));
const tipos = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.json': 'application/json', '.map': 'application/json', '.txt': 'text/plain' };
const porta = Number(process.env.PORT || 8100);

createServer(async (req, res) => {
  const caminho = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^[/\\]+/, '');
  const arquivo = join(www, caminho || 'index.html');
  if (!arquivo.startsWith(www)) { res.writeHead(403).end(); return; }
  try {
    const conteudo = await readFile(arquivo);
    res.writeHead(200, { 'Content-Type': tipos[extname(arquivo)] || 'application/octet-stream' }).end(conteudo);
  } catch {
    res.writeHead(404).end('não encontrado');
  }
}).listen(porta, '127.0.0.1', () => console.log('App em http://localhost:' + porta));
