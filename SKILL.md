# origin-motor-nginx

## Descripción del proyecto
Sistema de streaming independiente basado en **nginx-rtmp** que reemplaza/respalda Wowza Streaming Engine. Gestiona canales de TV (fx0085–fx0898+) mediante FFmpeg + supervisord en el servidor origin, con distribución HLS a 3 edge servers que actúan como proxy cache.

## Arquitectura general

```
Fuente (HTTPS .ts) → FFmpeg (supervisord) → nginx-rtmp:1936 → HLS /var/lib/nginx-hls
                                                                        ↓
                                                         nginx HTTP:8090/hls/fxNNNN/
                                                                        ↓
                                                    Edge1  Edge2  Edge3  (proxy cache)
                                                    :80    :80    :80
                                                    ↓
                                               Clientes HLS (Wowza-style URL)
```

## Servidores

| Rol    | IP              | OS           | Notas                          |
|--------|-----------------|--------------|-------------------------------|
| Origin | 23.137.84.97    | Ubuntu 22.04 | nginx-rtmp, supervisord, panel |
| Edge1  | 186.233.186.55  | Ubuntu 22.04 | nginx proxy cache              |
| Edge2  | 186.233.186.58  | Ubuntu 18.04 | nginx proxy cache              |
| Edge3  | 198.147.24.146  | Ubuntu 18.04 | nginx proxy cache              |

## Rutas clave en Origin (23.137.84.97)

| Ruta | Descripción |
|------|-------------|
| `/etc/nginx/nginx.conf` | Config nginx-rtmp (RTMP:1936, HLS HTTP:8090) |
| `/var/lib/nginx-hls/` | Segmentos HLS generados (fxNNNN/index.m3u8) |
| `/etc/supervisor/conf.d/` | Confs supervisord activas (una por canal) |
| `/etc/supervisor/library/nginx/` | Templates de conf para modo nginx |
| `/etc/supervisor/library/wowza/` | Templates de conf para modo wowza |
| `/usr/local/bin/ng-fxNNNN.sh` | Scripts FFmpeg por canal (ingesta nginx) |
| `/var/www/html/ng_manager.php` | Panel principal de canales |
| `/var/www/html/ng_dashboard.php` | Dashboard con gráficas Chart.js |
| `/var/www/html/ng_edges.php` | Monitor de edge servers |
| `/var/www/html/ng_help.php` | Ayuda y guía de colores |
| `/var/www/html/ng_settings.php` | Configuración del sistema |
| `/var/www/html/ng_preview.php` | Preview HLS via HLS.js |
| `/root/.ssh/config` | Alias SSH a edges (edge1, edge2, edge3) |
| `/root/.ssh/id_edges` | Clave privada para acceso sin contraseña a edges |
| `/etc/casino-secrets.env` | Credenciales MySQL (owner root:www-data 640) |

## Rutas clave en cada Edge

| Ruta | Descripción |
|------|-------------|
| `/etc/nginx/nginx.conf` | Config nginx proxy cache HLS |
| `/var/cache/nginx/hls/` | Directorio cache (max 2GB, 10 min inactive) |
| `/usr/local/bin/ng-edge-api.py` | Mini API métricas (Python, puerto 8091) |
| `/etc/systemd/system/ng-edge-api.service` | Servicio systemd para la mini API |

## Servicios en Origin

```bash
# Verificar estado
systemctl status nginx
supervisorctl status                    # todos los canales
supervisorctl status fx0093             # canal específico

# Reiniciar canal
supervisorctl restart fx0093

# Iniciar / detener todos
supervisorctl start all
supervisorctl stop all
```

## Servicios en Edges

```bash
# En cualquier edge
systemctl status nginx
systemctl status ng-edge-api

# API de métricas (desde origin)
curl http://186.233.186.55:8091/metrics   # edge1
curl http://186.233.186.58:8091/metrics   # edge2
curl http://198.147.24.146:8091/metrics   # edge3

# Health check nginx
curl http://186.233.186.55/health
```

## SSH a Edges desde Origin

```bash
ssh edge1    # → root@186.233.186.55
ssh edge2    # → root@186.233.186.58
ssh edge3    # → root@198.147.24.146
```

## Panel de administración

- URL: `http://23.137.84.97/ng_manager.php`
- Login: `ngadmin` / contraseña definida en sesión PHP
- Páginas: Dashboard, Canales, Edges, Ayuda, Configuración

## URLs de stream para clientes

