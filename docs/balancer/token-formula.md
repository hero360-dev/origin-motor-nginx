# nginx SecureToken — Fórmula y ejemplos

## Fórmula base

La fórmula es equivalente a Wowza SecureToken pero usando **MD5** en lugar de SHA-256,
y el resultado se codifica en **base64url** (sin padding).

```
INPUT  = "{expires}{uri}{client_ip} {secret}"
HASH   = MD5(INPUT, raw_binary=true)
TOKEN  = base64url_encode(HASH)   # base64 con +→- y /→_ y sin =

URL    = http://{edge_ip}{uri}?ngtk={TOKEN}&ngte={expires}
```

### Componentes

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `expires` | Unix timestamp de expiración (entero) | `1756291200` |
| `uri` | Path completo de la playlist | `/01hbx0235c6WI3k/myStream/playlist.m3u8` |
| `client_ip` | IPv4 pública del cliente final | `203.0.113.45` |
| `secret` | `NGINX_TOKEN_SECRET` (48 chars hex) | `c6b4bc0...` |

> **IMPORTANTE**: entre `{client_ip}` y `{secret}` hay **un espacio**.
> El string queda: `1756291200/01hbx0235c6WI3k/myStream/playlist.m3u8203.0.113.45 c6b4bc0...`

### Conversión de número de canal

```
fx0235  →  0235   (quitar prefijo "fx")
fx0093  →  0093
fx0100  →  0100
```

### Verificación rápida desde terminal

```bash
SECRET="TU_NGINX_TOKEN_SECRET_AQUI"
EXPIRES=$(( $(date +%s) + 7200 ))
URI="/01hbx0235c6WI3k/myStream/playlist.m3u8"
IP="203.0.113.45"

HASH=$(printf '%s' "${EXPIRES}${URI}${IP} ${SECRET}" \
  | openssl dgst -md5 -binary \
  | openssl base64 \
  | tr '+/' '-_' \
  | tr -d '=')

echo "Token: $HASH"
echo "URL: http://186.233.186.55${URI}?ngtk=${HASH}&ngte=${EXPIRES}"

# Probar contra el edge (debe responder 200):
curl -I "http://186.233.186.55${URI}?ngtk=${HASH}&ngte=${EXPIRES}"
```

---

## Implementaciones por lenguaje

### PHP

```php
function nginxMakeToken(string $expires, string $uri, string $clientIp, string $secret): string
{
    $input  = "{$expires}{$uri}{$clientIp} {$secret}";
    $binary = md5($input, true);                          // raw binary
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '='); // base64url
}

function nginxBuildUrl(string $edgeIp, string $fx, string $clientIp, string $secret, int $ttl = 7200): string
{
    $chNum   = ltrim(preg_replace('/^fx/', '', $fx), '0') ?: '0';
    $chNum   = str_pad($chNum, 4, '0', STR_PAD_LEFT);    // fx0235 → 0235
    $expires = time() + $ttl;
    $uri     = "/01hbx{$chNum}c6WI3k/myStream/playlist.m3u8";
    $token   = nginxMakeToken((string)$expires, $uri, $clientIp, $secret);
    return "http://{$edgeIp}{$uri}?ngtk={$token}&ngte={$expires}";
}

// Uso:
$url = nginxBuildUrl('186.233.186.55', 'fx0235', '203.0.113.45', getenv('NGINX_TOKEN_SECRET'));
```

---

### Python

```python
import hashlib, base64, time

def nginx_make_token(expires: int, uri: str, client_ip: str, secret: str) -> str:
    raw = f"{expires}{uri}{client_ip} {secret}"
    md5_binary = hashlib.md5(raw.encode()).digest()          # raw binary
    b64 = base64.b64encode(md5_binary).decode()
    return b64.replace('+', '-').replace('/', '_').rstrip('=')  # base64url

def nginx_build_url(edge_ip: str, fx: str, client_ip: str, secret: str, ttl: int = 7200) -> str:
    ch_num  = fx.lstrip('fx').zfill(4)                       # fx0235 → 0235
    expires = int(time.time()) + ttl
    uri     = f"/01hbx{ch_num}c6WI3k/myStream/playlist.m3u8"
    token   = nginx_make_token(expires, uri, client_ip, secret)
    return f"http://{edge_ip}{uri}?ngtk={token}&ngte={expires}"

# Uso:
import os
url = nginx_build_url('186.233.186.55', 'fx0235', '203.0.113.45', os.environ['NGINX_TOKEN_SECRET'])
```

