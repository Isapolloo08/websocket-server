# WebSocket Server - Deployment Guide

## Quick Deploy to Render

This guide walks you through deploying a persistent WebSocket server to Render (free tier available, auto-deploys from Git).

### Prerequisites
- GitHub account (free)
- Render account (free tier: https://render.com)
- This repository pushed to GitHub

### Step-by-Step Deployment

#### 1. Create a GitHub Repository (or use existing)

If you haven't already, push your project to GitHub:

```bash
cd C:\Users\MARK ANGELO\websocket-server
git init
git add .
git commit -m "Add WebSocket server and PHP integration"
git remote add origin https://github.com/YOUR_USERNAME/websocket-server.git
git push -u origin main
```

#### 2. Sign up on Render

- Go to https://render.com
- Sign up with GitHub (this auto-authorizes your repos)
- Create account

#### 3. Create a New Web Service on Render

1. Click **"New +"** → **"Web Service"**
2. Select your GitHub repository (`websocket-server`)
3. Fill in:
   - **Name**: `websocket-server` (or any name)
   - **Environment**: `Node`
   - **Build Command**: `npm install`
   - **Start Command**: `node lib/ws-server.js`
   - **Plan**: Free (Eco tier)
4. Click **"Create Web Service"**

Render will auto-deploy. Wait 2-3 minutes. You'll get a domain like:
```
https://websocket-server-abc123.onrender.com
```

#### 4. Update Your PHP Configuration

In **Hostinger Control Panel** (or your host's environment settings):

Add these environment variables:

```
WEBSOCKET_CLIENT_URL=wss://websocket-server-abc123.onrender.com
WEBSOCKET_BROADCAST_URL=https://websocket-server-abc123.onrender.com/broadcast
```

Replace `websocket-server-abc123` with your actual Render service name.

#### 5. Restart Your PHP Application

If using Hostinger:
- Go to Control Panel → **Staging / Versions** → Restart

Or simply reload your PHP application/dashboard.

#### 6. Test the Connection

**Check WebSocket is live:**
```bash
curl https://websocket-server-abc123.onrender.com/health
```

You should get:
```json
{
  "status": "ok",
  "connectedClients": 0,
  "uptime": 123.45,
  "timestamp": "2025-11-29T..."
}
```

**Test broadcast from your server:**
```bash
curl -X POST "https://websocket-server-abc123.onrender.com/broadcast" \
  -H "Content-Type: application/json" \
  -d '{"temperature":26.5,"device_id":"vm1","location":"Storage"}'
```

Response should be:
```json
{
  "ok": true,
  "sent": 0,
  "totalConnected": 0,
  "timestamp": "2025-11-29T..."
}
```

**Test end-to-end:**
1. Open your dashboard in a browser: `https://your-hostinger-domain.com/VendoMachine_Monitor.php`
2. Check browser console (F12 → Console tab) — should see `WebSocket connected`
3. Run broadcast curl command above
4. Watch the dashboard update in real-time

---

## Troubleshooting

### WebSocket Connection Failed
- Verify Render service is running: Check Render dashboard → your service → "Live logs"
- Verify env vars are set in PHP: Check Hostinger control panel
- Verify domain is correct: Use actual `onrender.com` domain, not localhost

### Broadcast Not Received
- Check `WEBSOCKET_BROADCAST_URL` is correct (should be https://, not wss://)
- Check PHP `broadcastToWebSocket()` is being called: Add logging to PHP

### Service Goes to Sleep (Free Tier)
- Render free tier spins down after 15 min of inactivity
- Use a health check or upgrade to paid tier ($7/month)

---

## Local Development (Optional)

If you want to test locally before deploying:

```bash
npm install
node lib/ws-server.js
```

Server runs on `ws://localhost:8080`

Expose with ngrok for remote testing:
```bash
ngrok http 8080
```

Then update `WEBSOCKET_CLIENT_URL` and `WEBSOCKET_BROADCAST_URL` to use the ngrok domain.

---

## Production Checklist

- [ ] WebSocket server deployed to Render
- [ ] Environment variables set in Hostinger
- [ ] PHP `VendoMachine_Monitor.php` injects `WEBSOCKET_URL` from env
- [ ] Dashboard opens and connects (check browser console)
- [ ] Temperature broadcast updates dashboard in real-time
- [ ] Render service has auto-restart enabled (Render default)

---

## Next Steps

After deployment:
1. Monitor real-time temperature updates from your vending machine
2. Optional: Add authentication token to `/broadcast` endpoint for security
3. Optional: Store temperature history in database for analytics

---

## Support

For issues:
- Check Render logs: https://render.com → your service → "Logs"
- Check browser console: F12 → Console tab
- Verify env vars are set correctly
