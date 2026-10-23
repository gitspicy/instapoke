import os
import sys
import logging
import obsws_python as obs

SOURCE = "ZLIVESTREAM"          # your OBS source name (set once)
DEFAULT_MINUTES = 10
MAX_MINUTES = 60          # cap so chat can't jump an hour
PASSWORD_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "obs_password.txt")   # same file Opie uses

logging.getLogger("obsws_python").setLevel(logging.CRITICAL + 1)   # keep connection errors (and the password) out of chat

# Optional minutes from chat, e.g. !ff 5
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
    print("Fast forward failed: can't connect to OBS (is it open, WebSocket on, password right?)")
    sys.exit()

status = cl.get_media_input_status(SOURCE)
cursor = status.media_cursor or 0        # current position in ms
duration = status.media_duration or 0    # track length in ms; -1 or 0 means OBS doesn't know it
jump = int(minutes * 60 * 1000)

if duration > 0 and cursor + jump >= duration - 5000:
    # Too close to the end: a VLC playlist can skip to the next track, anything else stops just short of the end
    if cl.get_input_settings(SOURCE).input_kind == "vlc_source":
        cl.trigger_media_input_action(SOURCE, "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_NEXT")
        print("Fast forward: skipped to the next track")
    else:
        cl.set_media_input_cursor(SOURCE, duration - 5000)
        print("Fast forward: jumped to the end")
else:
    cl.set_media_input_cursor(SOURCE, cursor + jump)
    print(f"Fast forward: +{minutes:g} min")
cl.disconnect()
