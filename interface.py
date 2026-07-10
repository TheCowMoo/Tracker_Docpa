import webbrowser

def show_dashboard(vps_url, user_name):
    # This will open: https://tracker.docharteredaccountant.com/dashboard/?user=nathan
    dashboard_url = f"{vps_url}/?user={user_name}"
    print(f"Opening Dashboard: {dashboard_url}")
    webbrowser.open(dashboard_url)