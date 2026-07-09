"""
DOCPA Tracker — User Interface

Handles:
  - System tray icon with right-click context menu
  - Settings dialog (API key, VPS URL, interval, etc.)
  - Status notifications and tooltips
  - Start/Stop/Exit controls
"""

import os
import sys
import json
import logging
import threading
import webbrowser
import tkinter as tk
from tkinter import ttk, messagebox, simpledialog

logger = logging.getLogger('docpa.interface')

# Try to import pystray — fall back to tkinter-only mode if unavailable
try:
    import pystray
    from PIL import Image, ImageDraw
    HAS_TRAY = True
except ImportError:
    HAS_TRAY = False
    logger.warning("pystray not installed. Running in windowed mode.")


class SettingsDialog:
    """
    Modal dialog for editing tracker configuration.
    """

    def __init__(self, parent, config: dict, api_key: str):
        self.result = None
        self.config = config.copy()
        self.api_key = api_key

        self.dialog = tk.Toplevel(parent)
        self.dialog.title("DOCPA Tracker Settings")
        self.dialog.geometry("480x420")
        self.dialog.resizable(False, False)
        self.dialog.transient(parent)
        self.dialog.grab_set()

        # Center on parent
        self.dialog.update_idletasks()
        pw = parent.winfo_width()
        ph = parent.winfo_height()
        px = parent.winfo_x()
        py = parent.winfo_y()
        dw = self.dialog.winfo_width()
        dh = self.dialog.winfo_height()
        x = px + (pw - dw) // 2
        y = py + (ph - dh) // 2
        self.dialog.geometry(f"+{x}+{y}")

        self._build_ui()

    def _build_ui(self):
        main = ttk.Frame(self.dialog, padding=20)
        main.pack(fill=tk.BOTH, expand=True)

        # API Key
        ttk.Label(main, text="API Key:").grid(row=0, column=0, sticky=tk.W, pady=(0, 4))
        self.api_entry = ttk.Entry(main, width=50, show="*")
        self.api_entry.insert(0, self.api_key)
        self.api_entry.grid(row=0, column=1, sticky=tk.EW, pady=(0, 4))
        ttk.Button(main, text="Show", command=self._toggle_api_visibility).grid(row=0, column=2, padx=(4, 0))

        # VPS URL
        ttk.Label(main, text="VPS URL:").grid(row=1, column=0, sticky=tk.W, pady=(0, 4))
        self.url_entry = ttk.Entry(main, width=50)
        self.url_entry.insert(0, self.config.get('vps_url', ''))
        self.url_entry.grid(row=1, column=1, columnspan=2, sticky=tk.EW, pady=(0, 4))

        # Interval
        ttk.Label(main, text="Interval (seconds):").grid(row=2, column=0, sticky=tk.W, pady=(0, 4))
        self.interval_entry = ttk.Entry(main, width=12)
        self.interval_entry.insert(0, str(self.config.get('interval_seconds', 300)))
        self.interval_entry.grid(row=2, column=1, sticky=tk.W, pady=(0, 4))

        # Image quality
        ttk.Label(main, text="Image Quality (1-100):").grid(row=3, column=0, sticky=tk.W, pady=(0, 4))
        self.quality_entry = ttk.Entry(main, width=12)
        self.quality_entry.insert(0, str(self.config.get('image_quality', 50)))
        self.quality_entry.grid(row=3, column=1, sticky=tk.W, pady=(0, 4))

        # Idle timeout
        ttk.Label(main, text="Idle Timeout (minutes):").grid(row=4, column=0, sticky=tk.W, pady=(0, 4))
        self.idle_entry = ttk.Entry(main, width=12)
        self.idle_entry.insert(0, str(self.config.get('idle_timeout_minutes', 5)))
        self.idle_entry.grid(row=4, column=1, sticky=tk.W, pady=(0, 4))

        # Auto-start checkbox
        self.auto_start_var = tk.BooleanVar(value=self.config.get('auto_start', True))
        ttk.Checkbutton(main, text="Auto-start on login", variable=self.auto_start_var).grid(
            row=5, column=0, columnspan=3, sticky=tk.W, pady=(8, 4)
        )

        # Minimize to tray
        self.tray_var = tk.BooleanVar(value=self.config.get('minimize_to_tray', True))
        ttk.Checkbutton(main, text="Minimize to system tray", variable=self.tray_var).grid(
            row=6, column=0, columnspan=3, sticky=tk.W, pady=(0, 4)
        )

        # Separator
        ttk.Separator(main, orient=tk.HORIZONTAL).grid(
            row=7, column=0, columnspan=3, sticky=tk.EW, pady=(12, 12)
        )

        # Buttons
        btn_frame = ttk.Frame(main)
        btn_frame.grid(row=8, column=0, columnspan=3)
        ttk.Button(btn_frame, text="Open Dashboard", command=self._open_dashboard).pack(side=tk.LEFT, padx=4)
        ttk.Button(btn_frame, text="Cancel", command=self.dialog.destroy).pack(side=tk.LEFT, padx=4)
        ttk.Button(btn_frame, text="Save", command=self._save).pack(side=tk.LEFT, padx=4)

        main.columnconfigure(1, weight=1)

    def _toggle_api_visibility(self):
        if self.api_entry.cget('show') == '*':
            self.api_entry.config(show='')
        else:
            self.api_entry.config(show='*')

    def _open_dashboard(self):
        url = self.url_entry.get().rstrip('/') + '/dashboard/'
        webbrowser.open(url)

    def _save(self):
        api_key = self.api_entry.get().strip()
        if not api_key:
            messagebox.showerror("Error", "API Key is required.", parent=self.dialog)
            return

        url = self.url_entry.get().strip().rstrip('/')
        if not url.startswith('http'):
            messagebox.showerror("Error", "VPS URL must start with http:// or https://", parent=self.dialog)
            return

        try:
            interval = max(30, int(self.interval_entry.get()))
            quality = max(1, min(100, int(self.quality_entry.get())))
            idle_timeout = max(1, int(self.idle_entry.get()))
        except ValueError:
            messagebox.showerror("Error", "Invalid number in interval, quality, or timeout.", parent=self.dialog)
            return

        self.result = {
            'api_key': api_key,
            'config': {
                'vps_url': url,
                'interval_seconds': interval,
                'image_quality': quality,
                'idle_timeout_minutes': idle_timeout,
                'auto_start': self.auto_start_var.get(),
                'minimize_to_tray': self.tray_var.get(),
            }
        }
        self.dialog.destroy()


