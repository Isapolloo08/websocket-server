// lib/ws-server.js - Persistent WebSocket server for real-time temperature updates
const http = require('http');
const express = require('express');
const { WebSocketServer } = require('ws');

const app = express();
app.use(express.json());

const server = http.createServer(app);
const wss = new WebSocketServer({ noServer: true });

// Track connected clients
let clientCount = 0;

wss.on('connection', (ws, req) => {
  clientCount++;
  const clientIp = req.socket.remoteAddress;
  console.log(`[WS] Client connected: ${clientIp} (total: ${clientCount})`);
  
  // Send welcome message
  ws.send(JSON.stringify({ 
    type: 'connected', 
    clientId: `client_${Date.now()}`,
    timestamp: Date.now() 
  }));

  ws.on('message', (data) => {
    console.log(`[WS] Message from ${clientIp}:`, data.toString().substring(0, 100));
  });

  ws.on('close', () => {
    clientCount--;
    console.log(`[WS] Client disconnected: ${clientIp} (remaining: ${clientCount})`);
  });

  ws.on('error', (err) => {
    console.error(`[WS] Error:`, err.message);
  });
});

// HTTP upgrade handler - accept WebSocket upgrades on any path
server.on('upgrade', (req, socket, head) => {
  console.log(`[HTTP] Upgrade request: ${req.url}`);
  wss.handleUpgrade(req, socket, head, (ws) => {
    wss.emit('connection', ws, req);
  });
});

// HTTP POST /broadcast endpoint - receives from PHP, broadcasts to all connected WebSocket clients
app.post('/broadcast', (req, res) => {
  const payload = JSON.stringify({
    type: 'temperature_update',
    data: req.body,
    timestamp: Date.now()
  });
  
  console.log(`[BROADCAST] Payload:`, JSON.stringify(req.body).substring(0, 100));
  
  let sentCount = 0;
  wss.clients.forEach((client) => {
    if (client.readyState === client.OPEN) {
      try {
        client.send(payload);
        sentCount++;
      } catch (err) {
        console.error(`[BROADCAST] Send failed:`, err.message);
      }
    }
  });
  
  console.log(`[BROADCAST] Sent to ${sentCount}/${clientCount} clients`);
  res.json({ 
    ok: true, 
    sent: sentCount, 
    totalConnected: clientCount,
    timestamp: new Date().toISOString()
  });
});

// Simple health check endpoint
app.get('/health', (req, res) => {
  res.json({ 
    status: 'ok', 
    connectedClients: clientCount,
    uptime: process.uptime(),
    timestamp: new Date().toISOString()
  });
});

const PORT = process.env.PORT || 8080;
server.listen(PORT, () => {
  console.log(`
╔════════════════════════════════════════════╗
║  WebSocket Server Running                  ║
║  Port: ${PORT}                                  ║
║  WS: ws://localhost:${PORT}                 ║
║  Broadcast: http://localhost:${PORT}/broadcast  ║
║  Health: http://localhost:${PORT}/health       ║
╚════════════════════════════════════════════╝
  `);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down...');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});
