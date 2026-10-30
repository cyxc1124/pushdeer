#!/usr/bin/env python3
"""Exercise the built API with disposable MariaDB/Redis and a local gorush stub."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.parse
import uuid


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("image")
parser.add_argument("--legacy-image", help="Old API image used only to write a synthetic session")
parser.add_argument("--redis-password", default="local-only&?%$:/password",
                    help="REDIS_PASSWORD value, including legacy null markers")
args = parser.parse_args()
host = os.environ.get("DOCKER_HOST") or subprocess.check_output(
    ["docker", "context", "inspect", "--format", "{{.Endpoints.docker.Host}}"], text=True
).strip()
if not host.startswith("unix://"):
    raise SystemExit("This test requires a local Docker Unix socket.")
docker = ["docker", "--host", host]
prefix = "pushdeer-test-" + uuid.uuid4().hex[:8]
network = prefix + "-net"
containers = []
checks = []
password = "local-only&?%$:/password"
redis_password = "" if args.redis_password.lower() in ("null", "(null)") else args.redis_password


def run(*command, check=True):
    result = subprocess.run(docker + list(command), capture_output=True, text=True, timeout=120)
    if check and result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result


def start(name, image, command=(), environment=None, mounts=()):
    full_name = prefix + "-" + name
    command_line = ["run", "-d", "--name", full_name, "--network", network,
                    "--network-alias", name, "--memory", "768m"]
    for key, value in (environment or {}).items():
        command_line += ["-e", key + "=" + value]
    for mount in mounts:
        command_line += ["-v", mount]
    containers.append(full_name)
    run(*command_line, image, *command)
    return full_name


def ready(command):
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        if run(*command, check=False).returncode == 0:
            return
        time.sleep(0.5)
    raise RuntimeError("Timed out waiting for fixture service: " + command[1])


def verify(label, condition):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


with tempfile.TemporaryDirectory(prefix=prefix) as temporary:
    directory = Path(temporary)
    stub = directory / "gorush.py"
    stub.write_text('''import json, threading
from http.server import BaseHTTPRequestHandler, HTTPServer
events = []
class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        events.append({'port': self.server.server_port, 'body': data})
        self.respond({'counts': 1, 'logs': ['success']})
    def do_GET(self):
        self.respond(events)
    def respond(self, body):
        data = json.dumps(body).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def log_message(self, *args): pass
for port in (8088, 8089):
    threading.Thread(target=HTTPServer(('0.0.0.0', port), Handler).serve_forever, daemon=True).start()
threading.Event().wait()
''')
    probe = directory / "probe.php"
    probe.write_text('''<?php
require '/app/api/vendor/autoload.php';
$app = require '/app/api/bootstrap/app.php';
$app->instance('request', Illuminate\\Http\\Request::capture());
$app->make(Illuminate\\Contracts\\Http\\Kernel::class)->bootstrap();
echo json_encode(['environment'=>$app->environment(), 'debug'=>config('app.debug'),
    'sapi'=>PHP_SAPI, 'argv'=>ini_get('register_argc_argv'),
    'upload_progress'=>ini_get('session.upload_progress.enabled')]);
''')
    curl = '''$r=json_decode($argv[1],true); $c=curl_init('http://127.0.0.1'.$r['path']);
curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$r['method'],CURLOPT_TIMEOUT=>10,
CURLOPT_HTTPHEADER=>$r['headers']]); if($r['body']!==null) curl_setopt($c,CURLOPT_POSTFIELDS,$r['body']);
$b=curl_exec($c); if($b===false) {fwrite(STDERR,curl_error($c));exit(1);}
echo json_encode(['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$b]);'''
    try:
        run("network", "create", "--internal", network)
        database = start("db", "mariadb:11.8", environment={
            "MARIADB_ROOT_PASSWORD": "local-root-only", "MARIADB_DATABASE": "pushdeer",
            "MARIADB_USER": "pushdeer", "MARIADB_PASSWORD": password})
        redis_command = ["redis-server"]
        if redis_password != "":
            redis_command += ["--requirepass", redis_password]
        redis = start("redis", "redis:7.4-alpine", redis_command)
        redis_cli = ["exec"]
        if redis_password != "":
            redis_cli += ["-e", "REDISCLI_AUTH=" + redis_password]
        redis_cli += [redis, "redis-cli"]
        mock = start("gorush", "python:3.11-slim", ("python", "/stub.py"), mounts=[str(stub) + ":/stub.py:ro"])
        ready(["exec", database, "healthcheck.sh", "--connect", "--innodb_initialized"])
        ready([*redis_cli, "ping"])
        if args.legacy_image:
            run("run", "--rm", "--network", network, "--entrypoint", "php",
                "-e", "DB_HOST=db", "-e", "DB_DATABASE=pushdeer", "-e", "DB_USERNAME=pushdeer", "-e", "DB_PASSWORD=" + password,
                args.legacy_image, "artisan", "migrate", "--force", "--no-interaction")
        api = start("api", args.image, environment={
            "APP_ENV": "production", "APP_DEBUG": "false", "APP_URL": "http://localhost",
            "APP_KEY": "base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
            "DB_HOST": "db", "DB_DATABASE": "pushdeer", "DB_USERNAME": "pushdeer", "DB_PASSWORD": password,
            "REDIS_HOST": "redis", "REDIS_PASSWORD": args.redis_password, "CACHE_DRIVER": "file",
            "GO_PUSH_ADDRESS": "gorush", "GO_PUSH_IOS_PORT": "8088", "GO_PUSH_IOS_CLIP_PORT": "8089",
            "GO_PUSH_IOS_TOPIC": "fixture.ios", "GO_PUSH_IOS_CLIP_TOPIC": "fixture.ios.Clip",
            "MAX_PUSH_EVERY_USER_PER_MINUTE": "600", "MAX_PUSH_KEY_PER_TIME": "100", "MAX_PUSH_EXISTS_DAYS": "7",
        }, mounts=[str(probe) + ":/app/api/public/__validation.php:ro"])
        ready(["exec", api, "php", "-r", "$s=@fsockopen('127.0.0.1',80);exit($s?0:1);"])
        migration = run("exec", api, "php", "artisan", "migrate", "--force", "--no-interaction").stdout
        if args.legacy_image:
            verify("Laravel 8 schema needs no new migrations", "Nothing to migrate" in migration or "No migrations to run" in migration)
        seed = '''require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach(['one','two'] as $name){$u=App\\Models\\PushDeerUser::create(['name'=>$name,'level'=>1]);
$u->simple_token='SP-'.$name;$u->save();}'''
        run("exec", api, "php", "-r", seed)

        def request(path, data=None, method="POST", form=False, headers=()):
            body = None if data is None else (urllib.parse.urlencode(data) if form else json.dumps(data))
            content_type = "application/x-www-form-urlencoded" if form else "application/json"
            packet = {"path": path, "method": method, "body": body,
                      "headers": ["Content-Type: " + content_type, "Accept: application/json", *headers]}
            response = json.loads(run("exec", api, "php", "-r", curl, json.dumps(packet)).stdout)
            verify("HTTP " + method + " " + path.split('?')[0], response["status"] == 200)
            return json.loads(response["body"])

        one = request("/login/simple_token", {"stoken": "SP-one"})["content"]["token"]
        two = request("/login/simple_token", {"stoken": "SP-two"})["content"]["token"]
        verify("Native Redis token reads user one", request("/user/info", {"token": one})["content"]["name"] == "one")
        verify("Native Redis token reads user two", request("/user/info", {"token": two})["content"]["name"] == "two")
        verify("Token isolation after alternating requests", request("/user/info", {"token": one})["content"]["name"] == "one")
        verify("Array token rejected", request("/user/info", {"token": ["bad"]})["code"] == 80403)
        if args.legacy_image:
            save_path = "tcp://redis:6379"
            if redis_password != "":
                save_path += "?auth=" + urllib.parse.quote(redis_password, safe="")
            script = "session_id('legacyfixture123456');session_start();$_SESSION=['uid'=>1,'name'=>'one','level'=>1];session_write_close();"
            legacy = run("run", "--rm", "--network", network, "--entrypoint", "php", args.legacy_image,
                "-d", "session.save_handler=redis", "-d", "session.save_path=" + json.dumps(save_path), "-r", script)
            if "PHP Warning" in legacy.stderr:
                raise RuntimeError(legacy.stderr)
            verify("PHP 8.0 Redis session survives PHP 8.4 upgrade", request("/user/info", {"token": "legacyfixture123456"}).get("content", {}).get("name") == "one")
            ttl = int(run(*redis_cli, "ttl", "PHPREDIS_SESSION:legacyfixture123456").stdout)
            verify("Native session TTL remains three days", ttl > 259000)
        for name, kind, clip in [("ios", "ios", 0), ("clip", "ios", 1), ("android", "android", 0)]:
            verify("Register " + name, request("/device/reg", {"token": one, "name": name, "type": kind, "is_clip": clip, "device_id": name + "-fixture"})["code"] == 0)
        key = request("/key/gen", {"token": one})["content"]["keys"][0]
        data = {"pushkey": key["key"], "text": "local-fixture", "desp": "**fixture**", "type": "markdown"}
        verify("JSON push fans out to all devices", len(request("/message/push", data)["content"]["result"]) == 3)
        verify("Form push", request("/message/push", data, form=True)["code"] == 0)
        verify("GET push", request("/message/push?" + urllib.parse.urlencode(data), method="GET")["code"] == 0)
        events = json.loads(run("exec", mock, "python", "-c", "import urllib.request;print(urllib.request.urlopen('http://127.0.0.1:8088/events').read().decode())").stdout)
        verify("Nine gorush notifications captured", len(events) == 9)
        verify("iOS production payload", events[0]["body"]["notifications"][0]["production"] is True)
        verify("App clip topic and port", events[1]["port"] == 8089 and events[1]["body"]["notifications"][0]["topic"] == "fixture.ios.Clip")
        verify("Android platform", events[2]["body"]["notifications"][0]["platform"] == 4)
        verify("Other user cannot list messages", request("/message/list", {"token": two})["content"]["messages"] == [])
        verify("Message history preserved", len(request("/message/list", {"token": one})["content"]["messages"]) == 3)
        verify("Cross-user key delete rejected", request("/key/remove", {"token": two, "id": key["id"]})["code"] == 80501)
        verify("Debug login remains disabled", request("/login/fake?--env=local", method="GET")["error"] == "Debug only")
        state = request("/__validation.php?--env=local", method="GET")
        verify("FPM environment ignores query argv", state["environment"] == "production" and state["sapi"] == "fpm-fcgi")
        verify("FPM mitigations enabled", not state["debug"] and state["argv"] == "" and state["upload_progress"] == "")
        verify("Crafted Cache-Control request handled", request("/login/fake", method="GET", headers=["Cache-Control: a" + " " * 25 + ",b"])["error"] == "Debug only")
        verify("Hourly cleanup registered", "app:cleanpush" in run("exec", api, "php", "artisan", "schedule:list", "--no-ansi").stdout)
        verify("Production excludes Tinker/PsySH", run("exec", api, "php", "-r", "require 'vendor/autoload.php';exit(class_exists('Psy\\\\Shell')?1:0);").returncode == 0)
        print(json.dumps({"image": args.image, "checks_passed": len(checks), "checks": checks}, ensure_ascii=False, indent=2))
    except Exception:
        for name in containers:
            logs = run("logs", "--tail", "25", name, check=False)
            print(name, logs.stdout, logs.stderr)
        raise
    finally:
        for name in reversed(containers):
            run("rm", "-f", "-v", name, check=False)
        run("network", "rm", network, check=False)
