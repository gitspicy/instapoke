"""
Opie's games. Shares Opie's brain (memory, attitude, moods, chat output) with ask.py.

Higher or Lower (one player at a time, everyone else queues):
    python games.py hilo <user> <bet> <balance>   start a game, or join the queue (bet is charged now)
    python games.py hilo <user>                   show who's playing and who's waiting
    python games.py higher <user>                 guess the next card is higher
    python games.py lower <user>                  guess the next card is lower
    python games.py leave <user>                  leave the queue (bet refunded)

Money: every command writes how much to TAKE from the person who typed it to GAME_CHARGE,
and how much to GIVE them to GAME_PAY (0 when nothing). Mix It Up moves the N-persons.
"""
import os, sys, re, time, random
import ask

GAME_CHARGE = os.path.join(ask.HERE, "game_charge.txt")   # N-persons to take from whoever ran the command
GAME_PAY = os.path.join(ask.HERE, "game_pay.txt")         # N-persons to give them

HILO_MIN_BET = 100
HILO_MAX_BET = 50000                     # 50k doubled 5 times = 1.6M max payout
HILO_ROUNDS = 5                          # guesses per game (a push uses one up)
HILO_WIN_MULT = 2                        # each win multiplies the pot by this. 2 = 50k becomes 1.6M after 5 wins.
                                         # Smart players average about 6.7x their bet at 2, 1.7x at 1.5, 1.2x at 1.4
HILO_TIMEOUT = 120                       # seconds a player can stall before someone waiting can bump them (they forfeit)
HILO_QUEUE_MAX = 10
HILO_NO_PLAY = ("HOSTILE", "MUTINY")     # attitude levels Opie won't play with: he keeps the bet

RANKS = ["2", "3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K", "A"]   # ace high
SUITS = ["♠", "♥", "♦", "♣"]                              # spades hearts diamonds clubs

WIN_LINES = ["Lucky bastard.", "Even a broken clock, brothaman.", "Don't get cocky.", "The Philly Crew would be proud.",
             "Ride the heater, mothahucka."]
PUSH_LINES = ["Same rank, nobody wins.", "Boring, like Antman.", "That one's a wash."]
LOSE_LINES = ["Read 'em and weep, brothaman.", "Into the wine fund it goes.", "Opie wins again. Shocking.",
              "Tough break, mothahucka. Not really, I'm thrilled."]


def money(charge=0, pay=0):
    for path, amount in ((GAME_CHARGE, charge), (GAME_PAY, pay)):
        with open(path, "w", encoding="utf-8") as f:
            f.write(str(int(amount)))

def card_name(c):
    return RANKS[c[0]] + SUITS[c[1]]

def new_deck():
    deck = [[r, s] for r in range(13) for s in range(4)]
    random.shuffle(deck)
    return deck

def start_game(state, user, bet):
    deck = new_deck()
    first = deck.pop()
    state["hilo"] = {"player": user, "bet": bet, "deck": deck, "card": first, "round": 1, "wins": 0, "last": time.time()}
    return (f"@{user} you're up! {bet:,} {ask.CURRENCY} on the line. "
            f"Card: {card_name(first)}. !higher or !lower? (guess 1/{HILO_ROUNDS})")

def next_in_line(state):
    """Current game is over: start the next queued player's game, if anyone's waiting."""
    state["hilo"] = None
    queue = state.get("hilo_queue", [])
    if not queue:
        return ""
    nxt = queue.pop(0)
    return " " + start_game(state, nxt["user"], nxt["bet"])

def finale(user, text_if_offline, situation):
    """One short Opie line for the big moments; the numbers are already in the message."""
    try:
        with ask.StateLock():
            mood = ask.current_mood(ask.load_state())
        line = ask.ollama([
            {"role": "system", "content": f"{ask.SYSTEM} {mood} You're the dealer in a Higher or Lower card game. "
                                          "One short sentence, no numbers."},
            {"role": "user", "content": situation},
        ])
        line = ask.scrub(ask.RUDE_LEAD.sub("", re.sub(r"\s+", " ", line)).strip())
        return line or text_if_offline
    except Exception:
        return text_if_offline


