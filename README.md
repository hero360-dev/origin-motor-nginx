# origin-motor-nginx

Sistema de streaming HLS independiente basado en **nginx-rtmp** para gestión de canales de TV, desarrollado como alternativa y respaldo a Wowza Streaming Engine.

## Características

- 🎬 Ingesta de streams desde fuentes HTTPS/RTMP via FFmpeg
- 📡 Distribución HLS via nginx-rtmp en el servidor origin
- 🌐 3 Edge servers con proxy cache nginx (reduce carga en origin)
- 🖥️ Panel web de administración con:
  - Dashboard con gráficas en tiempo real (Chart.js)
  - Gestión de canales (start/stop/switch Wowza↔nginx)
  - Monitor de edges con métricas en vivo
  - Preview HLS en el navegador (HLS.js)
- 📊 Mini API REST en cada edge (métricas: viewers, bandwidth, cache)
- 🔄 Supervisord para gestión de procesos FFmpeg con auto-restart
- 💾 Nombres de canales desde MySQL remoto vía túnel SSH autossh
- 🔐 Switch Wowza↔nginx por canal sin afectar producción

## Arquitectura

```
Fuente HTTPS/RTMP
        │
        ▼
   FFmpeg (supervisord)
        │ RTMP push
        ▼
nginx-rtmp :1936 (Origin 23.137.84.97)
        │ HLS
        ▼
/var/lib/nginx-hls/fxNNNN/
        │ HTTP :8090
        ▼
   Edge Servers (proxy cache)
   ├── Edge1: 186.233.186.55
   ├── Edge2: 186.233.186.58
   └── Edge3: 198.147.24.146
        │ URL estilo Wowza :80
        ▼
    Clientes HLS
```

## Estructura del repositorio

```
origin-motor-nginx/
├── SKILL.md                    # Contexto para Warp AI
├── README.md                   # Este archivo
├── docs/
│   ├── instalacion.md          # Guía de instalación desde cero
│   ├── operacion.md            # Operación diaria
│   └── arquitectura.md         # Diseño del sistema
├── origin/
│   ├── nginx/nginx.conf        # Config nginx-rtmp del origin
│   ├── supervisor/
│   │   ├── channel-template.conf  # Template supervisord por canal
│   │   └── library/            # Templates nginx/wowza
│   ├── scripts/
│   │   └── ng-channel.sh.example  # Script FFmpeg de ejemplo
│   └── panel/                  # Archivos PHP del panel web
│       ├── ng_manager.php
│       ├── ng_dashboard.php
│       ├── ng_edges.php
│       ├── ng_help.php
│       ├── ng_settings.php
│       ├── ng_preview.php
│       └── ng_preview_stop.php
├── edges/
│   ├── nginx/nginx.conf        # Config nginx proxy cache (edges)
│   └── api/ng-edge-api.py      # Mini API métricas (Python)
└── systemd/
    └── ng-edge-api.service     # Servicio systemd mini API
```

## Inicio rápido

Ver [docs/instalacion.md](docs/instalacion.md) para instalación completa.

**Panel:**
```
http://23.137.84.97/ng_manager.php
```

**HLS directo desde origin:**
```
http://23.137.84.97:8090/hls/fx0093/index.m3u8
```

**HLS via edge (estilo Wowza):**
```
http://186.233.186.55/01hbx0093c6WI3k/myStream/playlist.m3u8
```

## Servidores

| Rol    | IP              | OS           |
|--------|-----------------|--------------|
| Origin | 23.137.84.97    | Ubuntu 22.04 |
| Edge1  | 186.233.186.55  | Ubuntu 22.04 |
| Edge2  | 186.233.186.58  | Ubuntu 18.04 |
| Edge3  | 198.147.24.146  | Ubuntu 18.04 |

## Stack tecnológico

- **nginx-rtmp** — Recepción RTMP y generación HLS
- **FFmpeg** — Transcodificación y reingesta de streams
- **Supervisord** — Gestión de procesos FFmpeg
- **PHP + Apache** — Panel web de administración
- **Python 3** — Mini API de métricas en edges
- **MySQL** (remoto vía SSH tunnel) — Base de datos de canales
- **autossh** — Túnel SSH persistente a BD remota
