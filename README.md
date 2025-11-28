# Vendo Machine Monitor - WebSocket Real-Time Integration

A real-time temperature monitoring system for the Pill Vendo Machine using WebSocket connections for instant updates across all connected clients.

## Architecture

```
┌─────────────────┐
│  Vendo Machine  │ (or any IoT sensor)
│  (ESP32 / SIM)  │
└────────┬────────┘
         │
         │ HTTP POST (temperature data)
         ↓
┌──────────────────────┐
│   Hostinger PHP      │ (VendoMachine_Monitor.php)
│   Server             │
│                      │
│ 1. Log to database   │
│ 2. Broadcast via WS  │
└────────┬─────────────┘
         │
         │ HTTP POST /broadcast
         ↓
┌──────────────────────────────────────┐
│  Render WebSocket Server             │
│  (lib/ws-server.js - Persistent)     │
│                                      │
│  Maintains WS connections from all   │
│  connected browsers/clients          │
└────────┬─────────────────────────────┘
         │
         │ WebSocket messages (wss://)
         ↓
┌──────────────────────┐
│  Browser Dashboard   │
│  (Client JS)         │
│  Updates in real-time│
└──────────────────────┘
```

## Quick Start

### 1. Environment Variables Setup

Set these in your host environment (Hostinger / Render):

**For Hostinger PHP Server:**
```
WEBSOCKET_BROADCAST_URL=https://websocket-server-abc123.onrender.com/broadcast
WEBSOCKET_CLIENT_URL=wss://websocket-server-abc123.onrender.com
WEBSOCKET_API_TOKEN=(optional - for future security)
```

**For Render WebSocket Server:**
```
(No env vars needed - server runs out of the box)
```

### 2. Deploy WebSocket Server to Render

See [DEPLOY_RENDER.md](./DEPLOY_RENDER.md) for step-by-step Render deployment.

**Quick Summary:**
- Push this repo to GitHub
- Connect GitHub to Render
- Render auto-deploys on push
- Get a public `wss://` domain

### 3. PHP Configuration

The PHP file (`VendoMachine_Monitor.php`) automatically:
1. Injects `WEBSOCKET_URL` from `WEBSOCKET_CLIENT_URL` env var
2. Uses `WEBSOCKET_BROADCAST_URL` to post temperature updates
3. Sends optional Authorization header if `WEBSOCKET_API_TOKEN` is set

No code changes needed — just set env vars.

## File Structure

```
websocket-server/
├── lib/
│   └── ws-server.js              # Persistent WebSocket server (deploy to Render)
├── api/
│   └── websocket.js              # (legacy - kept for reference)
├── VendoMachine_Monitor.php       # Dashboard + real-time integration
├── package.json                   # Node dependencies
├── DEPLOY_RENDER.md              # Render deployment guide
└── README.md                      # This file
```

## Features

### Real-Time Updates
- Temperature data updates all connected clients instantly
- No page refresh required
- Latency: <100ms (depends on network)

### Database Logging
- Temperature readings logged to `temperature_log` table
- Alerts generated for out-of-range temperatures
- Alert history stored in `temperature_alerts` table

### WebSocket Broadcasting
- Multiple concurrent clients supported
- Automatic reconnection on disconnect
- Graceful error handling

### API Endpoints

#### WebSocket Server (`lib/ws-server.js`)

**Broadcast Endpoint (HTTP POST):**
```
POST https://your-websocket-server.com/broadcast
Content-Type: application/json

{
  "temperature": 25.6,
  "device_id": "vm1",
  "location": "Storage",
  "alert": false
}
```

Response:
```json
{
  "ok": true,
  "sent": 5,
  "totalConnected": 5,
  "timestamp": "2025-11-29T12:34:56Z"
}
```

**Health Check Endpoint (HTTP GET):**
```
GET https://your-websocket-server.com/health
```

Response:
```json
{
  "status": "ok",
  "connectedClients": 5,
  "uptime": 1234.56,
  "timestamp": "2025-11-29T12:34:56Z"
}
```

#### PHP Dashboard (`VendoMachine_Monitor.php`)

**Log Temperature (HTTP POST):**
```
POST https://your-hostinger-domain.com/VendoMachine_Monitor.php?action=log_temperature
Content-Type: application/json

{
  "temperature": 25.6,
  "device_id": "vm1",
  "location": "Storage"
}
```

Response:
```json
{
  "status": "success",
  "message": "Temperature logged and broadcasted",
  "database_log": { ... },
  "websocket_broadcast": { "ok": true, "sent": 5 }
}
```

## Testing

### Local Development

