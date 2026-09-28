#!/usr/bin/env bash
#
# Accounts and an incoming chat for trying IT Chat by hand (e.g. open a ticket or transfer a chat
# yourself as glpi):
#
#   manual-test.sh up     test accounts with new passwords, then an incoming chat from each requester
#                         (unless it already has an open one):
#                           demo.user, demo.user2                      requesters (Self-Service)
#                           demo.tech, demo.tech2, demo.hotliner,      technicians = transfer targets
#                           demo.supervisor                            (Technician x2, Hotliner, Supervisor)
#                           demo.inactive                              deactivated technician: must NOT
#                                                                      be in the transfer list
#   manual-test.sh chat   one more incoming chat from each requester (after `up`)
#   manual-test.sh code   current 2FA code of each technician (their login asks for it)
#   manual-test.sh down   delete all these accounts with their chats, tickets and Problems
#
# Passwords are printed and kept in ~/.itchat-manual-test (mode 600). Technicians have 2FA set up with
# a known secret: at the code prompt, type what `manual-test.sh code` prints (or add the secret it
# shows to an authenticator app).
#
# Env: NS (default glpi), GLPI_URL (default http://localhost:30080)

set -euo pipefail

NS=${NS:-glpi}
export GLPI_URL=${GLPI_URL:-http://localhost:30080}
HERE=$(cd "$(dirname "$0")" && pwd)
CREDS=$HOME/.itchat-manual-test

php_in_pod() {
    local pod
    pod=$(kubectl -n "$NS" get pods -l app=glpi-app --field-selector=status.phase=Running -o jsonpath='{.items[0].metadata.name}')
    kubectl -n "$NS" cp "$HERE/manual-test.php" "$pod:/tmp/manual-test.php" -c glpi-app
    kubectl -n "$NS" exec "$pod" -c glpi-app -- php /tmp/manual-test.php "$@"
}

send_chat() {
    [ -f "$CREDS" ] || { echo "run '$0 up' first" >&2; exit 1; }
    (cd "$HERE/tests" && ITCHAT_TEST_CREDS=$(cat "$CREDS") NEW_CHAT=${1:-0} python3 - <<'EOF'
import os
from lib import login, post, poll, close_open_chat
chats = {
    'demo.user': ['สวัสดีครับ เครื่องพิมพ์ชั้น 3 กระดาษติดอีกแล้ว เป็นแบบนี้ทุกวันเลยครับ',
                  'ลองเปิดปิดเครื่องแล้วก็ยังเป็นอยู่ครับ'],
    'demo.user2': ['ขอสิทธิ์เข้าโฟลเดอร์บัญชีในไดรฟ์กลางหน่อยครับ', 'ใช้ทำงานปิดงบเดือนนี้ครับ'],
}
for login_name, lines in chats.items():
    s = login(login_name)
    if os.environ['NEW_CHAT'] == '1':
        close_open_chat(s)  # `chat`: a new chat each time
    else:
        _, r = poll(s, open=0)  # `up`: a chat that is still open (maybe being tested) is left alone
        if isinstance(r, dict) and r.get('conv') and r['conv']['status'] == 'open':
            print(f"chat #{r['conv']['id']} from {login_name} is still open, left as it is")
            continue
    conv = 0
    for text in lines:
        c, r = post(s, action='send', conv=conv, content=text)
        if c != 200:
            raise SystemExit(f'send failed for {login_name}: {c} {r}')
        conv = r['conv']
    print(f'incoming chat #{conv} from {login_name}')
EOF
    )
}

case "${1:-}" in
    up)
        out=$(php_in_pod users)
        printf '%s\n' "$out" | grep '^transfer list:'
        (umask 077; printf '%s\n' "$out" | tail -1 > "$CREDS")
        python3 -c 'import json,sys; [print(f"{k:16} {v}") for k, v in json.load(open(sys.argv[1])).items() if k != "_totp"]' "$CREDS"
        send_chat
        ;;
    chat)
        send_chat 1
        ;;
    code)
        [ -f "$CREDS" ] || { echo "run '$0 up' first" >&2; exit 1; }
        (cd "$HERE/tests" && python3 - "$CREDS" <<'EOF'
import json, sys, time
from totp import code
secrets = json.load(open(sys.argv[1])).get('_totp', {})
for login, secret in secrets.items():
    print(f'{login:16} {code(secret)}   (secret {secret})')
print(f'codes change in {int(30 - time.time() % 30)} s')
EOF
        )
        ;;
    down)
        php_in_pod clean | tail -1
        rm -f "$CREDS"
        ;;
    *)
        echo "usage: $0 up|chat|code|down" >&2
        exit 2
        ;;
esac
