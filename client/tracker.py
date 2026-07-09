"""
DOCPA Tracker — Core Tracking Engine

Handles:
  - Screenshot capture (via pyautogui)
  - Idle detection (mouse/keyboard activity monitoring)
  - Offline queue with retry logic
  - Upload to VPS with heartbeat
"""

import os
import sys
import json
import time
import queue
import logging
import threading
import datetime

import pyautogui
import requests
from PIL import Image

# Try to get last mouse position for idle detection
try:
    from ctypes import Structure, windll, c_uint, sizeof, byref
    HAS_WINDOWS_IDLE = True
except ImportError:
    HAS_WINDOWS_IDLE = False

logger = logging.getLogger('docpa.tracker')


class IdleDetector:
    """Detect if the user is idle (no mouse/keyboard input)."""

    def __init__(self, timeout_minutes=5):
        self.timeout_seconds = timeout_minutes * 60
        self._last_mouse_pos = pyautogui.position()
        self._last_active_time = time.time()

    def check_idle(self) -> bool:
        """
        Check if user is currently idle.
        Returns True if idle, False if active.
        """
        current_pos = pyautogui.position()
        current_time = time.time()

        # Mouse moved
        if current_pos != self._last_mouse_pos:
            self._last_mouse_pos = current_pos
            self._last_active_time = current_time
            return False

        # Keyboard activity detected via Windows API (more accurate)
        if HAS_WINDOWS_IDLE:
            try:
                # Get the time since last input in milliseconds
                class LASTINPUTINFO(Structure):
                    _fields_ = [("cbSize", c_uint), ("dwTime", c_uint)]

                lii = LASTINPUTINFO()
                lii.cbSize = sizeof(LASTINPUTINFO)
                if windll.user32.GetLastInputInfo(byref(lii)):
                    # Get current tick count
                    current_tick = windll.kernel32.GetTickCount()
                    idle_ms = current_tick - lii.dwTime
                    return (idle_ms / 1000) > self.timeout_seconds
            except Exception:
                pass  # Fall through to position-based check

        # Fallback: mouse position hasn't changed
        elapsed = current_time - self._last_active_time
        return elapsed > self.timeout_seconds

    def mark_active(self):
        """Force-mark user as active (called after a successful screenshot)."""
        self._last_active_time = time.time()


class OfflineQueue:
    """
    Queue screenshots locally when VPS is unreachable,
    and retry sending them on a background thread.
    """

    def __init__(self, max_size=50, retry_interval=60):
        self.max_size = max_size
        self.retry_interval = retry_interval
        self._queue = queue.Queue()
        self._lock = threading.Lock()
        self._running = False
        self._thread = None

    def start(self):
        self._running = True
        self._thread = threading.Thread(target=self._retry_loop, daemon=True)
        self._thread.start()
        logger.info("Offline queue retry thread started.")

    def stop(self):
        self._running = False

    def enqueue(self, filepath: str, metadata: dict):
        """Add a screenshot file to the offline queue."""
        if self._queue.qsize() >= self.max_size:
            logger.warning("Offline queue full. Removing oldest item.")
            try:
                old = self._queue.get_nowait()
                if os.path.exists(old['filepath']):
                    os.remove(old['filepath'])
            except queue.Empty:
                pass

        self._queue.put({
            'filepath': filepath,
            'metadata': metadata,
            'retries': 0,
            'timestamp': time.time(),
        })
        logger.info(f"Queued offline: {filepath}")

    def size(self) -> int:
        return self._queue.qsize()

    def _retry_loop(self):
        """Background thread that attempts to flush the queue."""
        while self._running:
            try:
                item = self._queue.get(timeout=self.retry_interval)
                success = self._try_upload(item)
                if success:
                    logger.info(f"Offline upload success: {item['filepath']}")
                    if os.path.exists(item['filepath']):
                        os.remove(item['filepath'])
                else:
                    item['retries'] += 1
                    self._queue.put(item)
                    logger.warning(f"Offline retry #{item['retries']}: {item['filepath']}")
            except queue.Empty:
                continue
            except Exception as e:
                logger.error(f"Offline retry error: {e}")
                time.sleep(5)

    def _try_upload(self, item: dict) -> bool:
        """Attempt to upload a single queued item."""
        try:
            with open(item['filepath'], 'rb') as f:
                files = {'file': f}
                meta = item['metadata']
                resp = requests.post(
                    meta.get('url'),
                    files=files,
                    data=meta.get('data', {}),
                    timeout=30,
                )
                return resp.status_code == 201 and resp.json().get('success')
        except Exception as e:
            logger.debug(f"Offline upload attempt failed: {e}")
            return False


