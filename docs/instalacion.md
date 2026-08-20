# Guía de Instalación

## Requisitos previos

- Ubuntu 22.04 (origin) / Ubuntu 18.04+ (edges)
- Root access
- FFmpeg instalado
- PHP 8.x + Apache2
- Python 3.8+
- autossh (para túnel MySQL)

## 1. Origin — nginx-rtmp

```bash
apt-get install -y nginx libnginx-mod-rtmp supervisor

# Crear directorio HLS
mkdir -p /var/lib/nginx-hls
chown www-data:www-data /var/lib/nginx-hls

# Copiar config
cp origin/nginx/nginx.conf /etc/nginx/nginx.conf
nginx -t && systemctl restart nginx
```

## 2. Origin — Supervisord

```bash
# Crear estructura de directorios de templates
mkdir -p /etc/supervisor/library/nginx
mkdir -p /etc/supervisor/library/wowza

# Agregar al supervisord.conf principal:
# [include]
# files = /etc/supervisor/conf.d/*.conf
```

## 3. Origin — Panel PHP

```bash
apt-get install -y apache2 php php-curl libapache2-mod-php

# Copiar archivos del panel
cp origin/panel/*.php /var/www/html/

# Crear archivo de secrets (NO incluir en git)
cat > /etc/casino-secrets.env << 'SECRETS'
MYSQL_TUNNEL_HOST=127.0.0.1
MYSQL_TUNNEL_PORT=3307
MYSQL_TUNNEL_USER=USUARIO
MYSQL_TUNNEL_PASS=CONTRASEÑA
MYSQL_TUNNEL_DB=BASEDEDATOS
SECRETS
chmod 640 /etc/casino-secrets.env
chown root:www-data /etc/casino-secrets.env

systemctl restart apache2
```

## 4. Origin — Túnel SSH a MySQL remoto

```bash
apt-get install -y autossh

# Generar clave SSH (si no existe)
ssh-keygen -t ed25519 -f /root/.ssh/id_mysql_tunnel -N ""

# Copiar clave al servidor remoto
ssh-copy-id -i /root/.ssh/id_mysql_tunnel.pub usuario@servidor-remoto

# Crear servicio systemd
cat > /etc/systemd/system/autossh-mysql-tunnel.service << 'SERVICE'
[Unit]
Description=AutoSSH tunnel MySQL
After=network.target

[Service]
Environment="AUTOSSH_GATETIME=0"
ExecStart=/usr/bin/autossh -M 0 -N \
  -o "ServerAliveInterval=30" \
  -o "ServerAliveCountMax=3" \
  -i /root/.ssh/id_mysql_tunnel \
  -L 3307:127.0.0.1:3306 \
  usuario@servidor-remoto
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable autossh-mysql-tunnel
systemctl start autossh-mysql-tunnel
```

## 5. Origin — Agregar canal nuevo

```bash
# Crear script FFmpeg
cat > /usr/local/bin/ng-fxNNNN.sh << 'SCRIPT'
#!/bin/bash
exec /usr/bin/ffmpeg \
  -user_agent "Mozilla/5.0" \
  -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 2 \
  -i https://FUENTE_URL/stream.ts \
  -c:v copy \
  -c:a aac -b:a 128k -ar 48000 -ac 2 \
  -f flv rtmp://127.0.0.1:1936/live/fxNNNN
SCRIPT
chmod +x /usr/local/bin/ng-fxNNNN.sh

# Crear conf supervisord
cat > /etc/supervisor/conf.d/fxNNNN.conf << 'CONF'
[program:fxNNNN]
command=/usr/local/bin/ng-fxNNNN.sh
autostart=true
autorestart=true
startsecs=20
startretries=999
exitcodes=0
stopsignal=INT
user=root
stdout_logfile=/var/log/supervisor/fxNNNN.out.log
stderr_logfile=/var/log/supervisor/fxNNNN.err.log
stdout_logfile_maxbytes=5MB
stderr_logfile_maxbytes=5MB
stdout_logfile_backups=3
stderr_logfile_backups=3
environment=HOME="/root"
CONF

supervisorctl reread && supervisorctl update
supervisorctl start fxNNNN
```

## 6. Edges — nginx proxy cache

```bash
# En cada edge server
apt-get install -y nginx

mkdir -p /var/cache/nginx/hls
chown www-data:www-data /var/cache/nginx/hls

# Editar nginx.conf reemplazando EDGE_LABEL con edge1/edge2/edge3
cp edges/nginx/nginx.conf /etc/nginx/nginx.conf
# Editar: add_header X-Served-By EDGE_LABEL;
nano /etc/nginx/nginx.conf

nginx -t && systemctl enable nginx && systemctl restart nginx
```

## 7. Edges — Mini API de métricas

```bash
# Copiar script
cp edges/api/ng-edge-api.py /usr/local/bin/ng-edge-api.py
chmod +x /usr/local/bin/ng-edge-api.py

# Copiar servicio (editar EDGE_LABEL)
cp systemd/ng-edge-api.service /etc/systemd/system/ng-edge-api.service
# Editar ExecStart para poner el nombre del edge: edge1/edge2/edge3

systemctl daemon-reload
systemctl enable ng-edge-api
systemctl start ng-edge-api

# Verificar
curl http://localhost:8091/metrics
```

## 8. Origin — SSH sin contraseña a edges

```bash
# En origin, generar clave para edges
ssh-keygen -t ed25519 -f /root/.ssh/id_edges -N ""

# Copiar a cada edge (requiere contraseña de root del edge)
ssh-copy-id -i /root/.ssh/id_edges.pub root@IP_EDGE

# Agregar alias en /root/.ssh/config
cat >> /root/.ssh/config << 'CONFIG'
Host edge1
    HostName 186.233.186.55
    User root
    IdentityFile /root/.ssh/id_edges
    StrictHostKeyChecking no
    ConnectTimeout 5
CONFIG

# Verificar
ssh edge1 "echo OK"
```
