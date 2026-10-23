import os
import sys
import logging
import obsws_python as obs

SOURCE = "ZLIVESTREAM"          # your OBS source name (set once)
DEFAULT_MINUTES = 10
MAX_MINUTES = 60          # cap so chat can't jump an hour
PASSWORD_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "obs_password.txt")   # same file Opie uses

logging.getLogger("obsws_python").setLevel(logging.CRITICAL + 1)   # keep connection errors (and the password) out of chat

# Optional minutes from chat, e.g. !rw 5
try:
    minutes = float(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_MINUTES
except ValueError:
    minutes = DEFAULT_MINUTES
minutes = max(1, min(minutes, MAX_MINUTES))

try:
    with open(PASSWORD_FILE, encoding="utf-8") as f:
        password = f.read().strip()
except OSError:
    password = ""

try:
    cl = obs.ReqClient(host="localhost", port=4455, password=password, timeout=3)
except Exception:
    print("Rewind failed: can't connect to OBS (is it open, WebSocket on, password right?)")
    sys.exit()

status = cl.get_media_input_status(SOURCE)
cursor = status.media_cursor or 0        # current position in ms
jump = int(minutes * 60 * 1000)

# Go back, but never before the start of the track
cl.set_media_input_cursor(SOURCE, max(0, cursor - jump))
print(f"Rewind: -{minutes:g} min")
cl.disconnect()
