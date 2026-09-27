"""
Tiny internal form for setting mailpit's SMTP relay credentials (the
values that power its "Release"/resend feature), so they don't have to
be pasted into a terminal each time.

Deliberately narrow: this only ever touches two objects, both
pre-existing and named explicitly in its RBAC Role (see
k8s/overlays/local-dev/mailpit-config-form-rbac.yaml) — it cannot
create/read/modify anything else in the cluster.

  - Secret "mailpit-relay" (namespace glpi): patched with the
    submitted host/port/username/password/secure-mode.
  - Deployment "mailpit" (namespace glpi): patched with a
    restartedAt annotation to trigger a rollout, same mechanism
    `kubectl rollout restart` uses, so the new Secret takes effect.

No authentication in front of this by design (matches mailpit itself,
which also has none) — it's for a single-developer local-dev cluster
only reachable from this host. Do not expose this beyond that without
adding auth in front of it.
"""
import base64
import datetime
import os

from flask import Flask, request, render_template_string
from kubernetes import client, config

NAMESPACE = "glpi"
SECRET_NAME = "mailpit-relay"
DEPLOYMENT_NAME = "mailpit"

app = Flask(__name__)

config.load_incluster_config()
core_v1 = client.CoreV1Api()
apps_v1 = client.AppsV1Api()

PAGE = """
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Mailpit relay setup</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 3rem auto; color: #222; }
    label { display: block; margin-top: 1rem; font-weight: 600; }
    input, select { width: 100%; padding: 0.5rem; margin-top: 0.25rem; box-sizing: border-box; }
    button { margin-top: 1.5rem; padding: 0.6rem 1.2rem; font-weight: 600; }
    .flash { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 4px; }
    .flash.ok { background: #e6f4ea; color: #1e4620; }
    .flash.err { background: #fce8e6; color: #611a15; }
    .hint { color: #666; font-size: 0.85rem; }
  </style>
</head>
<body>
  <h1>Mailpit relay setup</h1>
  <p class="hint">Sets the SMTP relay mailpit's "Release" (resend) feature delivers through.
     Applies to the <code>mailpit-relay</code> Secret in the <code>glpi</code> namespace and
     restarts the <code>mailpit</code> Deployment to pick it up.</p>
  {% if message %}<div class="flash {{ 'ok' if ok else 'err' }}">{{ message }}</div>{% endif %}
  <form method="post">
    <label for="host">SMTP host</label>
    <input id="host" name="host" value="{{ current.host }}" required>

    <label for="port">SMTP port</label>
    <input id="port" name="port" value="{{ current.port }}" required>

    <label for="username">Username</label>
    <input id="username" name="username" value="{{ current.username }}">

    <label for="password">Password</label>
    <input id="password" name="password" type="password" placeholder="(unchanged if left blank)">

    <label for="secure">Encryption</label>
    <select id="secure" name="secure">
      <option value="starttls" {{ 'selected' if current.secure == 'starttls' else '' }}>STARTTLS</option>
      <option value="tls" {{ 'selected' if current.secure == 'tls' else '' }}>TLS</option>
      <option value="none" {{ 'selected' if current.secure == 'none' else '' }}>None</option>
    </select>

    <label for="allowed_recipients">Allowed recipients (regex, optional)</label>
    <input id="allowed_recipients" name="allowed_recipients" value="{{ current.allowed_recipients }}"
           placeholder=".*@yourcompany\\.com">

    <button type="submit">Save &amp; restart mailpit</button>
  </form>
</body>
</html>
"""


def read_current():
    try:
        secret = core_v1.read_namespaced_secret(SECRET_NAME, NAMESPACE)
        data = {k: base64.b64decode(v).decode() for k, v in (secret.data or {}).items()}
    except client.ApiException:
        data = {}
    return {
        "host": data.get("MP_SMTP_RELAY_HOST", ""),
        "port": data.get("MP_SMTP_RELAY_PORT", "587"),
        "username": data.get("MP_SMTP_RELAY_USERNAME", ""),
        "secure": data.get("MP_SMTP_RELAY_SECURE", "starttls"),
        "allowed_recipients": data.get("MP_SMTP_RELAY_ALLOWED_RECIPIENTS", ""),
    }


@app.route("/", methods=["GET", "POST"])
def index():
    message, ok = None, True
    current = read_current()

    if request.method == "POST":
        host = request.form.get("host", "").strip()
        port = request.form.get("port", "").strip()
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")
        secure = request.form.get("secure", "starttls")
        allowed_recipients = request.form.get("allowed_recipients", "").strip()

        if not host or not port:
            message, ok = "Host and port are required.", False
        else:
            data = {
                "MP_SMTP_RELAY_HOST": host,
                "MP_SMTP_RELAY_PORT": port,
                "MP_SMTP_RELAY_USERNAME": username,
                "MP_SMTP_RELAY_SECURE": secure,
                "MP_SMTP_RELAY_ALLOWED_RECIPIENTS": allowed_recipients,
            }
            if password:
                data["MP_SMTP_RELAY_PASSWORD"] = password

            try:
                secret = core_v1.read_namespaced_secret(SECRET_NAME, NAMESPACE)
                existing = {k: base64.b64decode(v).decode() for k, v in (secret.data or {}).items()}
                existing.update(data)
                encoded = {k: base64.b64encode(v.encode()).decode() for k, v in existing.items()}
                core_v1.patch_namespaced_secret(SECRET_NAME, NAMESPACE, {"data": encoded})

                apps_v1.patch_namespaced_deployment(
                    DEPLOYMENT_NAME,
                    NAMESPACE,
                    {
                        "spec": {
                            "template": {
                                "metadata": {
                                    "annotations": {
                                        "kubectl.kubernetes.io/restartedAt":
                                            datetime.datetime.utcnow().isoformat() + "Z"
                                    }
                                }
                            }
                        }
                    },
                )
                message, ok = "Saved. mailpit is restarting to pick up the new relay settings.", True
                current = read_current()
                current["password"] = ""
            except client.ApiException as e:
                message, ok = f"Kubernetes API error: {e.reason}", False

    return render_template_string(PAGE, message=message, ok=ok, current=current)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 8080)))
