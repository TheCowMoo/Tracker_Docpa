import pyautogui
import requests
import time
import os
from PIL import Image

def run_camera(vps_url, user_name, interval=10):
    print(f"Syncing to {vps_url} every {interval} seconds...")
    
    while True:
        # Create a unique filename to avoid 'Permission Denied' lock errors
        temp_file = f"shot_{int(time.time())}.jpg"
        
        try:
            # 1. Take Screenshot
            pic = pyautogui.screenshot()
            
            # 2. Save with unique name
            pic.convert("RGB").save(temp_file, "JPEG", quality=50)
            
            # 3. Send to VPS (hits /dashboard/upload.php)
            with open(temp_file, 'rb') as f:
                files = {'file': f}
                data = {'user': user_name}
                response = requests.post(f"{vps_url}/upload.php", files=files, data=data, timeout=15)
            
            if "Success" in response.text:
                print(f"[{time.strftime('%H:%M:%S')}] Sync Successful.")
            else:
                print(f"Server Error: {response.text}")
                
        except Exception as e:
            print(f"Sync Error: {e}")
            
        finally:
            # 4. Cleanup: Always try to delete the file after the attempt
            if os.path.exists(temp_file):
                try:
                    os.remove(temp_file)
                except:
                    pass # Ignore if still locked; the next loop uses a new name anyway
                
        time.sleep(interval)