**Origin directo (HLS):**
```
http://23.137.84.97:8090/hls/fx0093/index.m3u8
```

**Edge (estilo Wowza):**
```
http://186.233.186.55/01hbx0093c6WI3k/myStream/playlist.m3u8
http://186.233.186.58/01hbx0093c6WI3k/myStream/playlist.m3u8
http://198.147.24.146/01hbx0093c6WI3k/myStream/playlist.m3u8
```

## Formato de supervisord conf (canal nginx)

```ini
[program:fxNNNN]
command=/usr/bin/ffmpeg -user_agent "Mozilla/5.0" \
  -reconnect 1 -reconnect_at_eof 1 -reconnect_streamed 1 -reconnect_delay_max 2 \
  -i https://SOURCE_URL/stream.ts \
  -c:v copy -c:a aac -b:a 128k -ar 48000 -ac 2 \
  -f flv rtmp://127.0.0.1:1936/live/fxNNNN
autostart=true
autorestart=true
startsecs=20
startretries=999
```

## MySQL Tunnel (SSH)

El panel carga nombres de canales desde MySQL remoto vía túnel SSH.
- Túnel local: `localhost:3307` → servidor remoto:3306
- Config en: `/etc/casino-secrets.env` (root:www-data, 640)
- Servicio: `autossh-mysql-tunnel` (systemd)

## Switch Wowza ↔ nginx

El panel puede cambiar un canal entre Wowza y nginx copiando el conf template apropiado:
- Templates nginx: `/etc/supervisor/library/nginx/`
- Templates wowza: `/etc/supervisor/library/wowza/`
- El conf activo siempre está en: `/etc/supervisor/conf.d/fxNNNN.conf`

## Comandos útiles de diagnóstico

```bash
# Ver HLS segments activos de un canal
ls -la /var/lib/nginx-hls/fx0093/

# Ver log de FFmpeg de un canal
tail -f /var/log/supervisor/fx0093.err.log

# Cuántos canales activos tiene nginx
ls /var/lib/nginx-hls/ | wc -l

# Ver estadísticas RTMP
curl http://localhost:8090/stat

# Viewers en tiempo real (origin)
tail -f /var/log/nginx/access.log | grep '.m3u8'
```

## Sistema de Token de Seguridad (nginx SecureToken)

### Cómo funciona

Equivalente al Wowza SecureToken v2. Protege las URLs `.m3u8` de los edges con IP binding + tiempo de expiración.

**Fórmula del hash** (nginx `secure_link` module):
```
string = "{expires}{uri}{remote_addr} {NGINX_TOKEN_SECRET}"
hash   = MD5(string) → base64 → URL-safe (+ → -, / → _, sin =)
```

**URL con token:**
```
http://EDGE_IP/01hbxNNNNc6WI3k/myStream/playlist.m3u8?ngtk=HASH&ngte=EXPIRES
```

**Sin token → 403 | Token expirado → 410 | Token válido → 200**

### Archivos clave del token

| Archivo | Descripción |
|---|---|
| `/etc/casino-secrets.env` | Contiene `NGINX_TOKEN_SECRET=...` (48 chars hex) |
| `/var/www/html/ng_token_generate.php` | Genera token para los 3 edges vía AJAX |
| `/var/www/html/ng_token_info.php` | Devuelve secreto enmascarado para Settings |
| `/var/www/html/ng_token_regen.php` | Regenera secreto y sincroniza a edges vía SSH |
| `/etc/nginx/nginx.conf` (edges) | Tiene `secure_link` en location del `.m3u8` |

### Flujo desde el panel

1. Usuario abre sub-fila de un canal (botón 🌐)
2. Presiona **🔑 Play con Token** en un edge
3. JS llama `https://api.ipify.org` → obtiene IPv4 pública del cliente
4. JS llama `ng_token_generate.php?fx=fxNNNN&ip=CLIENT_IP`
5. PHP lee secreto del env, genera hash MD5, devuelve URL con token para los 3 edges
6. HLS.js reproduce la URL del edge → edge valida con `secure_link` → 200 ✓

### Problemas comunes y soluciones

**Error 500 al generar token:**
- Causa: `parse_ini_file()` falla si `/etc/casino-secrets.env` tiene paréntesis `()` en comentarios
- Solución: El código usa parser manual línea por línea (ya corregido)
- Verificar: `php -r "foreach(file('/etc/casino-secrets.env', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as \$l){ if(\$l[0]==='#') continue; if(strpos(\$l,'NGINX_TOKEN_SECRET=')===0){echo 'OK: '.strlen(substr(\$l,19)).' chars';break;} }"`

