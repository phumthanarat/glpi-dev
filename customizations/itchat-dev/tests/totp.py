"""RFC 6238 TOTP (what GLPI's 2FA and authenticator apps use), stdlib only, so both the
requests-based suites and the Playwright scripts can answer GLPI's MFA prompt."""
import base64
import hashlib
import hmac
import json
import os
import struct
import time


def code(secret: str, at: float | None = None, digits: int = 6, period: int = 30) -> str:
    key = base64.b32decode(secret.upper() + '=' * (-len(secret) % 8))
    counter = int((time.time() if at is None else at) // period)
    h = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    o = h[-1] & 0x0F
    return str((struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 10 ** digits).zfill(digits)


def secret_for(login: str) -> str | None:
    """TOTP secret of a test account (fixtures.php puts them in ITCHAT_TEST_CREDS["_totp"])."""
    return json.loads(os.environ.get('ITCHAT_TEST_CREDS', '{}')).get('_totp', {}).get(login)


def fresh_code(secret: str) -> str:
    """A code with at least 5 s left, so it doesn't expire between typing and submitting."""
    if 30 - time.time() % 30 < 5:
        time.sleep(30 - time.time() % 30 + 0.5)
    return code(secret)


def browser_mfa(page, login: str) -> None:
    """If a Playwright page is on GLPI's MFA prompt, type the current code and submit."""
    if '/MFA/Prompt' not in page.url:
        return
    secret = secret_for(login)
    if not secret:
        raise RuntimeError(f'MFA prompt for {login} but no TOTP secret')
    digits = fresh_code(secret)
    boxes = page.locator('input[name="totp_code[]"]')
    for i, d in enumerate(digits):
        boxes.nth(i).fill(d)
    if '/MFA/Prompt' in page.url:
        page.locator('form[action*="MFA/Verify"] button[type="submit"]').first.click()
    page.wait_for_load_state('networkidle')
