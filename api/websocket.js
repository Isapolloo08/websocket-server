
import { WebSocketServer } from 'ws';

// In-memory storage (for serverless - consider Redis for production)
const clients = new Map();
const devices = new Map();
let temperatureData = null;

// Device management
class DeviceManager {
  constructor() {
    this.devices = new Map();
  }

  registerDevice(deviceInfo, clientId) {
    this.devices.set(deviceInfo.device_id, {
      ...deviceInfo,
      clientId,
      lastSeen: Date.now(),
      status: 'online'
    });
    
    console.log(`Device registered: ${deviceInfo.device_id} from ${deviceInfo.location}`);
  }

  updateDeviceStatus(deviceId, status) {
    const device = this.devices.get(deviceId);
    if (device) {
      device.status = status;
      device.lastSeen = Date.now();
    }
  }

  getOnlineDevices() {
    return Array.from(this.devices.values()).filter(device => 
      device.status === 'online' && Date.now() - device.lastSeen < 300000 // 5 minutes
    );
  }
}
const deviceManager = new DeviceManager();
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

      case '/devices':
        await handleDevicesInfo(request, response);
        break;

      case '/clients':
        await handleClientsInfo(request, response);
        break;

      case '/temperature':
        await handleTemperatureData(request, response);
        break;

      case '/send-command':
        await handleSendCommand(request, response);
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
    userAgent: request.headers['user-agent'],
    type: 'browser', // default type
    deviceId: null
  };

  clients.set(clientId, clientInfo);

  console.log(`New WebSocket connection: ${clientId}. Total clients: ${clients.size}`);

  // Send welcome message
  sendToClient(ws, {
    type: 'connected',
    clientId: clientId,
    message: 'Connected to Temperature WebSocket Server',
    serverTime: new Date().toISOString(),
    version: '1.0'
  });

  // Send current temperature if available
  if (temperatureData) {
    sendToClient(ws, {
      type: 'temperature_update',
      data: temperatureData,
      timestamp: Date.now()
    });
  }

  // Send online devices list
  const onlineDevices = deviceManager.getOnlineDevices();
  if (onlineDevices.length > 0) {
    sendToClient(ws, {
      type: 'devices_online',
      devices: onlineDevices,
      count: onlineDevices.length
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
    const client = clients.get(clientId);
    
    switch (message.type) {
      case 'ping':
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
        
      case 'device_register':
        // Arduino device registration
        client.type = 'device';
        client.deviceId = message.device_id;
        deviceManager.registerDevice(message, clientId);
        
        // Notify all browser clients about new device
        broadcastToBrowsers({
          type: 'device_connected',
          device: message,
          timestamp: Date.now()
        });
        break;
        
      case 'temperature_update':
        // Handle temperature data from Arduino
        handleTemperatureUpdate(message, clientId);
        break;
        
      case 'request_devices':
        // Client requesting devices list
        const onlineDevices = deviceManager.getOnlineDevices();
        sendToClient(client.ws, {
          type: 'devices_list',
          devices: onlineDevices,
          count: onlineDevices.length
        });
        break;
        
      default:
        console.log(`Unknown message type from ${clientId}:`, message.type);
    }
  } catch (error) {
    console.error(`Error handling message from ${clientId}:`, error);
  }
}

function handleTemperatureUpdate(message, clientId) {
  // Update temperature data
  temperatureData = {
    ...message,
    id: generateMessageId(),
    serverTime: new Date().toISOString(),
    receivedAt: Date.now(),
    clientId: clientId
  };

  // Update device status
  if (message.device_id) {
    deviceManager.updateDeviceStatus(message.device_id, 'online');
  }

  // Broadcast to all browser clients
  const broadcastCount = broadcastToBrowsers({
    type: 'temperature_update',
    data: temperatureData,
    timestamp: Date.now()
  });

  console.log(`📊 Temperature ${message.temperature}°C from ${message.device_id} broadcasted to ${broadcastCount} clients`);
}
function handleClientDisconnect(clientId, code, reason) {
  const client = clients.get(clientId);
  
  if (client && client.type === 'device' && client.deviceId) {
    deviceManager.updateDeviceStatus(client.deviceId, 'offline');
    
    // Notify browser clients about device disconnect
    broadcastToBrowsers({
      type: 'device_disconnected',
      device_id: client.deviceId,
      timestamp: Date.now()
    });
  }
  
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

function broadcastToBrowsers(message) {
  const messageString = JSON.stringify(message);
  let count = 0;

  clients.forEach(client => {
    if (client.type === 'browser' && client.ws.readyState === client.ws.OPEN) {
      try {
        client.ws.send(messageString);
        count++;
      } catch (error) {
        console.error(`Error broadcasting to browser client ${client.id}:`, error);
      }
    }
  });

  return count;
}

// New endpoint: Send command to device
async function handleSendCommand(request, response) {
  try {
    const body = await getRequestBody(request);
    const data = JSON.parse(body);

    const { device_id, command, parameters } = data;

    if (!device_id || !command) {
      response.status(400).json({ 
        error: 'Missing required fields: device_id and command are required' 
      });
      return;
    }

    // Find device client
    let targetClient = null;
    clients.forEach(client => {
      if (client.deviceId === device_id && client.ws.readyState === client.ws.OPEN) {
        targetClient = client;
      }
    });

    if (!targetClient) {
      response.status(404).json({ 
        error: 'Device not found or not connected',
        device_id: device_id
      });
      return;
    }

    // Send command to device
    const commandMessage = {
      type: 'command',
      command: command,
      parameters: parameters,
      timestamp: Date.now()
    };

    sendToClient(targetClient.ws, JSON.stringify(commandMessage));

    response.status(200).json({
      success: true,
      message: 'Command sent to device',
      device_id: device_id,
      command: command
    });

  } catch (error) {
    console.error('Send command error:', error);
    response.status(500).json({ error: 'Internal server error' });
  }
}

// New endpoint: Get devices information
async function handleDevicesInfo(request, response) {
  const onlineDevices = deviceManager.getOnlineDevices();
  
  response.status(200).json({
    total: onlineDevices.length,
    devices: onlineDevices,
    lastUpdated: new Date().toISOString()
  });
}

// Enhanced health check
async function handleHealthCheck(request, response) {
  const onlineDevices = deviceManager.getOnlineDevices();
  const browserClients = Array.from(clients.values()).filter(client => client.type === 'browser');
  const deviceClients = Array.from(clients.values()).filter(client => client.type === 'device');

  response.status(200).json({
    status: 'healthy',
    uptime: process.uptime(),
    timestamp: new Date().toISOString(),
    clients: {
      total: clients.size,
      browsers: browserClients.length,
      devices: deviceClients.length
    },
    devices: {
      online: onlineDevices.length,
      list: onlineDevices
    },
    lastTemperature: temperatureData,
    environment: process.env.NODE_ENV
  });
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
      receivedAt: Date.now(),
      source: 'http_broadcast'
    };

    // Broadcast to all browser clients
    const broadcastCount = broadcastToBrowsers({
      type: 'temperature_update',
      data: temperatureData,
      timestamp: Date.now()
    });

    console.log(`📊 HTTP Broadcast: Temperature ${data.temperature}°C to ${broadcastCount} clients`);

    response.status(200).json({
      success: true,
      message: 'Temperature broadcasted successfully',
      clients: broadcastCount,
      data: {
        temperature: data.temperature,
        alert: data.alert,
        device_id: data.device_id,
        timestamp: new Date().toISOString()
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