class Tracker:
    """
    Main tracker class that orchestrates screenshot capture,
    idle detection, uploads, and the offline queue.
    """

    def __init__(self, config: dict, api_key: str):
        self.config = config
        self.api_key = api_key
        self.vps_url = config.get('vps_url', '').rstrip('/')
        self.interval = config.get('interval_seconds', 300)
        self.image_quality = config.get('image_quality', 50)
        self.idle_timeout = config.get('idle_timeout_minutes', 5)

        self.upload_url = f"{self.vps_url}/api/upload.php"
        self.heartbeat_url = f"{self.vps_url}/api/heartbeat.php"

        self.idle_detector = IdleDetector(timeout_minutes=self.idle_timeout)
        self.offline_queue = OfflineQueue(
            max_size=config.get('max_offline_queue_size', 50),
            retry_interval=config.get('retry_interval_seconds', 60),
        )

        self._running = False
        self._thread = None
        self._session_id = None
        self._last_heartbeat_time = 0
        self._screenshot_counter = 0
        self._error_count = 0

        # Temp directory for offline queue storage
        self._temp_dir = os.path.join(
            os.environ.get('LOCALAPPDATA', os.path.expanduser('~')),
            'DOCPA_Tracker',
            'queue'
        )
        os.makedirs(self._temp_dir, exist_ok=True)

    def start(self):
        """Start the tracking loop in a background thread."""
        if self._running:
            logger.warning("Tracker is already running.")
            return

        self._running = True
        self.offline_queue.start()
        self._thread = threading.Thread(target=self._tracking_loop, daemon=True)
        self._thread.start()
        logger.info(f"Tracker started. Interval: {self.interval}s, Upload: {self.upload_url}")

    def stop(self):
        """Stop the tracking loop gracefully."""
        self._running = False
        self.offline_queue.stop()
        if self._thread:
            self._thread.join(timeout=5)
        logger.info("Tracker stopped.")

    @property
    def is_running(self) -> bool:
        return self._running

    @property
    def session_id(self):
        return self._session_id

    @property
    def screenshot_count(self) -> int:
        return self._screenshot_counter

    @property
    def queued_count(self) -> int:
        return self.offline_queue.size()

    def _tracking_loop(self):
        """Main loop: capture, detect idle, upload."""
        while self._running:
            try:
                # 1. Check if idle
                is_idle = self.idle_detector.check_idle()

                # 2. Take screenshot
                pic = pyautogui.screenshot()
                screen_w, screen_h = pic.size

                # 3. Save to temp file
                timestamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
                temp_name = f"snap_{timestamp}_{os.urandom(4).hex()}.jpg"
                temp_path = os.path.join(self._temp_dir, temp_name)

                pic.convert('RGB').save(temp_path, 'JPEG', quality=self.image_quality)
                file_size = os.path.getsize(temp_path)

                # 4. Try online upload
                upload_success = self._upload_screenshot(
                    temp_path, is_idle, screen_w, screen_h
                )

                if upload_success:
                    self._session_id = self._session_id  # Updated by response
                    self._screenshot_counter += 1
                    self.idle_detector.mark_active()
                    # Remove temp file
                    if os.path.exists(temp_path):
                        os.remove(temp_path)
                    self._error_count = 0
                else:
                    # Queue for offline retry
                    self.offline_queue.enqueue(temp_path, {
                        'url': self.upload_url,
                        'data': {
                            'api_key': self.api_key,
                            'is_idle': '1' if is_idle else '0',
                            'width': str(screen_w),
                            'height': str(screen_h),
                            'machine': os.environ.get('COMPUTERNAME', 'Unknown'),
                        },
                    })
                    self._error_count += 1

                # 5. Send heartbeat (every 60 seconds)
                self._send_heartbeat(is_idle)

                # 6. Sleep for interval
                for _ in range(self.interval):
                    if not self._running:
                        break
                    time.sleep(1)

            except Exception as e:
                logger.error(f"Tracking loop error: {e}")
                self._error_count += 1
                time.sleep(10)

    def _upload_screenshot(self, filepath: str, is_idle: bool, width: int, height: int) -> bool:
        """Upload a screenshot to the VPS. Returns True on success."""
        try:
            with open(filepath, 'rb') as f:
                files = {'file': f}
                data = {
                    'api_key': self.api_key,
                    'is_idle': '1' if is_idle else '0',
                    'width': str(width),
                    'height': str(height),
                    'machine': os.environ.get('COMPUTERNAME', 'Unknown'),
                }
                if self._session_id:
                    data['session_id'] = str(self._session_id)

                resp = requests.post(self.upload_url, files=files, data=data, timeout=30)

                if resp.status_code == 201:
                    result = resp.json()
                    if result.get('session_id'):
                        self._session_id = result['session_id']
                    return True
                else:
                    logger.warning(f"Upload returned {resp.status_code}: {resp.text[:200]}")
                    return False

        except requests.exceptions.ConnectionError as e:
            logger.warning(f"Upload connection error (offline?): {e}")
            return False
        except requests.exceptions.Timeout:
            logger.warning("Upload timed out.")
            return False
        except Exception as e:
            logger.error(f"Upload error: {e}")
            return False

    def _send_heartbeat(self, is_idle: bool):
        """Send a periodic heartbeat to mark user as active/idle."""
        now = time.time()
        if now - self._last_heartbeat_time < 60:
            return  # Only heartbeat once per minute

        self._last_heartbeat_time = now

        if not self._session_id:
            return  # No session yet

        try:
            resp = requests.post(
                self.heartbeat_url,
                json={
                    'session_id': self._session_id,
                    'is_idle': '1' if is_idle else '0',
                },
                headers={'X-API-Key': self.api_key, 'Content-Type': 'application/json'},
                timeout=15,
            )
            if resp.status_code == 200:
                logger.debug("Heartbeat sent.")
            else:
                logger.debug(f"Heartbeat failed: {resp.status_code}")
        except Exception as e:
            logger.debug(f"Heartbeat error: {e}")

    def end_session(self) -> bool:
        """End the current session on the server. Returns True on success."""
        if not self._session_id:
            return True  # Nothing to end

        try:
            resp = requests.post(
                f"{self.vps_url}/api/sessions.php?action=end",
                headers={'X-API-Key': self.api_key, 'Content-Type': 'application/json'},
                timeout=15,
            )
            success = resp.status_code == 200 and resp.json().get('success')
            if success:
                logger.info(f"Session {self._session_id} ended.")
                self._session_id = None
            return success
        except Exception as e:
            logger.error(f"End session error: {e}")
            return False