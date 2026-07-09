"""
DOCPA Tracker — Main Entry Point

Orchestrates:
  - Configuration loading/saving
  - Tracker engine (screenshots, idle detection, offline queue)
  - UI (system tray or windowed)
  - Auto-start with Windows
  - Logging
"""

import os
import sys
import json
import time
import logging
import threading
import tkinter as tk
from tkinter import messagebox

# Add project root to path
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)

from tracker import Tracker
from interface import SettingsDialog, TrayApp, SettingsWindow, HAS_TRAY

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
CONFIG_PATH = os.path.join(BASE_DIR, 'config.json')
LOG_DIR = os.path.join(
    os.environ.get('LOCALAPPDATA', os.path.expanduser('~')),
    'DOCPA_Tracker'
)
LOG_PATH = os.path.join(LOG_DIR, 'docpa_tracker.log')
STARTUP_KEY_PATH = os.path.join(
    os.environ.get('LOCALAPPDATA', ''),
    'Microsoft\\Windows\\Start Menu\\Programs\\Startup\\DOCPA_Tracker.lnk'
)

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
os.makedirs(LOG_DIR, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    handlers=[
        logging.FileHandler(LOG_PATH, encoding='utf-8'),
        logging.StreamHandler(sys.stdout),
    ]
)
logger = logging.getLogger('docpa.main')


# ---------------------------------------------------------------------------
# Configuration helpers
# ---------------------------------------------------------------------------

def load_config() -> dict:
    """Load config.json from disk. Returns defaults if file missing/corrupt."""
    defaults = {
        'vps_url': 'https://tracker.docharteredaccountant.com',
        'api_key': '',
        'interval_seconds': 300,
        'image_quality': 50,
        'idle_timeout_minutes': 5,
        'idle_check_interval_seconds': 30,
        'max_offline_queue_size': 50,
        'retry_interval_seconds': 60,
        'auto_start': True,
        'minimize_to_tray': True,
        'log_level': 'INFO',
    }

    if not os.path.exists(CONFIG_PATH):
        logger.info("No config.json found. Creating with defaults.")
        try:
            with open(CONFIG_PATH, 'w', encoding='utf-8') as f:
                json.dump(defaults, f, indent=4)
        except Exception as e:
            logger.error(f"Failed to write default config: {e}")
        return defaults

    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
            config = json.load(f)
        # Merge with defaults (fill in any missing keys)
        merged = {**defaults, **config}
        return merged
    except (json.JSONDecodeError, IOError) as e:
        logger.error(f"Failed to load config: {e}. Using defaults.")
        return defaults


def save_config(config: dict, api_key: str):
    """Save config and API key to disk."""
    config['api_key'] = api_key
    try:
        with open(CONFIG_PATH, 'w', encoding='utf-8') as f:
            json.dump(config, f, indent=4)
        logger.info("Config saved.")
    except Exception as e:
        logger.error(f"Failed to save config: {e}")


def set_auto_start(enabled: bool):
    """Add or remove the app from Windows Startup (via registry)."""
    import winreg
    key_path = r'Software\Microsoft\Windows\CurrentVersion\Run'
    app_name = 'DOCPA Tracker'
    exe_path = os.path.abspath(sys.argv[0])

    try:
        key = winreg.OpenKey(
            winreg.HKEY_CURRENT_USER, key_path, 0, winreg.KEY_SET_VALUE
        )
        if enabled:
            winreg.SetValueEx(key, app_name, 0, winreg.REG_SZ, f'"{exe_path}"')
            logger.info("Auto-start enabled.")
        else:
            try:
                winreg.DeleteValue(key, app_name)
                logger.info("Auto-start disabled.")
            except FileNotFoundError:
                pass
        winreg.CloseKey(key)
    except Exception as e:
        logger.error(f"Failed to set auto-start: {e}")


# ---------------------------------------------------------------------------
# Main Application
# ---------------------------------------------------------------------------