```bash
# Start local WebSocket server
npm install
node lib/ws-server.js

# In another terminal, expose with ngrok
ngrok http 8080

# Update env vars to use ngrok URL
WEBSOCKET_CLIENT_URL=wss://abcd1234.ngrok.io
WEBSOCKET_BROADCAST_URL=https://abcd1234.ngrok.io/broadcast

# Test broadcast
curl -X POST https://abcd1234.ngrok.io/broadcast \
  -H "Content-Type: application/json" \
  -d '{"temperature":26.5,"device_id":"vm1"}'
```

### Production (Render)

```bash
# Check server health
curl https://websocket-server-abc123.onrender.com/health

# Test broadcast
curl -X POST https://websocket-server-abc123.onrender.com/broadcast \
  -H "Content-Type: application/json" \
  -d '{"temperature":26.5,"device_id":"vm1"}'

# Monitor logs
# View in Render dashboard → your service → Logs tab
```

## Troubleshooting

### WebSocket Connection Fails

**Symptom:** Browser console shows `WebSocket connection to '...' failed`

**Solution:**
1. Verify `WEBSOCKET_CLIENT_URL` env var is set correctly
2. Verify Render service is running (check Render dashboard)
3. Verify URL is accessible: `curl https://your-server.com/health`
4. Check browser console for actual error message

### Broadcast Not Received by Clients

**Symptom:** POST to `/broadcast` succeeds but clients don't update

**Solution:**
1. Check `"sent"` count in broadcast response — should be > 0 if clients connected
2. Check browser console for JS errors
3. Verify client is listening for `temperature_update` type messages
4. Check Render server logs for errors

### Render Service Hibernation (Free Tier)

**Symptom:** Service becomes unresponsive after 15 min of inactivity

**Solution:**
1. Upgrade to paid tier ($7/month) — no hibernation
2. Or: Use monitoring service to ping `/health` every 14 minutes
3. Or: Use Railway/Fly.io alternative (different hibernation policies)

## Security Considerations

### Authentication (Optional - Future Enhancement)

The `WEBSOCKET_API_TOKEN` env var can be used to secure the `/broadcast` endpoint:

```php
// In VendoMachine_Monitor.php broadcastToWebSocket()
if ($apiToken) {
    $headers[] = 'Authorization: Bearer ' . $apiToken;
}

// In lib/ws-server.js (check header)
app.post('/broadcast', (req, res) => {
    const authHeader = req.get('Authorization');
    const token = process.env.API_TOKEN;
    if (token && authHeader !== `Bearer ${token}`) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    // ... broadcast logic
});
```

### HTTPS/WSS (Required for Production)

- Always use `wss://` (WebSocket Secure) in production — not `ws://`
- Always use `https://` for HTTP endpoints
- Render/Railway/Fly.io provide free HTTPS/TLS automatically

### CORS

If hosting dashboard on different domain, ensure CORS is configured in the WebSocket server (currently unrestricted).

## Performance

- **Latency**: 50-200ms (depends on geography, network)
- **Concurrent Clients**: ~500-1000 on free tier (depends on message frequency)
- **Memory**: ~50MB base + ~1MB per 100 connected clients
- **CPU**: Minimal (mostly I/O bound)

## Monitoring

### Real-Time Monitoring (Render)

Visit: https://render.com → your service → **Logs** tab

Useful logs:
```
[WS] Client connected: ...
[BROADCAST] Sent to 5/5 clients
[WS] Client disconnected: ...
```

### Dashboard Metrics (Future Enhancement)

Can add:
- Connected client count
- Messages per second
- Temperature reading frequency
- Alert frequency

## Deployment Checklist

- [ ] GitHub repo created and pushed
- [ ] Render account created
- [ ] WebSocket service deployed to Render
- [ ] Render service domain noted (e.g., `websocket-server-abc123.onrender.com`)
- [ ] Environment variables set in Hostinger
- [ ] PHP file updated with env var injection (already done)
- [ ] Test health endpoint: `curl https://..../health`
- [ ] Test broadcast: `curl -X POST https://..../broadcast ...`
- [ ] Dashboard loads and connects (check browser console)
- [ ] Temperature broadcast updates dashboard in real-time

## Next Steps

1. **Deploy**: Follow [DEPLOY_RENDER.md](./DEPLOY_RENDER.md)
2. **Monitor**: Watch Render logs during first deployment
3. **Test**: Run broadcast curl command and verify dashboard updates
4. **Secure**: (Optional) Add API token authentication
5. **Scale**: (Optional) Monitor performance and upgrade tier if needed

## Support

- Render docs: https://render.com/docs
- WebSocket docs: https://developer.mozilla.org/en-US/docs/Web/API/WebSocket
- PHP docs: https://www.php.net

---

**Last Updated:** 2025-11-29  
**Version:** 1.0.0
