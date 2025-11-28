// lib/websocket-server.js
const { WebSocketServer } = require('ws');
const http = require('http');
const url = require('url');

class TemperatureWebSocketServer {
    constructor(port = process.env.PORT || 3000) {
        this.port = port;
        this.clients = new Set();
        this.temperatureData = null;
        this.server = null;
        this.wss = null;
        
        this.init();
    }

    init() {
        // Create HTTP server
        this.server = http.createServer((req, res) => {
            this.handleHttpRequest(req, res);
        });

        // Create WebSocket server
        this.wss = new WebSocketServer({ 
            server: this.server,
            clientTracking: true
        });

        this.setupWebSocketHandlers();
        this.startServer();
    }

    setupWebSocketHandlers() {
        this.wss.on('connection', (ws, request) => {
            this.handleConnection(ws, request);
        });

        this.wss.on('error', (error) => {
            console.error('WebSocket server error:', error);
        });
    }

    handleConnection(ws, request) {
        const clientId = this.generateClientId();
        const clientInfo = {
            id: clientId,
            ws: ws,
            connectedAt: new Date(),
            ip: request.socket.remoteAddress
        };

        this.clients.add(clientInfo);
        console.log(`New client connected: ${clientId}. Total: ${this.clients.size}`);

        // Send current temperature data to new client
        if (this.temperatureData) {
            this.sendToClient(ws, {
                type: 'temperature_update',
                data: this.temperatureData,
                timestamp: Date.now()
            });
        }

        // Send connection confirmation
        this.sendToClient(ws, {
            type: 'connected',
            clientId: clientId,
            timestamp: Date.now()
        });

        // Message handler
        ws.on('message', (data) => {
            this.handleMessage(ws, data, clientInfo);
        });

        // Close handler
        ws.on('close', (code, reason) => {
            this.handleClose(ws, code, reason, clientInfo);
        });

        // Error handler
        ws.on('error', (error) => {
            this.handleError(ws, error, clientInfo);
        });

        // Start ping-pong for connection health
        this.startPingPong(ws, clientInfo);
    }

    handleMessage(ws, data, clientInfo) {
        try {
            const message = JSON.parse(data.toString());
            
            switch (message.type) {
                case 'ping':
                    this.sendToClient(ws, {
                        type: 'pong',
                        timestamp: Date.now()
                    });
                    break;
                    
                case 'subscribe':
                    console.log(`Client ${clientInfo.id} subscribed to temperature updates`);
                    break;
                    
                default:
                    console.log(`Unknown message type from ${clientInfo.id}:`, message.type);
            }
        } catch (error) {
            console.error(`Error parsing message from ${clientInfo.id}:`, error);
        }
    }

    handleClose(ws, code, reason, clientInfo) {
        this.clients.delete(clientInfo);
        console.log(`Client disconnected: ${clientInfo.id}. Code: ${code}, Reason: ${reason}. Total: ${this.clients.size}`);
    }

    handleError(ws, error, clientInfo) {
        console.error(`WebSocket error for client ${clientInfo.id}:`, error);
        this.clients.delete(clientInfo);
    }

    startPingPong(ws, clientInfo) {
        const pingInterval = setInterval(() => {
            if (ws.readyState === ws.OPEN) {
                this.sendToClient(ws, {
                    type: 'ping',
                    timestamp: Date.now()
                });
            } else {
                clearInterval(pingInterval);
            }
        }, 30000); // Ping every 30 seconds

        // Store interval for cleanup
        clientInfo.pingInterval = pingInterval;
    }

    handleHttpRequest(req, res) {
        const parsedUrl = url.parse(req.url, true);
        const pathname = parsedUrl.pathname;

        // Set CORS headers
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

        if (req.method === 'OPTIONS') {
            res.writeHead(200);
            res.end();
            return;
        }

        switch (pathname) {
            case '/health':
                this.handleHealthCheck(req, res);
                break;
                
            case '/broadcast':
                if (req.method === 'POST') {
                    this.handleBroadcast(req, res);
                } else {
                    this.sendError(res, 405, 'Method not allowed');
                }
                break;
                
            case '/clients':
                this.handleClientsInfo(req, res);
                break;
                
            case '/temperature':
                this.handleTemperatureData(req, res);
                break;
                
            default:
                this.sendError(res, 404, 'Not found');
        }
    }