**Token da 403 aunque parece correcto:**
- Causa A: IP del cliente que ipify devuelve ≠ IP que el edge ve (NAT, CGNAT, IPv6)
  - Solución: Cambiar `api64.ipify.org` → `api.ipify.org` (IPv4 forzado)
- Causa B: El secreto en el edge difiere del de origin (desincronizado)
  - Solución: Usar "Regenerar secreto" en Configuración → re-sincroniza los 3 edges

**Verificar token manualmente desde origin:**
```bash
SECRET=$(grep "NGINX_TOKEN_SECRET" /etc/casino-secrets.env | cut -d'=' -f2)
EXPIRES=$(( $(date +%s) + 7200 ))
URI="/01hbxNNNNc6WI3k/myStream/playlist.m3u8"
IP="IP_DEL_CLIENTE"
HASH=$(echo -n "${EXPIRES}${URI}${IP} ${SECRET}" | openssl dgst -md5 -binary | openssl base64 | tr '+/' '-_' | tr -d '=')
curl -I "http://186.233.186.55${URI}?ngtk=${HASH}&ngte=${EXPIRES}"
# Esperado: HTTP/1.1 200 OK
```

**Regenerar secreto manualmente (sin panel):**
```bash
NEW=$(openssl rand -hex 24)
sed -i "s/^NGINX_TOKEN_SECRET=.*/NGINX_TOKEN_SECRET=${NEW}/" /etc/casino-secrets.env
for edge in edge1 edge2 edge3; do
  ssh $edge "sed -i 's|secure_link_md5 \"[^\"]*\"|secure_link_md5 \"\$secure_link_expires\$uri\$remote_addr ${NEW}\"|g' /etc/nginx/nginx.conf && nginx -s reload"
done
```

### nginx secure_link en edges (config actual)

```nginx
location ~ ^/01hbx([0-9]+)c6WI3k/myStream/playlist\.m3u8$ {
    set $ch $1;
    secure_link $arg_ngtk,$arg_ngte;
    secure_link_md5 "$secure_link_expires$uri$remote_addr NGINX_TOKEN_SECRET";
    if ($secure_link = "")  { return 403; }  # token invalido/ausente
    if ($secure_link = "0") { return 410; }  # token expirado
    proxy_pass http://23.137.84.97:8090/hls/fx${ch}/index.m3u8;
    # ... resto del proxy config
}
```

**Nota:** `nginx-extras` (Ubuntu 18.04) o `nginx` oficial de nginx.org incluyen `secure_link`. El paquete `nginx-core` de Ubuntu NO lo incluye.

## HLS Cache — Funcionamiento y Storage

### Flujo del caché

```
FFmpeg → /var/lib/nginx-hls/fxNNNN/ (Origin, disco)
              ↓  HTTP pull (nginx proxy)
         /var/cache/nginx/hls/ (Edge, proxy_cache)
              ↓
         Viewer (browser / STB)
```

El **origin NO tiene proxy_cache** — genera los `.ts` en disco con FFmpeg y los sirve directamente.
Los **edges SÍ tienen proxy_cache** — descargan del origin y los guardan localmente para servirlos rápido.

### Origin: almacenamiento dinámico en /var/lib/nginx-hls/

```nginx
hls_fragment 3;          # cada segmento dura 3 segundos
hls_playlist_length 12;  # playlist mantiene los últimos 12 segmentos = 36s de buffer
hls_nested on;           # un subdirectorio por canal: /var/lib/nginx-hls/fxNNNN/
add_header Cache-Control no-cache;
```

FFmpeg escribe los `.ts` y el `index.m3u8`. Cuando un segmento sale de la playlist, **FFmpeg lo elimina automáticamente**. El directorio de cada canal activo ocupa aprox. 15–30 MB (12 segs × ~2 MB/seg para 3Mbps). El total en origin es estable y pequeño independientemente de cuántos canales estén configurados — solo los que están en estado RUNNING ocupan espacio.

**Canales RUNNING en nginx generan ~22 MB en total** (referencia actual con pocos canales activos).

### Edges: proxy_cache con límite automático

Config en cada edge (`/etc/nginx/nginx.conf`):