def cmd_hilo(user, raw_bet="", raw_balance=""):
    user = user.lstrip("@")
    key = ask.norm(user)
    money()

    if not raw_bet:                      # no bet: just show the table
        with ask.StateLock():
            state = ask.load_state()
        game, queue = state.get("hilo"), state.get("hilo_queue", [])
        if not game:
            ask.write(f"@{user} the Higher or Lower table's open. Type !hilo and a bet ({HILO_MIN_BET:,} to {HILO_MAX_BET:,}).")
        else:
            waiting = ", ".join(q["user"] for q in queue) or "nobody"
            ask.write(f"{game['player']} is playing ({card_name(game['card'])}, guess {game['round']}/{HILO_ROUNDS}). "
                      f"Waiting: {waiting}.")
        return

    balance = ask.parse_balance(raw_balance)
    if balance is None:
        ask.write(f"@{user} I can't see your wallet right now, brothaman. Try again in a bit.")
        return
    bet = ask.parse_amount(raw_bet, balance)
    if bet is None:
        ask.write(f"@{user} bet how much? e.g. !hilo 5000, brothaman.")
        return
    bet = min(bet, HILO_MAX_BET) if raw_bet.strip().lower() in ("all", "max", "everything") else bet
    if bet < HILO_MIN_BET or bet > HILO_MAX_BET:
        ask.write(f"@{user} bets are {HILO_MIN_BET:,} to {HILO_MAX_BET:,} {ask.CURRENCY}, brothaman.")
        return
    if bet > balance:
        ask.write(f"@{user} you've only got {balance:,} {ask.CURRENCY}. Nice try, brothaman.")
        return

    def join(state):
        u = ask.get_user(state, key)
        if key not in ask.EXEMPT and ask.level(u["dis"]) in HILO_NO_PLAY:
            state["wine_fund"] = state.get("wine_fund", 0) + bet
            return "grudge", (f"@{user} I'll take that {bet:,} {ask.CURRENCY}, thanks. But I'm not playing with you. "
                              "Come back when you've learned some manners, brothaman.")
        game = state.get("hilo")
        queue = state.setdefault("hilo_queue", [])
        if game and ask.norm(game["player"]) == key:
            return "busy", f"@{user} you're already playing, brothaman. !higher or !lower on that {card_name(game['card'])}."
        if any(ask.norm(q["user"]) == key for q in queue):
            spot = next(i for i, q in enumerate(queue, 1) if ask.norm(q["user"]) == key)
            return "busy", f"@{user} you're already #{spot} in line. Patience, brothaman."
        bumped = ""
        if game and time.time() - game["last"] > HILO_TIMEOUT:
            bumped = f"{game['player']} fell asleep at the table and forfeits {int(game['bet'] * HILO_WIN_MULT ** game['wins']):,}. "
            state["wine_fund"] = state.get("wine_fund", 0) + game["bet"]
            game = None
            state["hilo"] = None
            if queue:                    # people already waiting go first
                queue.append({"user": user, "bet": bet})
                return "charge", bumped + next_in_line(state).strip() + f" @{user} you're #{len(queue)} in line."
        if not game:
            return "charge", bumped + start_game(state, user, bet)
        if len(queue) >= HILO_QUEUE_MAX:
            return "busy", f"@{user} the line's full, brothaman. Try again in a bit."
        queue.append({"user": user, "bet": bet})
        return "charge", (f"@{user} {game['player']}'s at the table. You're #{len(queue)} in line with {bet:,} {ask.CURRENCY}. "
                          "I'll deal you in when they bust. (!leave to back out)")
    outcome, text = ask.update_state(join)
    if outcome in ("charge", "grudge"):
        money(charge=bet)
    ask.write(ask.split_reply(text))


