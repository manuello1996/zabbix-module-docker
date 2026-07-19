# Docker Monitoring — Zabbix Frontend Module

A Zabbix 7.0/8.0 frontend module that adds first-class Docker monitoring pages to the Zabbix UI, built on top of the official **Docker by Zabbix agent 2** template. No external services, no mocked data — everything is read through the Zabbix API with the permissions of the logged-in user.

Developed by [MonZphere](https://monzphere.com) — Zabbix Integration Partner.

<a href="https://monzphere.com"><img src="assets/img/zabbix-partner.svg" alt="Zabbix Integration Partner" width="170"/></a>

## Features

- **Docker nodes overview** (`Monitoring → Docker`)
  - Aggregated environment cards: nodes, total / running / stopped containers
  - Per-node table: availability badges, active problems by severity, Docker version, container counts, host memory
  - Group / name filtering persisted per user, native pagination
- **Node detail page**
  - Host bar with availability badges (native hint popups) and compact stat pills
  - Container table: status, CPU and memory with inline SVG sparklines, memory limit, network I/O, uptime
  - Client-side search, status chips and sortable columns that survive auto-refresh
  - Container detail modal with ad-hoc CPU / memory / network charts
  - Native time selector (presets + calendar) shared with the Graphs tab
  - Host tabs rendered as module panels: Latest data, Problems (with native event menus), Graphs, Web, Inventory, Node info
  - CSRF-protected auto-refresh with freshness indicator
- **Theme aware** — ships with `blue-theme.css` and `dark-theme.css`, follows the active Zabbix theme
- **Accessible** — ARIA tab semantics, keyboard navigation, visible focus, reduced-motion support

## Requirements

| Component | Version |
|---|---|
| Zabbix server + frontend | 7.0 or 8.0 |
| Zabbix agent 2 (with built-in Docker plugin) | 7.0+ |
| Template | *Docker by Zabbix agent 2* (bundled copy in [`templates/`](templates/docker_by_zabbix_agent_2.yaml)) |

The Volumes and Networks tabs need two extra items that are not part of the stock template: the dependent item `docker.volumes.raw` and the dependent item prototype `docker.container_info.networks["{#NAME}"]`, both with JSONPath extraction and discard-unchanged-with-heartbeat preprocessing. Import [`templates/docker_by_zabbix_agent_2.yaml`](templates/docker_by_zabbix_agent_2.yaml) (Data collection → Templates → Import) to get the template with these items included.
| PHP | 8.2 – 8.5 |

## Installation

1. **Clone the module** into the frontend `modules` directory:

   ```bash
   cd /usr/share/zabbix/modules   # path may differ in your installation
   git clone https://github.com/Monzphere/zabbix-module-docker.git monzphere_docker
   ```

   > The target directory name must be `monzphere_docker`.

2. **Fix ownership** so the web server can read the files:

   ```bash
   chown -R www-data:www-data monzphere_docker   # apache/nginx user
   ```

3. **Register the module** in the Zabbix frontend:
   - Go to **Administration → General → Modules**
   - Click **Scan directory**
   - Locate **Docker Monitoring** and set it to **Enabled**

4. **Prepare the monitored hosts** (once per Docker node):
   - Install Zabbix agent 2 on the node and mount the Docker socket
     (`/var/run/docker.sock`) or add the agent user to the `docker` group
   - Link the **Docker by Zabbix agent 2** template to the host

5. Open **Monitoring → Docker**. Nodes appear automatically as soon as the
   template starts collecting.

### Docker Compose example (agent 2)

```yaml
zabbix-agent2:
  image: zabbix/zabbix-agent2:alpine-latest
  hostname: docker-host-01
  user: root
  environment:
    ZBX_HOSTNAME: docker-host-01
    ZBX_SERVER_HOST: zabbix-server
  volumes:
    - /var/run/docker.sock:/var/run/docker.sock:ro
  ports:
    - "10050:10050"
```

## Optional: automatic host inventory

The module's Inventory tab reads the standard Zabbix host inventory. To fill it
automatically from Docker engine facts, set the hosts' inventory mode to
**Automatic** and add `inventory_link` on the template items:

| Item key | Inventory field |
|---|---|
| `docker.os_type` | Type |
| `docker.name` | Name |
| `docker.operating_system` | OS |
| `docker.kernel_version` | OS (Full details) |
| `docker.server_version` | Software |
| `docker.architecture` | HW architecture |

## Module structure

```
monzphere_docker/
├── manifest.json                    # module manifest (v2.0)
├── Module.php                       # menu entry + time selector registration
├── actions/
│   ├── CControllerDockerList.php       # nodes overview
│   ├── CControllerDockerView.php       # node detail
│   ├── CControllerDockerRefresh.php    # auto-refresh endpoint (JSON, CSRF)
│   ├── CControllerDockerTab.php        # host tab panels (JSON)
│   └── CControllerDockerContainer.php  # container modal (JSON)
├── includes/
│   ├── DockerCollector.php          # metric collection / normalization
│   └── DockerFormatter.php          # display formatting
├── views/
│   ├── monzphere.docker.list.php
│   ├── monzphere.docker.view.php
│   └── js/
│       ├── monzphere.docker.list.js.php
│       └── monzphere.docker.view.js.php
├── templates/
│   └── docker_by_zabbix_agent_2.yaml   # bundled template (networks/volumes items)
└── assets/
    ├── css/
    │   ├── blue-theme.css
    │   └── dark-theme.css
    └── img/
        └── zabbix-partner.svg
```

## Uninstall

Disable the module in **Administration → General → Modules**, then remove the
`monzphere_docker` directory.

---

Developed by **MonZphere**.
