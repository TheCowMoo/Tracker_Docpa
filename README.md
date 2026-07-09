# DOCPA Time Tracker

A production-ready employee time tracking system with screenshot monitoring, idle detection, and a web dashboard. **Runs locally on Windows** and syncs to your **VPS** via a PHP + MySQL API.

## Architecture

```
┌─────────────────────┐       ┌─────────────────────────────┐
│   Windows Client     │       │   VPS (Ubuntu + HestiaCP)   │
│                     │       │                             │
│  main.py            │  ──►  │  /api/upload.php            │
│  tracker.py         │  HTTP │  /api/auth.php              │
│  interface.py       │       │  /api/sessions.php          │
│                     │       │  /api/stats.php             │
│  config.json        │       │  /api/heartbeat.php         │
│                     │       │                             │
│  .exe (PyInstaller) │       │  /dashboard/ (SPA)          │
│                     │       │  /data/screenshots/         │
└─────────────────────┘       └─────────────────────────────┘
```

## Quick Start

### 1. Deploy the Server (VPS)

Run the install script on your VPS:

```bash
# Upload the server/ directory to your VPS, then:
cd server/
bash install.sh
```

The script will:
- Create the MySQL database + user
- Import the schema
- Copy PHP files to the web root
- Generate an admin API key

**You'll receive an API key** at the end — save it for the client.

### 2. Configure & Run the Client (Windows)

Install dependencies:

```bash
cd client/
pip install -r requirements.txt
```

Edit `config.json`:

```json
{
    "vps_url": "https://tracker.docharteredaccountant.com",
    "api_key": "YOUR_API_KEY_HERE",
    "interval_seconds": 300,
    "image_quality": 50,
    "idle_timeout_minutes": 5,
    ...
}
```

Run:

```bash
python main.py
```

The app will appear in the **system tray** (or as a window if pystray isn't installed).

### 3. Build a Standalone .exe

```bash
pip install pyinstaller
pyinstaller build.spec
```

The output will be at `dist/DOCPA_Tracker.exe`. Distribute this to your team.

---

## Features

### Client (Python)
- **System tray icon** — runs silently in the background
- **Idle detection** — stops tracking when you're AFK (mouse/keyboard monitoring via Windows API)
- **Offline queue** — stores screenshots locally when VPS is unreachable and retries automatically
- **Configurable interval** — how often to take screenshots (default: 5 minutes)
- **Auto-start on login** — registers itself in Windows Registry
- **Single instance** — prevents duplicate processes
- **Logging** — rotating log file at `%LOCALAPPDATA%/DOCPA_Tracker/`

### Server (PHP + MySQL)
- **Authentication** — API-key based user management
- **Screenshot upload** — accepts images, creates thumbnails, manages storage
- **Session tracking** — auto-creates/continues sessions per user
- **Activity monitoring** — heartbeat endpoint for real-time status
- **Statistics** — productivity scores, streaks, daily/weekly summaries
- **Auto-cleanup** — deletes old screenshots after 90 days

### Dashboard (Web SPA)
- **Overview** — live status, today's activity, recent sessions
- **Screenshot timeline** — browse screenshots per session with lightbox view
- **Reports** — daily/weekly/hourly charts with active vs. idle breakdown
- **Chart.js** — interactive bar and line charts
- **Dark theme** — easy on the eyes for all-day use

---

## API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/auth.php?action=register` | POST | Register new user (returns API key) |
| `/api/auth.php?action=login` | POST | Validate API key |
| `/api/auth.php?action=verify` | GET | Verify current API key |
| `/api/upload.php` | POST | Upload screenshot (multipart) |
| `/api/sessions.php` | GET | List sessions with filters |
| `/api/sessions.php?action=current` | GET | Get active session |
| `/api/sessions.php?action=end` | POST | End current session |
| `/api/screenshots.php?session_id=N` | GET | List screenshots for session |
| `/api/screenshots.php?action=image` | GET | Serve screenshot file |
| `/api/stats.php` | GET | Activity statistics |
| `/api/heartbeat.php` | POST | Send activity heartbeat |

All endpoints require the API key via `Authorization: Bearer <key>`, `X-API-Key` header, or `?api_key=` query parameter.

---

## File Structure

```
DOCPA/
├── client/                          # Windows desktop app
│   ├── main.py                      # Entry point
│   ├── tracker.py                   # Screenshot engine + idle detection
│   ├── interface.py                 # Tray icon + settings dialog
│   ├── config.json                  # User configuration
│   ├── requirements.txt             # Python dependencies
│   └── build.spec                   # PyInstaller spec
│
├── server/                          # VPS server-side
│   ├── api/
│   │   ├── auth.php                 # Authentication
│   │   ├── upload.php               # Screenshot upload
│   │   ├── sessions.php             # Session management
│   │   ├── screenshots.php          # Screenshot retrieval
│   │   ├── stats.php                # Statistics
│   │   └── heartbeat.php            # Activity heartbeat
│   ├── dashboard/
│   │   ├── index.html               # SPA dashboard
│   │   ├── css/style.css            # Dark theme styles
│   │   └── js/app.js                # Dashboard logic
│   ├── includes/
│   │   ├── config.php               # Configuration defaults
│   │   ├── db.php                   # Database helpers
│   │   └── auth_middleware.php       # Authentication check
│   ├── schema.sql                   # Database schema
│   ├── install.sh                   # VPS deployment script
│   └── data/                        # Screenshot storage (auto-created)
│
└── README.md
```

## Security Notes

- **HTTPS is required** — use Let's Encrypt via HestiaCP
- **API keys** are random 64-character hex strings, stored client-side in `config.json`
- **Screenshots** are stored in a directory protected by `.htaccess` — they are only accessible via the authenticated image serving endpoint
- **Rate limiting** is not yet implemented — recommended to add at the web server level (e.g., Nginx `limit_req`) if you have many users
- **Database credentials** go in `config.local.php` (gitignored), never in `config.php`

## License

MIT