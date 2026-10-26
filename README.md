# TheLinkBox Dashboard

A modern, mobile-friendly dashboard for a [TheLinkBox](http://k5nx.com/TheLinkBox) reflector (IRLP, EchoLink and AllStar). It shows who is connected and who is talking, in real time, and has a password-protected admin area to manage the reflector from your phone.

## What it does

**Public page**
- Live list of connected stations: protocol, IP address, connect time, and a highlight on whoever is talking. Updates every couple of seconds without reloading. Light and dark follow the device, and it works on phones.
- **Live network diagram:** TheLinkBox and your AllStar node as two hubs, every station around them, the bridge between them. Whoever is talking pulses and audio animates toward the hubs.
- **AllStar panel (optional):** what is linked to your AllStar node, with callsigns linked to QRZ, who is talking, and the AllStarLink network map.
- **Callsign lookups:** IRLP stations (`stnNNNN`) and EchoLink stations linked through AllStar show their callsign and city or description, with the node number underneath. Callsigns link to QRZ; IRLP nodes link to their IRLP status page.
- **Last heard:** every station that talked, how long its last over was and how much it talked in the last 24 hours, plus a 24-hour timeline of who was on the air and when. Logged by the `tlb-notify` service (one small file per day under `/var/lib/tlb-admin/heard/`, kept 1 to 30 days). Talk that comes in over the AllStar link is credited to the AllStar node that keyed up, read from the node's Allmon3 or Allmon2 live feed when its address is set.
- **Server health:** CPU load, memory, disk and live network traffic (and temperature on machines that have a sensor).
- **Browser alerts (optional, per visitor):** a chime and a phone or desktop notification when someone starts talking, or when a watched callsign connects. Each visitor turns it on for their own device; the site owner can switch the feature off for everyone.

**Admin area** (HTTPS only, password protected)
- **Commands:** connected-now list with Disconnect and Ban buttons, Connect/Monitor by node, and a recent-nodes dropdown.
- **Allow list and Ban list:** view, add and remove entries.
- **AllStar:** set your node number (and optionally your Allmon3 address for real-time talking).
- **Settings:** the alert channels below, plus switches for the Last heard and server health sections, how long to keep the log, a clear-log button and an optional custom footer link.
- **Alerts (in Settings):** connect/disconnect/admin alerts sent to Telegram, Slack, Discord, ntfy, Pushover, email (SMTP) or a generic JSON webhook (Home Assistant, n8n, Zapier and so on). Everything is configured from the page; there are no files to edit. Setup guide: [docs/ALERTS.md](docs/ALERTS.md).
- **Activity:** an audit log of every login and command.

## Prerequisites

Debian (or similar) with Apache, PHP 7.3 or newer (no extra extensions), and TheLinkBox with `tlbcmd` at `/usr/local/bin/tlbcmd`. The admin area only works over HTTPS.

## Install

Download the latest release from the [Releases page](https://github.com/Russell-KV4S/TheLinkBox-Dashboard/releases) (or clone the repo), then run these as root from its folder. Change the web folder if yours differs.

```bash
install -d -o www-data -g www-data /var/www/html/irlp
install -o www-data -g www-data -m 644 irlp/* /var/www/html/irlp/
php set-admin-password.php
```

`set-admin-password.php` asks for the admin password (hidden), stores only a hash in `/etc/tlb-admin.php`, and creates `/var/lib/tlb-admin` for settings and the audit log. Neither is inside the web folder.

Then open `https://your-host/irlp/` for the dashboard and `https://your-host/irlp/admin.php` for the admin area.

### Alert service (optional)

The public page and admin work without this. The service watches `tlbcmd users` and sends connect/disconnect alerts to the channels you enable.

```bash
install -d /usr/local/lib/tlb-dashboard
install -m 644 notifier/tlb-notify.php /usr/local/lib/tlb-dashboard/
install -m 644 notifier/tlb-notify.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tlb-notify
```

If the web files are not in `/var/www/html/irlp`, set `Environment=TLB_LIB=/path/to/irlp/tlb-lib.php` in the service file.

In **Admin > Settings**, switch on the channels you want and fill in their details (for Telegram, a bot token from @BotFather and your chat ID; for Slack and Discord, a webhook URL; for ntfy, a topic). **Save and send test** checks each one. Changes apply within a couple of seconds, with no restart. Step-by-step setup for every channel, the webhook format and the browser alerts are in [docs/ALERTS.md](docs/ALERTS.md).

### AllStar panel (optional)

In **Admin > AllStar**, enter your AllStar node number. The panel and diagram then appear on the main page.

- Without anything else, the list comes from AllStarLink's public stats and updates about once a minute.
- For a real-time list that shows who is talking, also enter the web address of your node's **Allmon3** dashboard, for example `https://example.org/allmon3/`. **Save and test** confirms the live feed was found.

The network map is fetched from AllStarLink and cached on your server (the last good copy is shown instantly and refreshed in the background, so a slow or down AllStarLink never slows your page).

## How it works

- `tlbcmd` supplies the connection list, protocols and who is talking. For EchoLink stations, talking is read from the port table.
- AllStarLink's public stats API supplies callsigns and locations for linked nodes (cached; at most a few new lookups per request).
- IRLP callsigns come from each node's status.irlp.net page (cached for a day); EchoLink callsigns come from EchoLink's public list of logged-in stations (fetched at most every 10 minutes).
- Allmon3's WebSocket, if configured, is read directly by the visitor's browser for real-time talking.
- For Allmon2 (address contains `allmon2`), the notifier reads its event stream instead and relays the latest state through `asl.php`, so the node's Allmon2 page must be viewable without a login.
- Adding an alert channel is a short entry in `irlp/tlb-channels.php` plus one send function.

## Versions

The current version is shown at the bottom of the page. Each version is published on the [Releases page](https://github.com/Russell-KV4S/TheLinkBox-Dashboard/releases) (choose **Watch > Custom > Releases** on GitHub to be told about new ones), and [CHANGELOG.md](CHANGELOG.md) lists what changed in each. To upgrade, copy the new `irlp/*` files over the old ones as in Install; if `notifier/tlb-notify.php` changed, install it too and run `systemctl restart tlb-notify`.

## Security notes

- Channel secrets (tokens, webhook URLs, SMTP password) are stored in `/var/lib/tlb-admin/settings.json`, outside the web folder and not in git, and are never shown again in the admin page apart from the last 4 characters.
- Login has a lockout after 5 bad attempts, sessions expire after 20 minutes idle, and every action uses a CSRF token. The admin area sends a strict security policy that blocks scripts.
- Only the commands listed in `admin.php` can run, and node and callsign arguments are validated against a strict pattern before they reach a shell. Disconnect and Ban ask for confirmation.
- Admin-entered URLs (the Allmon3 address) must be public https addresses.
- Every login and command is written to `/var/lib/tlb-admin/audit.log`.
- Browser alert preferences are kept in each visitor's own browser; nothing about visitors is stored on the server.

## Credits

Dashboard design by Russell, KV4S. Released under the license in `LICENSE`.