```nginx
proxy_cache_path /var/cache/nginx/hls
    levels=1:2         # estructura árbol: ab/cdef/hashmd5 (evita miles de archivos en un dir)
    keys_zone=hls:20m  # 20 MB de RAM para índice de claves
    max_size=2g        # límite máximo en disco: 2 GB por edge
    inactive=10m       # elimina automáticamente si nadie pide el archivo en 10 min
    use_temp_path=off;

# Tiempos de caché:
proxy_cache_valid 200 206 3s;   # .m3u8 (playlist): refresca cada 3s
proxy_cache_valid 200 206 1h;   # .ts (segmentos): guarda 1 hora
```

**Limpieza automática:** nginx elimina entradas cuando:
1. El cache llega a `max_size=2g` → elimina entradas LRU (menos usadas)
2. Un archivo no es pedido por más de `inactive=10m`

**No hay riesgo de llenado descontrolado** — nginx lo gestiona automáticamente.

### Estado de storage actual

| Servidor | Directorio | Uso | Archivos | Límite |
|---|---|---|---|---|
| Origin | `/var/lib/nginx-hls/` | ~22 MB | ~50 .ts, 4 .m3u8 | Sin límite fijo (auto-limpieza FFmpeg) |
| Edge 1 | `/var/cache/nginx/hls/` | ~16 MB | 10 | 2 GB |
| Edge 2 | `/var/cache/nginx/hls/` | ~50 MB | 36 | 2 GB |
| Edge 3 | `/var/cache/nginx/hls/` | ~276 KB | 0 | 2 GB |

### Edge API (ng-edge-api.py) y el caché

La mini API en puerto 8091 reporta el tamaño del caché del edge con `cache_info()`. Esta función usa `os.walk()` sobre `/var/cache/nginx/hls/` — si hay miles de archivos puede tardar y **bloquear el servidor HTTP** (es single-threaded).

**Solución implementada:** timeout interno de 2 segundos. Si se excede, reporta `"??"` en lugar de congelar.

**Síntoma de edge API congelada:** `curl http://EDGE_IP:8091/metrics` hace timeout desde origin.
**Solución rápida:**
```bash
ssh edge1 "systemctl restart ng-edge-api"
ssh edge2 "systemctl restart ng-edge-api"
ssh edge3 "systemctl restart ng-edge-api"
```

**Diagnóstico de caché en edges:**
```bash
# Tamaño del caché
ssh edge1 "du -sh /var/cache/nginx/hls"

# Limpiar caché manualmente (sin detener nginx)
ssh edge1 "find /var/cache/nginx/hls -type f -delete && echo OK"

# Ver qué tiene la API ahora
curl -s http://186.233.186.55:8091/metrics | python3 -m json.tool
```

### Consideraciones de storage a largo plazo

- **Origin**: Estable. Si se tienen 200 canales RUNNING simultáneos en nginx → ~200 × 25 MB = ~5 GB máximo. Con la estrategia actual (mezcla Wowza + nginx) el riesgo es bajo.
- **Edges**: Controlado por `max_size=2g`. Con 600+ canales y mucho tráfico, los 2 GB se llenarían, pero nginx purga los LRU automáticamente sin intervención manual.
- **Monitorear**: `du -sh /var/lib/nginx-hls/` en origin y `du -sh /var/cache/nginx/hls/` en edges periódicamente.

## Canales Edge-VOD (streamvod en edge2)

### Descripción
28 canales de películas/series VOD que corren en el servidor **edge2** (`186.233.186.58`) vía supervisord remoto. Cada canal reproduce un playlist rotativo de contenido desde la API de `vodsisweb.dalexx.com`.

### Canales disponibles
| Rango | Tipo | Ejemplos |
|---|---|---|
| fx0065–fx0082 | Inglés (18 canales) | movies65, terror71, disney68, scifi78... |
| fx0690–fx0699 | Latino (10 canales) | latmovies65, latterror71, latcomedia73... |

### Arquitectura
```
edge2 (186.233.186.58)
  supervisord → accion69.sh         (modo Wowza)
              → accion69_nginx.sh   (modo nginx)
                    ↓ llama a
              accion69.php / accion69_nginx.php
                    ↓ consulta API
              vodsisweb.dalexx.com → URL firmada película
                    ↓
              FFmpeg -re -i URL_PELICULA
                    ↓
              Wowza: rtmp://prov:prov001@abuelo.mine.nu:1935/fx0069/myStream
              nginx: rtmp://prov:prov001@23.137.84.97:1936/live/fx0069
```