class TrayApp:
    """
    System tray application using pystray.
    Falls back to a simple tkinter window if pystray is not available.
    """

    def __init__(self, on_start=None, on_stop=None, on_settings=None, on_exit=None):
        self.on_start = on_start
        self.on_stop = on_stop
        self.on_settings = on_settings
        self.on_exit = on_exit

        self._tray_icon = None
        self._running = False

    def create_tray(self, tracking: bool = False, queued: int = 0):
        """Create or update the system tray icon."""
        if not HAS_TRAY:
            return

        if self._tray_icon:
            # Update existing tray
            self._tray_icon.title = self._get_tooltip(tracking, queued)
            # Tray icon image can't be changed easily — recreate if needed
            return

        # Create icon image (16x16)
        icon_image = self._create_icon(tracking)

        menu = pystray.Menu(
            pystray.MenuItem("Start Tracking", self._on_start, enabled=lambda: not tracking),
            pystray.MenuItem("Stop Tracking", self._on_stop, enabled=lambda: tracking),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Settings", self._on_settings),
            pystray.MenuItem("Open Dashboard", self._open_dashboard),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Exit", self._on_exit_cb),
        )

        self._tray_icon = pystray.Icon(
            "docpa_tracker",
            icon_image,
            self._get_tooltip(tracking, queued),
            menu,
        )

        # Run in background thread
        threading.Thread(target=self._tray_icon.run, daemon=True).start()

    def _create_icon(self, tracking: bool):
        """Create a simple 16x16 icon."""
        size = 16
        img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
        draw = ImageDraw.Draw(img)

        color = (52, 211, 153) if tracking else (154, 160, 176)
        # Draw a small circle
        draw.ellipse([2, 2, 13, 13], fill=color + (255,))
        return img

    def _get_tooltip(self, tracking: bool, queued: int = 0):
        status = "Tracking" if tracking else "Stopped"
        tip = f"DOCPA Tracker — {status}"
        if queued > 0:
            tip += f" ({queued} offline)"
        return tip

    def update_status(self, tracking: bool, queued: int = 0):
        """Update the tray icon tooltip after status change."""
        if self._tray_icon:
            self._tray_icon.title = self._get_tooltip(tracking, queued)
            self._tray_icon.icon = self._create_icon(tracking)

    def _on_start(self):
        if self.on_start:
            self.on_start()

    def _on_stop(self):
        if self.on_stop:
            self.on_stop()

    def _on_settings(self):
        if self.on_settings:
            self.on_settings()

    def _open_dashboard(self):
        webbrowser.open("https://tracker.docharteredaccountant.com/dashboard")

    def _on_exit_cb(self):
        if self.on_exit:
            self.on_exit()

    def stop_tray(self):
        """Stop the tray icon."""
        if self._tray_icon:
            self._tray_icon.stop()
            self._tray_icon = None


