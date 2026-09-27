"""Post-deploy browser check: the chat widget renders for a requester and a technician,
with no JavaScript errors. Used by deploy.sh (with automatic asset rollback on failure).

Env: GLPI_URL, CHECK_USERS = "login:password,login:password" (default post-only + glpi).
Exit 0 = ok, 1 = widget missing or JS error.
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get('GLPI_URL', 'http://localhost:30080').rstrip('/')
USERS = [u.split(':', 1) for u in os.environ.get('CHECK_USERS', 'post-only:postonly,glpi:glpi').split(',')]

ok = True
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for login, password in USERS:
        page = br.new_page(viewport={'width': 1280, 'height': 800})
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(BASE + '/')
        page.fill('input[name="login_name"]', login)
        page.fill('input[name="login_password"]', password)
        page.press('input[name="login_password"]', 'Enter')
        page.wait_for_load_state('networkidle')
        try:
            page.wait_for_selector('.itchat-fab', state='visible', timeout=10000)
            page.click('.itchat-fab')
            page.wait_for_selector('.itchat-panel', state='visible', timeout=5000)
            visible = True
        except Exception:
            visible = False
        page.wait_for_timeout(1500)  # let the first poll render
        good = visible and not errors
        ok &= good
        print(f"{'PASS' if good else 'FAIL'} widget for {login}: visible={visible} js_errors={errors[:3]}", flush=True)
        page.close()
    br.close()
sys.exit(0 if ok else 1)
