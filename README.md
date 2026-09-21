# Docker Monitoring — Zabbix Frontend Module
<img width="1240" height="767" alt="Captura de Tela 2026-07-19 às 19 40 23" src="https://github.com/user-attachments/assets/fb54ddc0-2fbb-4711-8570-47dc40b68bc2" />
<img width="1237" height="758" alt="Captura de Tela 2026-07-19 às 19 41 02" src="https://github.com/user-attachments/assets/7c9f647e-46e0-4425-b9dc-f9a5bd10a28f" />
<img width="1238" height="765" alt="Captura de Tela 2026-07-19 às 19 41 41" src="https://github.com/user-attachments/assets/35852994-8a98-459e-8fd5-3a50bb2da1bd" />

A Zabbix 7.0/8.0 frontend module that adds first-class Docker monitoring pages to the Zabbix UI, built on top of the official **Docker by Zabbix agent 2** template. No external services, no mocked data — everything is read through the Zabbix API with the permissions of the logged-in user.

## Features

- **Docker nodes overview** (`Monitoring → Docker`)
  - Aggregated environment cards: nodes, total / running / stopped containers
  - Per-node table: availability badges, active problems by severity, container counts, host tags
  - Host-group and free-text filtering (visible host name or inventory notes), persisted per user
  - Optional container state, host-tag, all-problem and Docker-template-problem filters
  - Docker-template problem matching includes templates whose visible name contains `Docker by Zabbix agent 2`
  - CSV export of every collected image older than 365 days, with Team, Host, inventory Notes, Image and creation Date columns
- **Docker Cleanup** (the **Docker Cleanup** action on `Monitoring > Docker`)
  - On-demand, read-only host summary of removable dangling images, stopped containers and unused volumes
  - Uses retained container-status items and optional volume details. Exact dangling-image detection requires a
    retained `docker.images` value; the page identifies the hosts where that inventory is unavailable
  - Sorts hosts by estimated reclaimable storage without loading cleanup data on the Docker nodes overview
- **Node detail page**
  - Host bar with availability badges (native hint popups) and compact stat pills
  - Problems split into Docker-template-originated and other host problems
  - Container table: status, CPU and memory with inline SVG sparklines, network I/O, uptime
  - Client-side search, status chips and sortable columns that survive auto-refresh
  - Container detail modal with ad-hoc CPU / memory / network charts
  - Native time selector (presets + calendar) shared with the Graphs tab
  - Host tabs rendered as module panels: Containers, Problems (with native event menus), Graphs, Images, Volumes,
    Mounts, Networks, Compose projects, Node info (including host inventory)
  - Container popup label inventory and a Compose view grouped by `com.docker.compose.project`, including all
    `com.docker.compose.*` deployment metadata
  - CSRF-protected auto-refresh with freshness indicator
- **Theme aware** — ships with `blue-theme.css` and `dark-theme.css`, follows the active Zabbix theme
- **Accessible** — ARIA tab semantics, keyboard navigation, visible focus, reduced-motion support
- **Native Zabbix integration**
  - Replaces the per-host Web endpoint with Docker in Data collection → Hosts and Monitoring → Hosts
    when the host is linked to a template whose name contains `Docker by Zabbix agent 2`
  - Replaces Web with Docker in both the View and Configuration sections of host popups, including Problems and Latest data
  - Replaces Web with Docker in global search host results for Docker-template hosts
  - Adds a `Docker Containers` section directly below Hosts in the global search results, matching both
    container names and `docker.container.description[...]` values
  - Container search links open the related Docker host page and its container detail popup
  - Uses a separate read-only module action and global asset, so it does not override the core actions
    also customized by the Better Search module

## Requirements

| Component | Version |
|---|---|
| Zabbix server + frontend | 7.0 or 8.0 |
| Zabbix agent 2 (with built-in Docker plugin) | 7.0+ |
| Template | *Docker by Zabbix agent 2* (bundled copy in [`templates/`](templates/docker_by_zabbix_agent_2.yaml)) |

The bundled template adds items that are not part of the stock template:
`docker.containers.mounts`, `docker.containers.ports`, `docker.containers.image_usage`,
`docker.containers.labels`, `docker.volumes.raw`, `docker.container_info.networks["{#NAME}"]`, and
`docker.container_info.image_id["{#NAME}"]`.
Import [`templates/docker_by_zabbix_agent_2.yaml`](templates/docker_by_zabbix_agent_2.yaml)
(Data collection → Templates → Import) to enable the compact stored datasets used for mounts,
ports and image usage, plus volume and network details. Each module dataset retains one day of
history and discards unchanged values with a one-hour heartbeat; the complete stock
`docker.containers` master response remains unstored. Image usage falls back to image-reference
matching until exact image IDs are available.

Container labels require the additional Agent 2 UserParameter shipped in
[`agent2/docker-labels.conf`](agent2/docker-labels.conf). Copy it into the Agent 2 include directory,
ensure `curl` is installed and the Agent 2 user can read `/var/run/docker.sock`, then reload or restart
Agent 2. The raw Docker API response is not retained; the dependent `docker.containers.labels` item
stores only container identity, image, state and labels. The cleanup page never executes a Docker
prune or removal command.
| PHP | 8.2 – 8.5 |

## Installation

1. **Clone the module** into the frontend `modules` directory:

   ```bash
   cd /usr/share/zabbix/modules   # path may differ in your installation
   git clone <repository-url> monitor_docker
   ```

   > The target directory name must be `monitor_docker`.

2. **Fix ownership** so the web server can read the files:

   ```bash
   chown -R www-data:www-data monitor_docker   # apache/nginx user
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

## Optional custom node items

When these items exist on a Docker host, the module displays their latest values:

| Item key | Display |
|---|---|
| `lxp.docker.sum_healthy` | Healthy container count in the host stat bar |
| `lxp.docker.sum_unhealthy` | Unhealthy container count in the host stat bar |
| `lxp.docker.compose_version` | Docker Compose version in Node info |

## Optional: automatic host inventory

The module displays the standard Zabbix host inventory beneath the Node info table. To fill it
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
monitor_docker/
├── manifest.json                    # module manifest (v2.0)
├── Module.php                       # menu entry + time selector registration
├── actions/
│   ├── CControllerDockerList.php       # nodes overview
│   ├── CControllerDockerCleanup.php    # cleanup candidates overview
│   ├── CControllerDockerView.php       # node detail
│   ├── CControllerDockerTab.php        # host tab panels (JSON)
│   ├── CControllerDockerContainer.php  # container modal (JSON)
│   └── CControllerDockerIntegration.php # host links + container search (JSON)
├── includes/
│   ├── DockerCollector.php          # metric collection / normalization
│   └── DockerFormatter.php          # display formatting
├── views/
│   ├── docker.list.php
│   ├── docker.cleanup.php
│   ├── docker.view.php
│   └── js/
│       ├── docker.list.js.php
│       └── docker.view.js.php
├── templates/
│   └── docker_by_zabbix_agent_2.yaml   # bundled template (networks/volumes items)
└── assets/
    ├── css/
    │   ├── blue-theme.css
    │   └── dark-theme.css
    ├── js/
    │   └── integrations.js
    └── img/
        └── zabbix-partner.svg
```

## Uninstall

Disable the module in **Administration → General → Modules**, then remove the
`monitor_docker` directory.

---

Developed by **Manuel Pesenti**.