def cmd_guess(user, guess):
    user = user.lstrip("@")
    key = ask.norm(user)
    money()

    def play(state):
        game = state.get("hilo")
        queue = state.get("hilo_queue", [])
        if not game:
            return 0, f"@{user} nobody's playing. Type !hilo and a bet to start, brothaman.", None
        if ask.norm(game["player"]) != key:
            spot = next((i for i, q in enumerate(queue, 1) if ask.norm(q["user"]) == key), None)
            where = f"you're #{spot} in line" if spot else "type !hilo and a bet to get in line"
            return 0, f"@{user} it's {game['player']}'s game, {where}.", None
        old = game["card"]
        new = game["deck"].pop()
        game["card"], game["last"] = new, time.time()
        shown = f"{card_name(old)} → {card_name(new)}."
        if new[0] == old[0]:
            result = "push"
        elif (new[0] > old[0]) == (guess == "higher"):
            result = "win"
            game["wins"] += 1
        else:
            result = "lose"
        pot = int(game["bet"] * HILO_WIN_MULT ** game["wins"])

        if result == "lose":
            lost = int(game["bet"] * HILO_WIN_MULT ** game["wins"])
            state["wine_fund"] = state.get("wine_fund", 0) + game["bet"]
            situation = (f"{user} just lost a {lost:,} pot at Higher or Lower on guess {game['round']} of {HILO_ROUNDS}. "
                         "Gloat, or rub it in.")
            return 0, f"@{user} {shown} WRONG. You lose {lost:,} {ask.CURRENCY}.", (situation, random.choice(LOSE_LINES),
                                                                                       next_in_line(state))
        if game["round"] >= HILO_ROUNDS:
            situation = (f"{user} survived all {HILO_ROUNDS} guesses at Higher or Lower and walks away with {pot:,} "
                         f"(bet was {game['bet']:,}, {game['wins']} wins). React.")
            return pot, f"@{user} {shown} {'PUSH' if result == 'push' else 'WIN'}! Game over: you take home {pot:,} {ask.CURRENCY}!", \
                (situation, "Get outta here, you lucky mothahucka.", next_in_line(state))
        game["round"] += 1
        line = random.choice(WIN_LINES if result == "win" else PUSH_LINES)
        return 0, (f"@{user} {shown} {'WIN' if result == 'win' else 'PUSH'}! {line} Pot: {pot:,}. "
                   f"{card_name(new)}: !higher or !lower? (guess {game['round']}/{HILO_ROUNDS})"), None
    pay, text, ending = ask.update_state(play)

    if ending:                           # game over: one Opie line, then deal the next player in
        situation, fallback, next_game = ending
        text = f"{text} {finale(user, fallback, situation)}{next_game}"
    money(pay=pay)
    ask.write(ask.split_reply(text))


def cmd_leave(user):
    user = user.lstrip("@")
    key = ask.norm(user)
    money()
    def leave(state):
        queue = state.get("hilo_queue", [])
        for i, q in enumerate(queue):
            if ask.norm(q["user"]) == key:
                queue.pop(i)
                return q["bet"]
        return 0
    refund = ask.update_state(leave)
    if refund:
        money(pay=refund)
        ask.write(f"@{user} chickened out. Here's your {refund:,} {ask.CURRENCY} back, brothaman.")
    else:
        ask.write(f"@{user} you're not in line, brothaman.")


def main():
    args = sys.argv[1:]
    cmd = args[0].lower() if args else ""
    user = args[1] if len(args) > 1 else "someone"
    if cmd == "hilo":
        cmd_hilo(user, *(args[2:] + ["", ""])[:2])
    elif cmd in ("higher", "hi", "h", "lower", "lo", "l"):
        cmd_guess(user, "higher" if cmd.startswith("h") else "lower")
    elif cmd == "leave":
        cmd_leave(user)
    else:
        ask.write("Opie's games: hilo, higher, lower, leave.")


if __name__ == "__main__":
    ask.backup_state()
    try:
        main()
    except Exception:
        ask.log_error("games crash")
        money()                          # never move money on a crash
        try:
            ask.write("Opie dropped the cards, brothaman. Try that again in a sec.")
        except Exception:
            pass
