# nginx Edge Balancer + Token — Guía de integración

## Resumen

Este documento describe cómo el **app server externo** debe integrarse con el sistema
de streaming nginx para:

1. Seleccionar el mejor edge disponible (load balancing)
2. Generar un token de seguridad firmado (equivalente al Wowza SecureToken)
3. Devolver al cliente una URL completa lista para reproducir

---

## Arquitectura

```
Cliente (app móvil / web)
    │
    │  "Quiero ver fx0235"
    ▼
App Server externo
    │  1. Consulta métricas de edges (puerto 8091)
    │  2. Selecciona edge con menos carga
    │  3. Genera token nginx (MD5 secure_link)
    ▼
Respuesta al cliente:
  URL = http://186.233.186.55/01hbx0235c6WI3k/myStream/playlist.m3u8
        ?ngtk=BASE64URL_MD5_HASH
        &ngte=UNIX_TIMESTAMP_EXPIRACION
    │
    ▼
Cliente → Edge seleccionado → Origin (proxy pull HLS)
```

---

## Endpoints de métricas por edge

Cada edge expone un API HTTP en el **puerto 8091** (acceso solo desde servidores autorizados):

| Edge | IP | URL métricas |
|------|-----|--------------|
| Edge 1 | 186.233.186.55 | `http://186.233.186.55:8091/metrics` |
| Edge 2 | 186.233.186.58 | `http://186.233.186.58:8091/metrics` |
| Edge 3 | 198.147.24.146 | `http://198.147.24.146:8091/metrics` |

### Formato de respuesta de /metrics

```json
{
  "hostname": "edge1",
  "timestamp": 1756291200,
  "nginx_status": "active",
  "viewers": 12,
  "bw_out_mbps": 45.3,
  "bytes_out_30s": 169875000,
  "requests_30s": 384,
  "cache_size": "850M",
  "cache_files": 2840
}
```

| Campo | Descripción |
|-------|-------------|
| `viewers` | Clientes únicos que pidieron `.m3u8` en los últimos 12s |
| `bw_out_mbps` | Mbps entregados en los últimos 30s |
| `nginx_status` | `active` o `inactive` |

---

## Algoritmo de selección de edge

```
Para cada edge:
    si no responde en 3s → excluir
    score = (viewers × 1.0) + (bw_out_mbps × 0.5)

Seleccionar edge con menor score (menos carga)
Fallback: si todos excluidos → usar edge1 (estático)
```

---

## Fórmula del token nginx

Ver archivo [`token-formula.md`](./token-formula.md) para la fórmula completa con
ejemplos en PHP, Python, Node.js y Java.

---

## Implementación de referencia en PHP

Ver archivo [`balancer-api.php`](./balancer-api.php).

Incluye:
- Consulta paralela de métricas (curl_multi, no bloqueante)
- Algoritmo de scoring
- Generación de token con/sin IP binding
- Autenticación por API key
- Manejo de errores y fallback

---

## Diferencias con Wowza SecureToken

| Aspecto | Wowza | nginx |
|---------|-------|-------|
| Algoritmo hash | SHA-256 / HMAC (SecureToken v2) | MD5 raw → base64url |
| Parámetro hash | `wowzatokenhash=` | `ngtk=` |
| Parámetro expiración | `wowzatokenendtime=` | `ngte=` |
| IP binding | Opcional (SharedSecret param) | Incluido en el hash |
| URL pattern edge | `/fxNNNN/myStream/playlist.m3u8` | `/01hbxNNNNc6WI3k/myStream/playlist.m3u8` |
| Validación | Wowza Engine nativa | nginx `secure_link` module |
| Respuesta sin token | 403 | 403 |
| Respuesta token expirado | 403 | 410 |
| Métricas edge | API Wowza REST | `http://EDGE:8091/metrics` (custom) |

---

## Configuración requerida en el app server

Agregar al archivo de configuración/entorno del app server:

```
NGINX_TOKEN_SECRET=<valor de NGINX_TOKEN_SECRET en /etc/casino-secrets.env del origin>
NGINX_TOKEN_TTL=7200
NGINX_EDGE_METRICS_TIMEOUT=3
```

El secreto es un string hexadecimal de 48 caracteres. Solicitar al administrador
del origin (`23.137.84.97`) el valor actual.

---

## Seguridad: IP binding y CGNAT

El token incluye la IP del cliente en el hash (`remote_addr` en nginx). Esto significa:

- **Si el cliente está detrás de CGNAT** (múltiples usuarios con misma IP pública):
  el token sigue funcionando (todos comparten la IP pública).
- **Si el cliente cambia de red** durante la reproducción: el token falla (410).
- **IPv6**: usar siempre la IPv4 pública del cliente. Si el cliente solo tiene IPv6,
  considerar desactivar IP binding (ver token-formula.md, sección "Sin IP binding").

Para obtener la IPv4 del cliente desde el app server:
```
X-Forwarded-For: header del request HTTP del cliente
```
O si el cliente hace la solicitud directamente: `$_SERVER['REMOTE_ADDR']` (PHP).

