# Alerts

The dashboard has two kinds of alerts:

- **Server alerts** go to the sysop's chat or phone (Telegram, Slack, Discord, ntfy, Pushover, email or a webhook) when stations connect or disconnect, or when someone uses the admin area. They are set up once, in **Admin > Settings**.
- **Browser alerts** are for anyone viewing the public page. Each visitor picks their own (a chime, a notification when someone talks, a watch list of callsigns), and the choices are kept in their browser.

## Server alerts

### What you need

Connect and disconnect alerts come from the alert service (`tlb-notify`), which watches TheLinkBox every couple of seconds. Install it as shown in the [README](../README.md#alert-service-optional) and check it is running:

```bash
systemctl status tlb-notify
```

Admin-area alerts are sent by the admin page itself, so they work even without the service.

### Choosing what to be told about

In **Admin > Settings**, under **Send me an alert when**:

| Switch | What it does |
|---|---|
| A node connects | A message when a station joins. |
| A node disconnects | A message when a station leaves, with how long it was connected. A station has to be gone for 4 seconds first, so a brief hiccup doesn't send a disconnect and a reconnect. |
| Someone uses the admin area | Logins, failed logins, commands run on a node (connect, disconnect, ban and so on) and clearing the Last heard log, with the address it came from. |
| Skip lurkers | No messages for stations connected as lurkers. |
| Skip permanent links | No messages for nailed-up/permanent links. |
| Server name in alerts | The first line of every message (and the email subject / notification title). |

A message looks like this:

```
KV4S Reflector · connected
W3NH (stn8706) (IRLP) from 71.14.56.13
Users now: 3
```

IRLP stations are shown with their callsign when status.irlp.net knows it.

### Setting up a channel

Switch on as many channels as you like; every message goes to all of them. Each channel card has **Save and send test**, which sends a test message and tells you exactly what went wrong if it fails. Changes apply within a couple of seconds, with no restart.

Tokens, webhook addresses and passwords are write-only: after saving, the page only shows their last 4 characters. To change one, type the new value; leave the box blank to keep the saved one.

#### Telegram

1. In Telegram, talk to **@BotFather**, send `/newbot` and follow the prompts. It gives you a **bot token** like `123456789:AA...`.
2. Send any message to your new bot (or add it to a group and send a message there).
3. Open `https://api.telegram.org/bot<your token>/getUpdates` in a browser and find `"chat":{"id": ...}`. That number is the **Chat ID**. Group chat IDs start with a minus sign; include it.
4. Paste both into the Telegram card.

If a token is ever shared by mistake, send `/revoke` to @BotFather and paste the new one.

#### Slack

1. Go to <https://api.slack.com/apps>, **Create New App > From scratch**, and pick your workspace.
2. Open **Incoming Webhooks**, switch it on, then **Add New Webhook to Workspace** and choose the channel.
3. Copy the `https://hooks.slack.com/services/...` address into the Slack card.

#### Discord

1. In the Discord channel, open **Edit Channel > Integrations > Webhooks > New Webhook**.
2. Name it, then **Copy Webhook URL** and paste it into the Discord card.

#### ntfy

[ntfy](https://ntfy.sh) is a free push-notification service with apps for Android and iPhone.

1. Install the ntfy app and subscribe to a topic. On the public `ntfy.sh` server anyone who knows a topic name can read it, so pick something hard to guess, such as `kv4s-reflector-7f3k2`.
2. In the ntfy card, leave the server as `https://ntfy.sh` (or put your own ntfy server's address) and enter the topic.
3. The access token is only needed for a protected topic on your own server.

The first line of the message becomes the notification title.

#### Pushover

1. Sign in at <https://pushover.net>. Your **User Key** is on the main page.
2. Under **Your Applications**, choose **Create an Application/API Token**, name it (for example "TheLinkBox") and copy the **API Token**.
3. Paste both into the Pushover card. Both are 30 characters long.

#### Email (SMTP)

Fill in your mail provider's outgoing (SMTP) server. Common settings:

| Provider | Server | Port | Security |
|---|---|---|---|
| Gmail | smtp.gmail.com | 587 | STARTTLS |
| Outlook.com / Microsoft 365 | smtp.office365.com | 587 | STARTTLS |
| Yahoo | smtp.mail.yahoo.com | 465 | SSL/TLS |
| Your ISP or own server | ask them | 587 or 465 | usually STARTTLS |

Gmail and Yahoo need an **app password** (made in your account's security settings, with 2-step verification turned on) rather than your normal password. The From address usually has to be the account you sign in with. The first line of the message becomes the subject.

#### Generic webhook

For Home Assistant, Node-RED, n8n, Zapier and anything else that accepts a web request. The dashboard sends a `POST` with this JSON body:

```json
{
  "event": "connect",
  "server": "KV4S Reflector",
  "text": "KV4S Reflector · connected\nW3NH (stn8706) (IRLP) from 71.14.56.13\nUsers now: 3"
}
```

`event` is `connect`, `disconnect`, `admin` or `test`. If the receiver needs a key, put it in **Extra header**, for example `Authorization: Bearer abc123`.

Home Assistant example: create an automation with a **Webhook** trigger, copy its address (`https://your-ha/api/webhook/<id>`) into the URL box, and use `{{ trigger.json.text }}` in the action.

### When alerts don't arrive

- Press **Save and send test** on the channel. If the test works but real alerts don't, the alert service is the problem; if the test fails, the message says why (wrong token, unreachable server, login refused and so on).
- Check the service and its log:

  ```bash
  systemctl status tlb-notify
  journalctl -u tlb-notify -n 50
  ```

- The service has to be able to reach the channel over the internet (outgoing HTTPS, or your SMTP port).
- "Skip lurkers" and "Skip permanent links" hide those stations on purpose.

## Browser alerts

When **Show the Alerts button on the main page** is switched on in **Admin > Settings** (it is by default), the public page shows an **Alerts** button. Each visitor can choose:

- **Chime when someone starts talking.**
- **Notify me when someone starts talking**, as a system notification.
- **Notify me when someone connects:** off, anyone, or only callsigns on their **watch list** (comma separated, e.g. `N4RGJ, W1AW`). This covers stations on the reflector and on the linked AllStar node.

**Send a test** checks that notifications are allowed. The browser asks for permission the first time.

Browser alerts only work while the dashboard is open, though it can be in a background tab (browsers may check background tabs less often). On iPhone and iPad, notifications need the page added to the Home Screen first (Share > Add to Home Screen) and opened from there.

Nothing is sent to the server: the choices live in that browser only, so each device is set up separately.