class SettingsWindow:
    """
    Full windowed interface (used when pystray is not available).
    """

    def __init__(self):
        self.root = tk.Tk()
        self.root.title("DOCPA Tracker")
        self.root.geometry("400x350")
        self.root.resizable(False, False)

        self.config = {}
        self.api_key = ""
        self.callbacks = {}

        self._build_ui()

    def _build_ui(self):
        main = ttk.Frame(self.root, padding=20)
        main.pack(fill=tk.BOTH, expand=True)

        ttk.Label(main, text="DOCPA Time Tracker", font=("", 16, "bold")).pack(pady=(0, 20))

        self.status_label = ttk.Label(main, text="Status: Stopped", font=("", 10))
        self.status_label.pack(pady=(0, 10))

        self.btn_start = ttk.Button(main, text="Start Tracking", command=self._on_start)
        self.btn_start.pack(pady=4)

        self.btn_stop = ttk.Button(main, text="Stop Tracking", command=self._on_stop, state=tk.DISABLED)
        self.btn_stop.pack(pady=4)

        ttk.Button(main, text="Settings", command=self._on_settings).pack(pady=4)
        ttk.Button(main, text="Open Dashboard", command=self._open_dashboard).pack(pady=4)
        ttk.Button(main, text="Exit", command=self._on_exit).pack(pady=(20, 0))

    def set_callbacks(self, on_start, on_stop, on_settings, on_exit):
        self.callbacks = {
            'start': on_start,
            'stop': on_stop,
            'settings': on_settings,
            'exit': on_exit,
        }

    def update_status(self, tracking: bool, queued: int = 0):
        status = "Tracking" if tracking else "Stopped"
        extra = f" ({queued} offline)" if queued > 0 else ""
        self.status_label.config(text=f"Status: {status}{extra}")
        self.btn_start.config(state=tk.DISABLED if tracking else tk.NORMAL)
        self.btn_stop.config(state=tk.NORMAL if tracking else tk.DISABLED)

    def _on_start(self):
        if 'start' in self.callbacks:
            self.callbacks['start']()

    def _on_stop(self):
        if 'stop' in self.callbacks:
            self.callbacks['stop']()

    def _on_settings(self):
        if 'settings' in self.callbacks:
            self.callbacks['settings']()

    def _open_dashboard(self):
        webbrowser.open("https://tracker.docharteredaccountant.com/dashboard")

    def _on_exit(self):
        if 'exit' in self.callbacks:
            self.callbacks['exit']()

    def run(self):
        self.root.mainloop()

    def close(self):
        self.root.quit()
        self.root.destroy()