    async handleBroadcast(req, res) {
        try {
            const body = await this.getRequestBody(req);
            const data = JSON.parse(body);

            // Validate authentication (optional)
            const authToken = req.headers['authorization'];
            if (!this.authenticateRequest(authToken)) {
                this.sendError(res, 401, 'Unauthorized');
                return;
            }

            // Validate data
            if (!this.validateBroadcastData(data)) {
                this.sendError(res, 400, 'Invalid data format');
                return;
            }

            // Store temperature data
            this.temperatureData = {
                ...data,
                id: this.generateMessageId(),
                serverTime: new Date().toISOString(),
                receivedAt: Date.now()
            };

            // Broadcast to all clients
            const broadcastResult = this.broadcastToAll({
                type: 'temperature_update',
                data: this.temperatureData,
                timestamp: Date.now()
            });

            // Log the broadcast
            console.log(`Broadcasted temperature: ${data.temperature}°C to ${broadcastCount} clients`);

            // Send response
            res.setHeader('Content-Type', 'application/json');
            res.writeHead(200);
            res.end(JSON.stringify({
                success: true,
                message: 'Temperature data broadcasted successfully',
                clients: broadcastResult.count,
                data: {
                    temperature: data.temperature,
                    alert: data.alert,
                    message: data.message
                }
            }));

        } catch (error) {
            console.error('Broadcast error:', error);
            this.sendError(res, 500, 'Internal server error');
        }
    }

    handleHealthCheck(req, res) {
        res.setHeader('Content-Type', 'application/json');
        res.writeHead(200);
        res.end(JSON.stringify({
            status: 'healthy',
            uptime: process.uptime(),
            timestamp: new Date().toISOString(),
            clients: this.clients.size,
            memory: process.memoryUsage(),
            lastTemperature: this.temperatureData
        }));
    }

    handleClientsInfo(req, res) {
        const clientsInfo = Array.from(this.clients).map(client => ({
            id: client.id,
            connectedAt: client.connectedAt,
            ip: client.ip
        }));

        res.setHeader('Content-Type', 'application/json');
        res.writeHead(200);
        res.end(JSON.stringify({
            total: this.clients.size,
            clients: clientsInfo
        }));
    }

    handleTemperatureData(req, res) {
        res.setHeader('Content-Type', 'application/json');
        res.writeHead(200);
        res.end(JSON.stringify({
            data: this.temperatureData,
            lastUpdated: this.temperatureData ? new Date(this.temperatureData.receivedAt) : null
        }));
    }

    broadcastToAll(message) {
        const messageString = JSON.stringify(message);
        let broadcastCount = 0;

        this.clients.forEach(client => {
            if (client.ws.readyState === client.ws.OPEN) {
                try {
                    client.ws.send(messageString);
                    broadcastCount++;
                } catch (error) {
                    console.error(`Error broadcasting to client ${client.id}:`, error);
                }
            }
        });

        return { count: broadcastCount };
    }

    sendToClient(ws, message) {
        if (ws.readyState === ws.OPEN) {
            try {
                ws.send(JSON.stringify(message));
            } catch (error) {
                console.error('Error sending message to client:', error);
            }
        }
    }

    authenticateRequest(authToken) {
        // Add your authentication logic here
        // For now, allow all requests
        return true;
        
        // Example with token validation:
        // const expectedToken = process.env.API_TOKEN;
        // return authToken === `Bearer ${expectedToken}`;
    }

    validateBroadcastData(data) {
        return data && 
               typeof data.temperature === 'number' && 
               typeof data.device_id === 'string';
    }

    getRequestBody(req) {
        return new Promise((resolve, reject) => {
            let body = '';
            req.on('data', chunk => {
                body += chunk.toString();
            });
            req.on('end', () => {
                resolve(body);
            });
            req.on('error', reject);
        });
    }

    sendError(res, code, message) {
        res.setHeader('Content-Type', 'application/json');
        res.writeHead(code);
        res.end(JSON.stringify({ error: message, code: code }));
    }

    generateClientId() {
        return `client_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    generateMessageId() {
        return `msg_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    startServer() {
        this.server.listen(this.port, () => {
            console.log(`🚀 Temperature WebSocket server running on port ${this.port}`);
            console.log(`📊 Health check: http://localhost:${this.port}/health`);
            console.log(`🔗 WebSocket: ws://localhost:${this.port}/websocket`);
        });
    }

    stop() {
        console.log('Shutting down WebSocket server...');
        
        // Close all client connections
        this.clients.forEach(client => {
            if (client.ws.readyState === client.ws.OPEN) {
                client.ws.close(1001, 'Server shutdown');
            }
            if (client.pingInterval) {
                clearInterval(client.pingInterval);
            }
        });

        // Close WebSocket server
        if (this.wss) {
            this.wss.close();
        }

        // Close HTTP server
        if (this.server) {
            this.server.close();
        }

        console.log('WebSocket server stopped');
    }
}

// Export for use in other files
module.exports = TemperatureWebSocketServer;

// Start server if run directly
if (require.main === module) {
    const server = new TemperatureWebSocketServer();
    
    // Graceful shutdown
    process.on('SIGINT', () => {
        console.log('\nReceived SIGINT');
        server.stop();
        process.exit(0);
    });
    
    process.on('SIGTERM', () => {
        console.log('Received SIGTERM');
        server.stop();
        process.exit(0);
    });
}