### Confs marcadores en origin
Cada canal tiene un conf marcador en `/etc/supervisor/conf.d/fxNNNN.conf` con metadatos:
```ini
# TYPE=edge_vod
# EDGE_HOST=186.233.186.58
# EDGE_USER=root
# EDGE_SSH_KEY=/var/www/.ssh/id_edges
# EDGE_SCRIPT_WOWZA=/home/streamvod/accion69.sh
# EDGE_SCRIPT_NGINX=/home/streamvod/accion69_nginx.sh
# SYSTEM=wowza
[program:fx0069]
command=/bin/true
autostart=false
autorestart=false
```
`SYSTEM=` se actualiza al hacer switch desde el panel. La llave SSH debe estar en `/var/www/.ssh/id_edges` (accesible por www-data).

### Scripts en edge2
Cada canal tiene 4 archivos en `/home/streamvod/`:
- `accion69.php` — versión Wowza (original)
- `accion69.sh` — wrapper bash Wowza
- `accion69_nginx.php` — versión nginx (generada)
- `accion69_nginx.sh` — wrapper bash nginx

Los scripts nginx están también en el repo bajo `edges/streamvod/`.

### Switch Wowza⇔nginx desde el panel
El toggle Wowza/nginx en el panel hace via SSH a edge2:
1. `sudo sed -i 's|wowza.sh|nginx.sh|' /etc/supervisor/conf.d/fx0069.conf`
2. `sudo supervisorctl restart fx0069`
3. Actualiza `# SYSTEM=` en el conf marcador de origin
4. Limpia HLS en origin si se vuelve a Wowza
5. `update_iddealer($ch, 1/0)` en MySQL

### SSH key setup
La llave para edge2 debe estar en dos lugares:
- `/root/.ssh/id_edges` — para comandos manuales desde root
- `/var/www/.ssh/id_edges` — para que www-data (Apache/PHP) pueda conectar

```bash
# Si se resetea la llave (nueva):
ssh-copy-id -i /root/.ssh/id_edges.pub root@186.233.186.58
cp /root/.ssh/id_edges /var/www/.ssh/id_edges
chown www-data:www-data /var/www/.ssh/id_edges
chmod 600 /var/www/.ssh/id_edges
# Cambiar owner de los confs marcadores para que www-data pueda escribir SYSTEM=
chown www-data:www-data /etc/supervisor/conf.d/fx006[5-9].conf
chown www-data:www-data /etc/supervisor/conf.d/fx07*.conf
chown www-data:www-data /etc/supervisor/conf.d/fx008[0-2].conf
chown www-data:www-data /etc/supervisor/conf.d/fx069*.conf
```

### Estado en supervisord (desde origin)
```bash
# Ver estado real de todos los edge-vod en edge2
ssh -i /var/www/.ssh/id_edges root@186.233.186.58 \
  "sudo supervisorctl status" | grep -E 'fx006[5-9]|fx07|fx008|fx069'

# Ver script activo de un canal
ssh -i /var/www/.ssh/id_edges root@186.233.186.58 \
  "grep command /etc/supervisor/conf.d/fx0069.conf"
```

### Regenerar scripts nginx (si se corrompen)
Los scripts en `/tmp/nginx_php_fixed/` (origin) y en `edges/streamvod/` (repo) son la versión correcta.
Si se necesita regenerar:
```bash
# En origin — genera archivos correctos sin problemas de escaping
python3 /root/generate_nginx_scripts.py  # ver edges/streamvod/

# Copiar a edge2
scp -i /var/www/.ssh/id_edges /tmp/nginx_php_fixed/*.php \
  root@186.233.186.58:/home/streamvod/
```

**IMPORTANTE**: NO generar los _nginx.php desde dentro de SSH con bash heredocs de doble comilla — bash expande las variables `$idopt`, `$stream` a vacío. Siempre generar en origin con Python y copiar con SCP.

### Bugs conocidos corregidos
1. **Scripts con `$stream = ''`**: ocurre si se generan desde Python dentro de un SSH heredoc con dobles comillas (bash expande `$var`). Solución: generar en origin con Python puro y copiar con SCP.
2. **SSH key inaccesible por www-data**: llave en `/root/.ssh/` no es leída por Apache. Copiar siempre a `/var/www/.ssh/`.
3. **Confs marcadores owned por root**: www-data no puede actualizar `SYSTEM=`. Solución: `chown www-data`.
