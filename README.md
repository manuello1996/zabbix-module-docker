# Docker Monitoring for Zabbix

A frontend module for viewing Docker nodes, containers, problems and storage data collected by **Docker by Zabbix agent 2**. Module pages read data through the Zabbix API using the logged-in user's permissions.

Open **Monitoring > Docker** for the node overview. The Docker menu stays selected on the overview, node detail and cleanup pages.

## Features

- **Node overview:** container totals, interface availability, problem severity badges and host tags. Filter by host group, host name or inventory notes, tags, container state and problems. Filters are saved per user. The overview refreshes according to the user's Zabbix refresh interval. Overview and cleanup URLs also include the displayed filters so they can be shared.
- **Node detail:** container state, health, uptime, CPU, memory and network rates; CPU and memory sparklines cover the last 24 hours. Search names and notes, filter state and health, and sort name, CPU or memory across all containers on the selected node. Choose 10, 25, 50 or 100 rows per page and show or hide optional columns. Page size, sorting and visible columns are saved per user in Zabbix `CProfile`. Shared links preserve the host, host groups, active tab, search, state/health filters, chart range and table settings; opening a link does not save its table settings as your defaults.
- **Container details:** a popup with CPU, memory and network charts, labels, ports and mounts. The native time selector controls chart ranges.
- **Host panels:** Containers, Compose, Problems, Graphs, Images, Volumes, Mounts, Networks and Node info, including host inventory. Problems are split by Docker-template origin. Compose projects use `com.docker.compose.project` labels.
- **Cleanup overview:** an on-demand summary of potential unused images, stopped containers, unused volumes and estimated reclaimable storage, sorted by host. This page reads retained monitoring data and never runs prune or removal commands. See the limitations below.
- **Image CSV export:** images with a collected creation timestamp older than the configured image-age threshold (365 days by default), with Team, Host, Notes, Image and Date columns. The Team column is currently blank. The export covers accessible monitored image items independently of the overview filters.
- **Host integration:** Docker links replace Web links for qualifying hosts in host lists, host popups and global search. Qualification requires the Docker container-total item and a linked template whose visible name contains `Docker by Zabbix agent 2`. Global container search matches names and description items and can open a container popup directly.
- **Themes:** styles for the Zabbix blue and dark themes, with visible focus styles and reduced-motion support. Sort controls support Enter and Space; host tabs currently require pointer activation.

The detail page does not automatically refresh its container metrics. Reload it to obtain a new snapshot. Secondary panels load on demand; some remain cached until the page is reloaded. Freshness strips show the collected timestamps and configured item intervals.

## Requirements and compatibility

The module targets Zabbix 7.0 and 8.0. Local runtime checks have been performed with Zabbix frontend 7.0.22 and PHP 8.3; these checks do not establish compatibility with every frontend or PHP release.

| Component | Requirement |
| --- | --- |
| Zabbix frontend | Module support and access to the monitored hosts |
| Zabbix agent 2 | Built-in Docker plugin, with access to the Docker socket |
| Host template | Docker by Zabbix agent 2 |
| Additional panels | Import the [bundled template](templates/docker_by_zabbix_agent_2.yaml) |
| Container labels and Compose metadata | Install the [labels UserParameter](agent2/docker-labels.conf) |

Module entry pages require permission to view Monitoring > Latest data. Problems and Graphs panels additionally check their corresponding Monitoring permissions. Zabbix API permissions restrict accessible host data.

## Installation

1. Place this repository in a directory under the Zabbix frontend's `modules` directory, for example `/usr/share/zabbix/modules/monitor_docker`. The folder name does not have to match the manifest ID.
2. Ensure the frontend's web server user can read the module files.
3. In **Administration > General > Modules**, select **Scan directory**, locate **Docker Monitoring**, and enable it.
4. Configure Zabbix agent 2 on each Docker node with access to `/var/run/docker.sock`, and link the **Docker by Zabbix agent 2** template to the host.
5. Import the [bundled template](templates/docker_by_zabbix_agent_2.yaml) through **Data collection > Templates > Import** for the extra datasets described below. The export uses the Zabbix 7.0 template format.
6. Open **Monitoring > Docker** after collection starts. The overview and detail page identify Docker nodes through monitored `docker.containers.total` items.

### Container labels and Compose metadata

Copy [agent2/docker-labels.conf](agent2/docker-labels.conf) into the agent 2 include directory. Ensure `curl` is installed and the agent user can access `/var/run/docker.sock`, then restart or reload the agent configuration using your deployment's normal procedure.

The UserParameter requests `/containers/json?all=true` from the local Docker API. Its raw response is not retained. A dependent item keeps container identity, image, state and labels. Mount the configuration file and provide `curl` inside the agent container if using a containerized agent.

### Example agent container

Use an agent image version compatible with your Zabbix deployment:

