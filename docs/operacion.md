# Guía de Operación

## Estado general del sistema

```bash
# En origin
systemctl status nginx           # nginx-rtmp
supervisorctl status             # todos los canales FFmpeg
curl http://localhost:8090/stat  # stats RTMP

# Edges
curl http://186.233.186.55:8091/metrics
curl http://186.233.186.58:8091/metrics
curl http://198.147.24.146:8091/metrics
```

## Gestión de canales

```bash
# Estado de un canal
supervisorctl status fx0093

# Iniciar / detener / reiniciar
supervisorctl start fx0093
supervisorctl stop fx0093
supervisorctl restart fx0093

# Ver logs en tiempo real
tail -f /var/log/supervisor/fx0093.err.log

# Verificar HLS
ls -la /var/lib/nginx-hls/fx0093/
curl -I http://localhost:8090/hls/fx0093/index.m3u8
```

## Panel web

- URL: `http://23.137.84.97/ng_manager.php`
- **Toggle** en cada canal: cambia entre Wowza y nginx
- **Botón Play**: abre preview HLS en el navegador
- **Copiar URL**: URL para reproductores (VLC, players)
- **Edges**: vista de métricas de los 3 edges (auto-refresh 5s)

## Switch Wowza ↔ nginx (manual)

```bash
# Cambiar canal a nginx
cp /etc/supervisor/library/nginx/fxNNNN.conf /etc/supervisor/conf.d/fxNNNN.conf
supervisorctl reread && supervisorctl update
supervisorctl restart fxNNNN

# Cambiar canal a Wowza
cp /etc/supervisor/library/wowza/fxNNNN.conf /etc/supervisor/conf.d/fxNNNN.conf
supervisorctl reread && supervisorctl update
supervisorctl restart fxNNNN
```

## Diagnóstico de problemas

### Canal no reproduce

```bash
# 1. Ver si supervisord lo tiene corriendo
supervisorctl status fxNNNN

# 2. Ver errores de FFmpeg
tail -50 /var/log/supervisor/fxNNNN.err.log

# 3. Verificar HLS generado
ls -la /var/lib/nginx-hls/fxNNNN/
# Si el directorio está vacío o no existe → FFmpeg no funciona

# 4. Probar URL directa
curl -I http://localhost:8090/hls/fxNNNN/index.m3u8
```

### Edge no responde

```bash
# Desde origin
ssh edge1 "systemctl status nginx"
ssh edge1 "systemctl restart nginx"

# Si la API de métricas no responde
ssh edge1 "systemctl restart ng-edge-api"
ssh edge1 "curl localhost:8091/metrics"
```

### Túnel MySQL caído

```bash
systemctl status autossh-mysql-tunnel
systemctl restart autossh-mysql-tunnel

# Probar conexión
mysql -h 127.0.0.1 -P 3307 -u USUARIO -p
```

## Monitoreo de viewers

```bash
# En origin: viewers de un canal específico en tiempo real
tail -f /var/log/nginx/access.log | grep 'fx0093'

# Conteo de IPs únicas últimos 5 min (viewers aproximado)
awk -v d="$(date -d '5 minutes ago' '+%d/%b/%Y:%H:%M:%S')" \
  '$0 > d && /\.m3u8/' /var/log/nginx/access.log | \
  awk '{print $1}' | sort -u | wc -l
```
