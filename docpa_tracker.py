import pyautogui
import requests
import time
import os
import tkinter as tk
from tkinter import simpledialog
from PIL import Image

# --- 1. ASK FOR USER NAME ---
root = tk.Tk()
root.withdraw()
USER_NAME = simpledialog.askstring("DOCPA Tracker", "Enter your Name to Start:")

if not USER_NAME:
    USER_NAME = "Unknown_User"

# --- 2. SETTINGS ---
# We will change this URL once your VPS is ready!
VPS_URL = "http://your-vps-ip:5000/upload" 
INTERVAL = 300 # 5 minutes

def start_tracking():
    print(f"DOCPA Tracker Active for {USER_NAME}")
    while True:
        try:
            # Snap the screen
            pic = pyautogui.screenshot()
            
            # Shrink it (Save space on your VPS!)
            temp_name = "snap.jpg"
            pic.convert("RGB").save(temp_name, "JPEG", quality=50)
            
            # Send to VPS
            with open(temp_name, 'rb') as f:
                files = {'file': f}
                data = {'user': USER_NAME}
                requests.post(VPS_URL, files=files, data=data, timeout=10)
            
            # Clean up computer
            if os.path.exists(temp_name):
                os.remove(temp_name)
                
        except Exception as e:
            print("Waiting for VPS to be online...")

        time.sleep(INTERVAL)

if __name__ == "__main__":
    start_tracking()