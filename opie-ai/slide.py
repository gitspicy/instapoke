import os
import sys
import logging
import obsws_python as obs

SOURCE = "backgrounds"    # your Image Slide Show source name in OBS (exact spelling and capitals)
PASSWORD_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "obs_password.txt")   # same file Opie uses

ACTIONS = {
    "next": "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_NEXT",
    "prev": "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_PREVIOUS",
    "first": "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_RESTART",
}
ALIASES = {"previous": "prev", "back": "prev", "restart": "first", "start": "first"}

logging.getLogger("obsws_python").setLevel(logging.CRITICAL + 1)   # keep connection errors (and the password) out of chat

# python slide.py next | prev | first
choice = sys.argv[1].lower() if len(sys.argv) > 1 else "next"
choice = ALIASES.get(choice, choice)
if choice not in ACTIONS:
    choice = "next"

try:
    with open(PASSWORD_FILE, encoding="utf-8") as f:
        password = f.read().strip()
except OSError:
    password = ""

try:
    cl = obs.ReqClient(host="localhost", port=4455, password=password, timeout=3)
except Exception:
    print("Background change failed: can't connect to OBS (is it open, WebSocket on, password right?)")
    sys.exit()

try:
    cl.trigger_media_input_action(SOURCE, ACTIONS[choice])
    print({"next": "Background: next", "prev": "Background: previous", "first": "Background: back to the first"}[choice])
except Exception:
    print(f"Background change failed: OBS has no source called '{SOURCE}'")
cl.disconnect()