class DocpaApp:
    """Main application controller."""

    def __init__(self):
        self.config = load_config()
        self.api_key = self.config.pop('api_key', '')

        # Set log level from config
        log_level = self.config.get('log_level', 'INFO').upper()
        logging.getLogger('docpa').setLevel(getattr(logging, log_level, logging.INFO))

        self.tracker: Tracker = None
        self.tray: TrayApp = None
        self.window: SettingsWindow = None

        self._hide_root = None  # Hidden tk root for dialogs

    # -----------------------------------------------------------------------
    # Lifecycle
    # -----------------------------------------------------------------------

    def run(self):
        """Start the application."""
        logger.info("=== DOCPA Tracker starting ===")

        # Check if API key is configured
        if not self.api_key:
            logger.warning("No API key configured.")
            self._prompt_for_api_key()

        # Create hidden root for dialogs
        self._hide_root = tk.Tk()
        self._hide_root.withdraw()

        if not self.api_key:
            logger.error("No API key provided. Exiting.")
            messagebox.showerror(
                "DOCPA Tracker",
                "No API key configured.\n\n"
                "Please set your API key in Settings and try again."
            )
            self._show_settings_dialog()
            if not self.api_key:
                self._hide_root.destroy()
                return

        # Set auto-start
        if self.config.get('auto_start', True):
            set_auto_start(True)

        # Start UI
        if HAS_TRAY:
            self._start_tray_mode()
        else:
            self._start_window_mode()

    def _prompt_for_api_key(self):
        """Ask user for API key on first run."""
        from tkinter import simpledialog
        self.api_key = simpledialog.askstring(
            "DOCPA Tracker",
            "Enter your API Key:\n(Get this from the dashboard)",
            parent=self._hide_root
        ) or ''

    # -----------------------------------------------------------------------
    # Tray mode
    # -----------------------------------------------------------------------

    def _start_tray_mode(self):
        """Start the application in system tray mode."""
        self.tray = TrayApp(
            on_start=self._start_tracking,
            on_stop=self._stop_tracking,
            on_settings=self._show_settings_dialog,
            on_exit=self._exit_app,
        )

        # Auto-start tracking
        if self.config.get('auto_start', True) and self.api_key:
            self._start_tracking()

        self.tray.create_tray(
            tracking=self.tracker.is_running if self.tracker else False,
            queued=self.tracker.queued_count if self.tracker else 0,
        )

        # Keep main thread alive
        try:
            while True:
                time.sleep(1)
                # Periodically update tray status
                if self.tracker and self.tray:
                    self.tray.update_status(
                        self.tracker.is_running,
                        self.tracker.queued_count,
                    )
        except KeyboardInterrupt:
            self._exit_app()

    # -----------------------------------------------------------------------
    # Window mode
    # -----------------------------------------------------------------------

    def _start_window_mode(self):
        """Start the application in windowed mode (no tray)."""
        self.window = SettingsWindow()
        self.window.set_callbacks(
            on_start=self._start_tracking,
            on_stop=self._stop_tracking,
            on_settings=self._show_settings_dialog,
            on_exit=self._exit_app,
        )

        if self.config.get('auto_start', True) and self.api_key:
            self._start_tracking()

        self.window.update_status(
            tracking=self.tracker.is_running if self.tracker else False,
            queued=self.tracker.queued_count if self.tracker else 0,
        )

        # Status update thread
        def update_loop():
            while True:
                time.sleep(5)
                if self.window and self.tracker:
                    self.window.root.after(0, lambda: self.window.update_status(
                        self.tracker.is_running,
                        self.tracker.queued_count,
                    ))

        threading.Thread(target=update_loop, daemon=True).start()
        self.window.run()

    # -----------------------------------------------------------------------
    # Tracking
    # -----------------------------------------------------------------------

    def _start_tracking(self):
        """Start the tracking engine."""
        if self.tracker and self.tracker.is_running:
            logger.warning("Already tracking.")
            return

        if not self.api_key:
            messagebox.showwarning("DOCPA Tracker", "Please set your API key in Settings first.")
            return

        logger.info("Starting tracker...")
        self.tracker = Tracker(self.config, self.api_key)
        self.tracker.start()
        self._update_ui_status()

    def _stop_tracking(self):
        """Stop the tracking engine."""
        if not self.tracker or not self.tracker.is_running:
            return

        logger.info("Stopping tracker...")
        self.tracker.end_session()
        self.tracker.stop()
        self.tracker = None
        self._update_ui_status()

    def _update_ui_status(self):
        """Update UI elements with current tracking status."""
        tracking = self.tracker and self.tracker.is_running
        queued = self.tracker.queued_count if self.tracker else 0

        if self.tray:
            self.tray.update_status(tracking, queued)
        if self.window:
            self.window.update_status(tracking, queued)

    # -----------------------------------------------------------------------
    # Settings dialog
    # -----------------------------------------------------------------------

    def _show_settings_dialog(self):
        """Open the settings dialog (modal)."""
        if not self._hide_root:
            return

        # If tracking, stop first
        was_tracking = self.tracker and self.tracker.is_running
        if was_tracking:
            self._stop_tracking()

        dialog = SettingsDialog(self._hide_root, self.config, self.api_key)
        self._hide_root.wait_window(dialog.dialog)

        if dialog.result:
            # Apply new settings
            self.api_key = dialog.result['api_key']
            self.config.update(dialog.result['config'])
            save_config(self.config, self.api_key)

            # Update auto-start
            set_auto_start(self.config.get('auto_start', True))

            # Restart tracking if it was running
            if was_tracking and self.api_key:
                self._start_tracking()

            logger.info("Settings updated.")
        elif was_tracking and self.api_key:
            # User cancelled, but tracking was on — restart
            self._start_tracking()

    # -----------------------------------------------------------------------
    # Exit
    # -----------------------------------------------------------------------

    def _exit_app(self):
        """Clean shutdown."""
        logger.info("Shutting down...")
        self._stop_tracking()

        if self.tray:
            self.tray.stop_tray()
        if self.window:
            self.window.close()

        if self._hide_root:
            self._hide_root.destroy()

        logger.info("=== DOCPA Tracker stopped ===")
        os._exit(0)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    # Ensure single instance (simple mutex)
    import ctypes
    mutex_name = "Global\\DOCPA_Tracker_Mutex"
    try:
        mutex = ctypes.windll.kernel32.CreateMutexW(None, False, mutex_name)
        if ctypes.windll.kernel32.GetLastError() == 183:  # ERROR_ALREADY_EXISTS
            logger.warning("Another instance is already running.")
            # Bring existing window to front
            ctypes.windll.user32.MessageBoxW(0, "DOCPA Tracker is already running in your system tray.", "DOCPA Tracker", 0)
            sys.exit(0)
    except Exception:
        pass  # Non-Windows or ctypes unavailable

    app = DocpaApp()
    app.run()