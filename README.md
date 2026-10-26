# TheLinkBox Dashboard

A mobile-friendly dashboard for a [TheLinkBox](http://k5nx.com/TheLinkBox) reflector.

- **Live public page:** who is connected, who is talking, protocol, IP, connect time. Updates every couple of seconds without reloading, and works on phones (light and dark follow the device).
- **Last heard:** every station that talked, how long its last over was, and how much it talked in the last 24 hours, plus a 24-hour timeline of who was on the air and when. Logged by the `tlb-notify` service (one small file per day under `/var/lib/tlb-admin/heard/`, kept 1 to 30 days). Talk that comes in over the AllStar link is credited to the AllStar node that keyed up, read from the node's Allmon3 live feed when its address is set.
- **Server health:** CPU load, memory, disk and live network traffic (and temperature on machines that have a sensor).
- **Password-protected admin area:** run a fixed set of `tlbcmd` commands (version, users, uptime, allow/ban lists, disconnect, EchoLink connect/monitor), with a confirmation step for anything that changes state.
- **Alerts:** a message when a node connects or disconnects, and when the admin area is used, sent to any of: Telegram, Slack, Discord, ntfy, Pushover, email (SMTP), or a generic JSON webhook (Home Assistant, n8n, Zapier, and so on). Everything is configured from the admin page; no files to edit.

## Prerequisites

Debian with Apache, PHP 7.3+ (no extra extensions), and TheLinkBox with `tlbcmd` at `/usr/local/bin/tlbcmd`. The admin area only works over HTTPS.

## Install

Run as root, from a checkout of this repo. Adjust the web folder if yours differs.

```bash
install -d -o www-data -g www-data /var/www/html/irlp
cp irlp/* /var/www/html/irlp/
php set-admin-password.php
```

`set-admin-password.php` asks for the admin password (hidden), stores only a hash in `/etc/tlb-admin.php`, and creates `/var/lib/tlb-admin` for settings and the activity log. Neither is inside the web folder.

### Alerts (optional)

```bash
install -d /usr/local/lib/tlb-dashboard
cp notifier/tlb-notify.php /usr/local/lib/tlb-dashboard/
cp notifier/tlb-notify.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tlb-notify
```

Then open `https://your-host/irlp/admin.php`, go to **Alerts**, switch on the channels you want, and fill in their details (for Telegram, a bot token from @BotFather and your chat ID; for Slack and Discord, a webhook URL; for ntfy, a topic). **Save and send test** checks each one. Changes apply within a couple of seconds.

New channels are a short entry in `irlp/tlb-channels.php` plus one send function.

## Security notes

- Channel secrets (tokens, webhook URLs, SMTP password) are saved in `/var/lib/tlb-admin/settings.json` (not in the web folder, not in git) and are never shown again in the admin page, apart from the last 4 characters.
- Login has a lockout after 5 bad attempts, sessions expire after 20 minutes idle, and every action uses a CSRF token.
- Only the commands listed in `admin.php` can run, and node arguments are validated before use.
- Every login and command is written to `/var/lib/tlb-admin/audit.log`.