```yaml
services:
  zabbix-agent2:
    image: zabbix/zabbix-agent2:7.0-ubuntu-latest
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

Set `ZBX_HOSTNAME` to the technical host name registered in Zabbix and `ZBX_SERVER_HOST` to your server or proxy address. This example provides Docker plugin access; it does not install the labels UserParameter.

## Collected datasets

The bundled template extends the stock Docker template with compact retained data for the module.

| Item | Purpose | History / unchanged-value heartbeat |
| --- | --- | --- |
| `docker.containers.mounts` | Container mounts | 1 day / 1 hour |
| `docker.containers.ports` | Published ports | 1 day / 1 hour |
| `docker.containers.image_usage` | Container-to-image matching | 1 day / 1 hour |
| `docker.containers.labels` | Labels and Compose metadata | 1 day / 1 hour |
| `docker.volumes.raw` | Volume details | 7 days / 1 hour |
| `docker.container_info.networks["{#NAME}"]` | Network memberships | 7 days / 1 hour |
| `docker.container_info.image_id["{#NAME}"]` | Exact container image ID | Template/default history / 1 day |

The stock `docker.containers`, `docker.images` and `docker.data_usage` master responses are not retained in the bundled template. The module uses retained dependent items where available. Container-to-image matching falls back to image references when exact IDs are unavailable. Freshness checks use Zabbix's configured history display period, so retained but older values may not appear.

## Old-image threshold

Define the global, text macro `{$DOKCER.IMAGE.OLD}` in **Administration > General > Macros** to change when an image is considered old. The spelling `DOKCER` is intentional here and must match exactly.

For example, set `{$DOKCER.IMAGE.OLD}` to `90d` to use 90 days instead of 365. The threshold applies consistently to red Created dates in the Images tab, its tooltip and legend, and the overview's CSV export label, exported rows and filename.

The value is a positive Zabbix time duration, for example `90d`, `12w` or `48h`. A number without a suffix means seconds. Host and template macros do not override this global setting. An absent, empty, invalid, non-positive, secret or vault macro falls back to **365 days**. Images exactly at the cutoff are excluded; only older creation timestamps qualify. Reload the overview to refresh its export label after changing the macro.

## Cleanup limitations

Cleanup candidates are estimates from stored snapshots. Image sizes can include shared layers, so their sum is not a guarantee of recoverable disk space.

The current cleanup implementation has three known data-completeness limitations:

- If container-to-image matching is incomplete and no other candidates are counted, the host is omitted from the table. An absent host does not prove that it is clean.
- Missing or negative volume reference counts are currently counted as unused. Such values do not establish that no container references the volume.
- If an installation retains raw `docker.images` data but lacks usable raw `docker.data_usage` container data, the raw-image path can count images still used by containers as unused. That path does not fall back to the retained container-to-image dataset.

These limitations affect the displayed candidates and estimates. The module itself performs no Docker cleanup operations.

## Optional custom items and inventory

| Custom item | Display |
| --- | --- |
| `lxp.docker.sum_healthy` | Healthy container count |
| `lxp.docker.sum_unhealthy` | Unhealthy container count and overview filter |
| `lxp.docker.compose_version` | Compose version in Node info |

Node info also displays standard Zabbix host inventory. To populate it from Docker facts, enable **Automatic** inventory on the host and assign `inventory_link` fields to the relevant template items:

| Item | Inventory field |
| --- | --- |
| `docker.os_type` | Type |
| `docker.name` | Name |
| `docker.operating_system` | OS |
| `docker.kernel_version` | OS (Full details) |
| `docker.server_version` | Software |
| `docker.architecture` | HW architecture |

## Repository layout and validation

| Path | Contents |
| --- | --- |
| `Module.php`, `manifest.json` | Menu aliases, time-selector registration and action definitions |
| `actions/` | Page, panel, popup, export and integration controllers |
| `includes/` | Metric collection and display formatting |
| `views/` | Page markup and page-specific JavaScript |
| `assets/` | Themes, global integration JavaScript and images |
| `templates/` | Extended Docker template |
| `agent2/` | Labels UserParameter |
| `tests/` | Standalone regression checks |

Run the detail-controller regression checks with PHP:

```sh
php tests/docker_view_test.php
```

The checks cover a selected host beyond the search limit, all 75 fixture containers, legacy page parameters, group exclusions, unavailable hosts and resetting the selection. They use API fixtures and do not need a Zabbix database.

## Screenshots

![Docker node overview](https://github.com/user-attachments/assets/fb54ddc0-2fbb-4711-8570-47dc40b68bc2)
![Docker node detail](https://github.com/user-attachments/assets/7c9f647e-46e0-4425-b9dc-f9a5bd10a28f)
![Docker module panel](https://github.com/user-attachments/assets/35852994-8a98-459e-8fd5-3a50bb2da1bd)

## Uninstall

Disable **Docker Monitoring** in **Administration > General > Modules**, then remove its directory from the frontend's `modules` directory.

Developed by Manuel Pesenti.