---

### Node.js

```javascript
const crypto = require('crypto');

function nginxMakeToken(expires, uri, clientIp, secret) {
    const input  = `${expires}${uri}${clientIp} ${secret}`;
    const binary = crypto.createHash('md5').update(input, 'utf8').digest();
    return binary.toString('base64')
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function nginxBuildUrl(edgeIp, fx, clientIp, secret, ttl = 7200) {
    const chNum  = fx.replace(/^fx/, '').padStart(4, '0');  // fx0235 → 0235
    const expires = Math.floor(Date.now() / 1000) + ttl;
    const uri    = `/01hbx${chNum}c6WI3k/myStream/playlist.m3u8`;
    const token  = nginxMakeToken(expires, uri, clientIp, secret);
    return `http://${edgeIp}${uri}?ngtk=${token}&ngte=${expires}`;
}

// Uso:
const url = nginxBuildUrl('186.233.186.55', 'fx0235', '203.0.113.45', process.env.NGINX_TOKEN_SECRET);
```

---

### Java

```java
import java.security.MessageDigest;
import java.util.Base64;
import java.time.Instant;

public class NginxToken {

    public static String makeToken(long expires, String uri, String clientIp, String secret)
            throws Exception {
        String input = expires + uri + clientIp + " " + secret;
        MessageDigest md = MessageDigest.getInstance("MD5");
        byte[] binary = md.digest(input.getBytes("UTF-8"));
        String b64 = Base64.getEncoder().encodeToString(binary);
        return b64.replace('+', '-').replace('/', '_').replaceAll("=+$", "");
    }

    public static String buildUrl(String edgeIp, String fx, String clientIp, String secret, long ttl)
            throws Exception {
        String chNum  = fx.replaceFirst("^fx", "");
        chNum = String.format("%04d", Integer.parseInt(chNum));  // fx0235 → 0235
        long expires  = Instant.now().getEpochSecond() + ttl;
        String uri    = "/01hbx" + chNum + "c6WI3k/myStream/playlist.m3u8";
        String token  = makeToken(expires, uri, clientIp, secret);
        return "http://" + edgeIp + uri + "?ngtk=" + token + "&ngte=" + expires;
    }
}
```

---

## Opción: Sin IP binding

Si los clientes tienen problemas con CGNAT, proxies, o IPv6, se puede eliminar el
IP binding del token. Requiere cambiar la config nginx en los edges:

**Edge nginx.conf actual (con IP binding):**
```nginx
secure_link_md5 "$secure_link_expires$uri$remote_addr SECRET";
```

**Edge nginx.conf sin IP binding:**
```nginx
secure_link_md5 "$secure_link_expires$uri SECRET";
```

**Token sin IP binding (PHP):**
```php
function nginxMakeTokenNoIp(string $expires, string $uri, string $secret): string
{
    $input  = "{$expires}{$uri} {$secret}";  // sin IP, con espacio antes del secret
    $binary = md5($input, true);
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}
```

> Este cambio requiere actualizar la config nginx en los 3 edges y recargarlos.
> Consultar con el administrador del sistema.

---

## Respuestas HTTP del edge

| Código | Significado |
|--------|-------------|
| `200` | Token válido, stream entregado |
| `403` | Token ausente, malformado, o IP incorrecta |
| `410` | Token expirado (`expires` en el pasado) |
| `404` | Canal no existe en el edge |

---

## Notas de seguridad

- El secreto (`NGINX_TOKEN_SECRET`) nunca debe exponerse en URLs, logs, ni código fuente
- Usar variables de entorno o vault para almacenarlo en el app server
- El TTL de 7200s (2 horas) es configurable; para streams en vivo, valores menores (1800s) son más seguros
- Rotar el secreto periódicamente usando el panel (`ng_settings.php` → Token → Regenerar)
