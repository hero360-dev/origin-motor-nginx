#!/usr/bin/env python3
"""Edge mini-API: exposes streaming metrics as JSON on port 8091"""
import json, re, time, subprocess, sys, os
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler

LOG_FILE  = "/var/log/nginx/access.log"
CACHE_DIR = "/var/cache/nginx/hls"
PORT      = 8091
HOSTNAME  = sys.argv[1] if len(sys.argv) > 1 else "edge"

# Force a sane PATH for subprocess calls
os.environ['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'

LOG_RE = re.compile(
    r'(\S+) - \S+ \[(\d+/\w+/\d+:\d+:\d+:\d+ [+-]\d{4})\] '
    r'"(?:GET|POST|HEAD) (\S+) \S+" (\d+) (\d+)'
)

def run(cmd, timeout=5):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True,
                           timeout=timeout, env=os.environ)
        return r.stdout.strip()
    except Exception:
        return ""

def parse_log():
    now = time.time()
    viewers = set()
    bytes_out = 0
    total = 0
    try:
        with open(LOG_FILE, 'rb') as f:
            # Efficient tail: read last 512KB
            f.seek(0, 2)
            size = f.tell()
            f.seek(max(0, size - 512 * 1024))
            content = f.read().decode('utf-8', errors='replace')
    except Exception:
        content = ""
    for line in content.splitlines():
        m = LOG_RE.match(line)
        if not m:
            continue
        ip, ts_str, uri, status, bytes_sent = m.groups()
        try:
            dt = datetime.strptime(ts_str, "%d/%b/%Y:%H:%M:%S %z")
            age = now - dt.timestamp()
        except Exception:
            continue
        if age > 30:
            continue
        bytes_out += int(bytes_sent)
        total += 1
        if age <= 12 and ('.m3u8' in uri or 'playlist' in uri):
            viewers.add(ip)
    bw_mbps = round((bytes_out * 8) / (30 * 1_000_000), 3)
    return {"viewers": len(viewers), "bw_out_mbps": bw_mbps,
            "bytes_out_30s": bytes_out, "requests_30s": total}

def cache_info():
    # Read cache size from /proc (no subprocess)
    size = "N/A"
    files = 0
    try:
        total = 0
        for dirpath, dirnames, filenames in os.walk(CACHE_DIR):
            for fname in filenames:
                try:
                    total += os.path.getsize(os.path.join(dirpath, fname))
                    files += 1
                except:
                    pass
        # Format size
        if total < 1024:
            size = f"{total}B"
        elif total < 1024**2:
            size = f"{total//1024}K"
        elif total < 1024**3:
            size = f"{total//1024//1024}M"
        else:
            size = f"{total//1024//1024//1024}G"
    except Exception:
        pass
    return {"cache_size": size, "cache_files": files}

def nginx_status():
    # Check via /proc instead of pgrep
    try:
        for pid in os.listdir('/proc'):
            if not pid.isdigit():
                continue
            try:
                with open(f'/proc/{pid}/comm', 'r') as f:
                    if f.read().strip() == 'nginx':
                        return "active"
            except:
                pass
    except:
        pass
    return "inactive"

class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def do_GET(self):
        if self.path in ('/metrics', '/'):
            data = {
                "hostname":     HOSTNAME,
                "timestamp":    int(time.time()),
                "nginx_status": nginx_status(),
                **parse_log(),
                **cache_info()
            }
            body = json.dumps(data, indent=2).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()

if __name__ == '__main__':
    server = HTTPServer(('0.0.0.0', PORT), Handler)
    print(f"[ng-edge-api] {HOSTNAME} listening on :{PORT}", flush=True)
    server.serve_forever()
