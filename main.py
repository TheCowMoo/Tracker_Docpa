import threading
import tkinter as tk
from tkinter import simpledialog
import tracker
import interface
import time

# 1. Ask who is using the app
root = tk.Tk()
root.withdraw()
USER = simpledialog.askstring("DOCPA", "Enter your Name:")

# 2. Reverted to /dashboard path
MY_VPS = "https://tracker.docharteredaccountant.com/dashboard"

if USER:
    camera_thread = threading.Thread(
        target=tracker.run_camera, 
        args=(MY_VPS, USER), 
        daemon=True
    )
    camera_thread.start()

    interface.show_dashboard(MY_VPS, USER)

    print(f"\n--- DOCPA is now tracking: {USER} ---")
    print("Keep this window open to continue syncing.")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\nStopping Tracker...")