// api/websocket.js - Updated for Vercel
import { WebSocketServer } from 'ws';

// In-memory storage (for serverless - consider Redis for production)
const clients = new Map();
let temperatureData = null;

export default async function handler(request, response) {
  const url = new URL(request.url, `http://${request.headers.host}`);
  const pathname = url.pathname;

  // Set CORS headers
  response.setHeader('Access-Control-Allow-Origin', '*');
  response.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, DELETE');
  response.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (request.method === 'OPTIONS') {
    response.status(200).end();
    return;
  }

  try {
    switch (pathname) {
      case '/websocket':
        if (request.method === 'GET') {
          await handleWebSocketUpgrade(request, response);
        } else {
          response.status(405).json({ error: 'Method not allowed' });
        }
        break;

      case '/broadcast':
        if (request.method === 'POST') {
          await handleBroadcast(request, response);
        } else {
          response.status(405).json({ error: 'Method not allowed' });
        }
        break;

      case '/health':
        await handleHealthCheck(request, response);
        break;

      case '/clients':
        await handleClientsInfo(request, response);
        break;

      case '/temperature':
        await handleTemperatureData(request, response);
        break;

      default:
        response.status(404).json({ error: 'Endpoint not found' });
    }
  } catch (error) {
    console.error('Handler error:', error);
    response.status(500).json({ error: 'Internal server error' });
  }
}

async function handleWebSocketUpgrade(request, response) {
  const wss = new WebSocketServer({ noServer: true });

  wss.handleUpgrade(request, request.socket, Buffer.alloc(0), (ws) => {
    handleWebSocketConnection(ws, request);
  });
}

function handleWebSocketConnection(ws, request) {
  const clientId = generateClientId();
  const clientInfo = {
    id: clientId,
    ws: ws,
    ip: request.headers['x-forwarded-for'] || request.socket.remoteAddress,
    connectedAt: new Date().toISOString(),
    userAgent: request.headers['user-agent']
  };

  clients.set(clientId, clientInfo);

  console.log(`New WebSocket connection: ${clientId}. Total clients: ${clients.size}`);

  // Send welcome message
  sendToClient(ws, {
    type: 'connected',
    clientId: clientId,
    message: 'Connected to Temperature WebSocket Server',
    timestamp: Date.now()
  });

  // Send current temperature if available
  if (temperatureData) {
    sendToClient(ws, {
      type: 'temperature_update',
      data: temperatureData,
      timestamp: Date.now()
    });
  }

  // Message handler
  ws.on('message', (data) => {
    handleClientMessage(clientId, data);
  });

  // Close handler
  ws.on('close', (code, reason) => {
    handleClientDisconnect(clientId, code, reason);
  });

  // Error handler
  ws.on('error', (error) => {
    handleClientError(clientId, error);
  });

  // Start ping-pong
  startPingPong(clientId, ws);
}

function handleClientMessage(clientId, data) {
  try {
    const message = JSON.parse(data);
    
    switch (message.type) {
      case 'ping':
        const client = clients.get(clientId);
        if (client && client.ws.readyState === client.ws.OPEN) {
          sendToClient(client.ws, {
            type: 'pong',
            timestamp: Date.now()
          });
        }
        break;
        
      case 'subscribe':
        console.log(`Client ${clientId} subscribed to updates`);
        break;
        
      default:
        console.log(`Unknown message type from ${clientId}:`, message.type);
    }
  } catch (error) {
    console.error(`Error handling message from ${clientId}:`, error);
  }
}

function handleClientDisconnect(clientId, code, reason) {
  clients.delete(clientId);
  console.log(`Client disconnected: ${clientId}. Code: ${code}, Reason: ${reason}. Remaining: ${clients.size}`);
}

function handleClientError(clientId, error) {
  console.error(`WebSocket error for client ${clientId}:`, error);
  clients.delete(clientId);
}

function startPingPong(clientId, ws) {
  const interval = setInterval(() => {
    if (ws.readyState === ws.OPEN) {
      sendToClient(ws, {
        type: 'ping',
        timestamp: Date.now()
      });
    } else {
      clearInterval(interval);
      clients.delete(clientId);
    }
  }, 30000);

  // Store interval reference
  const client = clients.get(clientId);
  if (client) {
    client.pingInterval = interval;
  }
}

async function handleBroadcast(request, response) {
  try {
    const body = await getRequestBody(request);
    const data = JSON.parse(body);

    // Validate required fields
    if (!data.temperature || !data.device_id) {
      response.status(400).json({ 
        error: 'Missing required fields: temperature and device_id are required' 
      });
      return;
    }

    // Authenticate (optional - add your logic)
    const authToken = request.headers['authorization'];
    if (!authenticateBroadcast(authToken)) {
      response.status(401).json({ error: 'Unauthorized' });
      return;
    }

    // Update temperature data
    temperatureData = {
      ...data,
      id: generateMessageId(),
      serverTime: new Date().toISOString(),
      receivedAt: Date.now()
    };

    // Broadcast to all clients
    const broadcastCount = broadcastToAll({
      type: 'temperature_update',
      data: temperatureData,
      timestamp: Date.now()
    });

    console.log(`Broadcasted temperature ${data.temperature}°C to ${broadcastCount} clients`);

    response.status(200).json({
      success: true,
      message: 'Temperature broadcasted successfully',
      clients: broadcastCount,
      data: {
        temperature: data.temperature,
        alert: data.alert,
        device_id: data.device_id
      }
    });

  } catch (error) {
    console.error('Broadcast error:', error);
    response.status(500).json({ error: 'Internal server error' });
  }
}

async function handleHealthCheck(request, response) {
  response.status(200).json({
    status: 'healthy',
    uptime: process.uptime(),
    timestamp: new Date().toISOString(),
    clients: clients.size,
    lastTemperature: temperatureData,
    environment: process.env.NODE_ENV
  });
}

async function handleClientsInfo(request, response) {
  const clientsArray = Array.from(clients.values()).map(client => ({
    id: client.id,
    connectedAt: client.connectedAt,
    ip: client.ip,
    userAgent: client.userAgent
  }));

  response.status(200).json({
    total: clients.size,
    clients: clientsArray
  });
}

async function handleTemperatureData(request, response) {
  response.status(200).json({
    data: temperatureData,
    lastUpdated: temperatureData ? new Date(temperatureData.receivedAt) : null
  });
}

function broadcastToAll(message) {
  const messageString = JSON.stringify(message);
  let count = 0;

  clients.forEach(client => {
    if (client.ws.readyState === client.ws.OPEN) {
      try {
        client.ws.send(messageString);
        count++;
      } catch (error) {
        console.error(`Error broadcasting to client ${client.id}:`, error);
      }
    }
  });

  return count;
}

function sendToClient(ws, message) {
  if (ws.readyState === ws.OPEN) {
    try {
      ws.send(JSON.stringify(message));
    } catch (error) {
      console.error('Error sending to client:', error);
    }
  }
}

function authenticateBroadcast(authToken) {
  // Add your authentication logic
  // For now, allow all requests
  return true;
  
  // Example:
  // const expectedToken = process.env.API_TOKEN;
  // return authToken === `Bearer ${expectedToken}`;
}

function getRequestBody(request) {
  return new Promise((resolve, reject) => {
    let body = '';
    request.on('data', chunk => {
      body += chunk.toString();
    });
    request.on('end', () => {
      resolve(body);
    });
    request.on('error', reject);
  });
}

function generateClientId() {
  return `client_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

function generateMessageId() {
  return `msg_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}