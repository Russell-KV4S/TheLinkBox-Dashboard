<?php
// Watches `tlbcmd users` and sends alerts (Telegram, Slack, Discord, ntfy, Pushover, email, webhook) when nodes connect or disconnect.
// Settings are read fresh each cycle from the file the admin page writes, so changes take effect
// without restarting the service. Run by systemd as www-data (see tlb-notify.service).

if (php_sapi_name() !== 'cli') exit(1);

require getenv('TLB_LIB') ?: '/var/www/html/irlp/tlb-lib.php';

const POLL_SECS = 2;

// Send to all enabled channels; log failures to the journal.
function send($s, $event, $msg) {
	foreach (tlb_notify($s, $event, $msg) as $ch => $r) {
		if (!$r[0]) fwrite(STDERR, "[$ch] ".$r[1]."\n");
	}
}

// Seconds that a user must be missing before we call it a disconnect (rides out brief tlbcmd hiccups).
const MISSING_GRACE = 4;

$known   = null;   // node => ['attrs','time','ip','proto','since']
$missing = [];     // node => first time seen missing
$firstRun = true;

while (true) {
	$s     = tlb_load_settings();
	$users = tlb_users();

	// tlbcmd returned nothing parseable and nobody was known: treat as empty. If it fails while
	// users are known we rely on MISSING_GRACE rather than announcing a mass disconnect instantly.
	$protos = $users ? tlb_protocols() : [];
	$now    = time();

	if ($known === null) {
		$known = [];
		foreach ($users as $n => $u) {
			$known[$n] = $u + ($protos[$n] ?? ['ip' => '', 'proto' => '']) + ['since' => $now];
		}
		sleep(POLL_SECS);
		continue;
	}

	$skip = function ($attrs) use ($s) {
		if ($s['skip_lurkers'] && strpos($attrs, 'L') !== false) return true;
		if ($s['skip_permanent'] && strpos($attrs, 'P') !== false) return true;
		return false;
	};

	foreach ($users as $n => $u) {
		unset($missing[$n]);
		if (!isset($known[$n])) {
			$known[$n] = $u + ($protos[$n] ?? ['ip' => '', 'proto' => '']) + ['since' => $now];
			tlb_recent_add($n);
			if (tlb_any_channel_ready($s) && $s['on_connect'] && !$skip($u['attrs'])) {
				$msg = $s['server_label']." · connected\n".$n.
					($known[$n]['proto'] ? ' ('.$known[$n]['proto'].')' : '').
					($known[$n]['ip'] ? ' from '.$known[$n]['ip'] : '')."\nUsers now: ".count($users);
				send($s, 'connect', $msg);
			}
		}
	}

	foreach ($known as $n => $k) {
		if (isset($users[$n])) continue;
		if (!isset($missing[$n])) { $missing[$n] = $now; continue; }
		if ($now - $missing[$n] < MISSING_GRACE) continue;
		unset($known[$n], $missing[$n]);
		if (tlb_any_channel_ready($s) && $s['on_disconnect'] && !$skip($k['attrs'])) {
			$mins = max(0, round(($now - $k['since']) / 60));
			$msg = $s['server_label']." · disconnected\n".$n.
				($k['proto'] ? ' ('.$k['proto'].')' : '').' left after '.$mins."m\nUsers now: ".count($users);
			send($s, 'disconnect', $msg);
		}
	}

	sleep(POLL_SECS);
}
