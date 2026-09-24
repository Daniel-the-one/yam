// =============================================================
// yam-proxy.js — Reverse proxy unique pour Yam
// Regroupe l'API Laravel (port 8000) et Reverb WebSocket (port 6001)
// derrière UN SEUL port, pour qu'un lien unique suffise.
//
//   - Requêtes HTTP normales  → API (port 8000)
//   - WebSocket /app/{key}    → Reverb (port 6001)
//
// Usage : node yam-proxy.js [port]
// =============================================================
const http = require('http');
const net = require('net');

const LISTEN_PORT = parseInt(process.argv[2] || '8080', 10);
const API_PORT = 8000;
const WS_PORT = 6001;
const API_HOST = '127.0.0.1';
const WS_HOST = '127.0.0.1';

const server = http.createServer((req, res) => {
  // Requête HTTP normale → API
  const proxyReq = http.request({
    host: API_HOST,
    port: API_PORT,
    method: req.method,
    path: req.url,
    headers: { ...req.headers, host: req.headers.host || `127.0.0.1:${API_PORT}` },
  }, (proxyRes) => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers);
    proxyRes.pipe(res);
  });

  proxyReq.on('error', (err) => {
    console.error('[proxy] API error:', err.message);
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'text/plain' });
      res.end('Bad Gateway: API not reachable');
    } else {
      res.destroy();
    }
  });

  req.pipe(proxyReq);
});

// WebSocket upgrade → Reverb
server.on('upgrade', (req, socket, head) => {
  const targetPort = req.url.startsWith('/app/') ? WS_PORT : API_PORT;
  console.log(`[proxy] WS upgrade → :${targetPort} ${req.url}`);

  const proxySocket = net.connect(targetPort, WS_HOST, () => {
    proxySocket.write(head);
    // Reconstruire la requête d'upgrade vers Reverb
    proxySocket.write(
      `${req.method} ${req.url} HTTP/1.1\r\n` +
      Object.entries(req.headers)
        .map(([k, v]) => `${k}: ${v}`)
        .join('\r\n') +
      '\r\n\r\n'
    );
  });

  proxySocket.on('error', (err) => {
    console.error('[proxy] WS error:', err.message);
    socket.destroy();
  });

  socket.on('error', () => proxySocket.destroy());
  proxySocket.on('error', () => socket.destroy());

  proxySocket.pipe(socket);
  socket.pipe(proxySocket);
});

server.listen(LISTEN_PORT, '0.0.0.0', () => {
  console.log(`[proxy] Yam reverse proxy écoute sur :${LISTEN_PORT}`);
  console.log(`[proxy]   HTTP → API :${API_PORT}`);
  console.log(`[proxy]   WS /app/* → Reverb :${WS_PORT}`);
});
