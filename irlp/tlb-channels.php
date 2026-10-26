<?php
// Notification channels. Each channel is: a label, its settings fields, and a sender.
// Settings keys: ch_<channel> (enabled flag) plus the field keys below.
// To add a channel: add an entry to tlb_channels() and a tlb_send_<channel>() function.
//
// Field types: text, secret (write-only in the admin page), select (options in 'options').

function tlb_channels() {
	$email = '/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/';
	return [
		'telegram' => ['label' => 'Telegram', 'fields' => [
			['tg_token',   'Bot token', 'secret', '/^\d{5,12}:[A-Za-z0-9_-]{30,50}$/', 'From @BotFather'],
			['tg_chat_id', 'Chat ID',   'text',   '/^-?\d{1,20}$/', 'A number'],
		]],
		'slack' => ['label' => 'Slack', 'fields' => [
			['slack_url', 'Incoming webhook URL', 'secret', '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', 'https://hooks.slack.com/services/…'],
		]],
		'discord' => ['label' => 'Discord', 'fields' => [
			['discord_url', 'Webhook URL', 'secret', '#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_-]+$#', 'https://discord.com/api/webhooks/…'],
		]],
		'ntfy' => ['label' => 'ntfy', 'fields' => [
			['ntfy_server', 'Server',                  'text',   '#^https?://[A-Za-z0-9.-]+(:\d+)?$#', 'https://ntfy.sh'],
			['ntfy_topic',  'Topic',                   'text',   '/^[A-Za-z0-9_-]{1,64}$/', 'Pick something hard to guess'],
			['ntfy_token',  'Access token (optional)', 'secret', '/^[A-Za-z0-9_-]{1,100}$/', 'Only for protected topics'],
		]],
		'pushover' => ['label' => 'Pushover', 'fields' => [
			['po_app',  'Application token', 'secret', '/^[A-Za-z0-9]{30}$/', '30 characters'],
			['po_user', 'User key',          'secret', '/^[A-Za-z0-9]{30}$/', '30 characters'],
		]],
		'email' => ['label' => 'Email (SMTP)', 'fields' => [
			['smtp_host', 'SMTP server',  'text',   '/^[A-Za-z0-9.-]{1,253}$/', 'smtp.example.com'],
			['smtp_port', 'Port',         'text',   '/^\d{1,5}$/', '587'],
			['smtp_sec',  'Security',     'select', null, ''],
			['smtp_user', 'Username (optional)', 'text', '/^[^\r\n]{1,200}$/', ''],
			['smtp_pass', 'Password',     'secret', '/^[^\r\n]{1,200}$/', ''],
			['mail_from', 'From address', 'text',   $email, 'alerts@example.com'],
			['mail_to',   'To address',   'text',   $email, 'you@example.com'],
		]],
		'webhook' => ['label' => 'Generic webhook', 'fields' => [
			['wh_url',    'URL',                      'secret', '#^https?://\S{4,500}$#', 'Receives JSON'],
			['wh_header', 'Extra header (optional)', 'secret', '/^[A-Za-z0-9-]{1,50}: [^\r\n]{1,300}$/', 'Authorization: Bearer …'],
		]],
	];
}

function tlb_select_options($key) {
	return $key === 'smtp_sec' ? ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25)'] : [];
}

function tlb_channel_defaults() {
	$d = ['ntfy_server' => 'https://ntfy.sh', 'smtp_port' => '587', 'smtp_sec' => 'tls'];
	foreach (tlb_channels() as $k => $c) {
		$d['ch_'.$k] = false;
		foreach ($c['fields'] as $f) if (!isset($d[$f[0]])) $d[$f[0]] = '';
	}
	return $d;
}

// A channel is usable when it's switched on and its required fields are filled in.
function tlb_channel_ready(array $s, $key) {
	if (empty($s['ch_'.$key])) return false;
	$need = [
		'telegram' => ['tg_token', 'tg_chat_id'], 'slack' => ['slack_url'], 'discord' => ['discord_url'],
		'ntfy' => ['ntfy_server', 'ntfy_topic'], 'pushover' => ['po_app', 'po_user'],
		'email' => ['smtp_host', 'smtp_port', 'mail_from', 'mail_to'], 'webhook' => ['wh_url'],
	][$key] ?? [];
	foreach ($need as $f) if (($s[$f] ?? '') === '') return false;
	return true;
}

function tlb_any_channel_ready(array $s) {
	foreach (tlb_channels() as $k => $c) if (tlb_channel_ready($s, $k)) return true;
	return false;
}

// Send to every ready channel. Returns [channel => [ok, message]].
function tlb_notify(array $s, $event, $text) {
	$res = [];
	foreach (tlb_channels() as $k => $c) {
		if (tlb_channel_ready($s, $k)) $res[$k] = tlb_channel_send($s, $k, $event, $text);
	}
	return $res;
}

// Send to one channel regardless of its enabled flag (used by the test button).
function tlb_channel_send(array $s, $key, $event, $text) {
	$fn = 'tlb_send_'.$key;
	return function_exists($fn) ? $fn($s, $event, $text) : [false, 'Unknown channel.'];
}

// ---- transport helpers ----

// Returns [http status, body] or [0, ''] when unreachable.
function tlb_http_post($url, $body, array $headers = [], $timeout = 8) {
	$headers[] = 'User-Agent: TheLinkBox-Dashboard/1.0';
	$ctx = stream_context_create(['http' => [
		'method' => 'POST', 'header' => implode("\r\n", $headers)."\r\n", 'content' => $body,
		'timeout' => $timeout, 'ignore_errors' => true,
	]]);
	$r = @file_get_contents($url, false, $ctx);
	if ($r === false) return [0, ''];
	$code = 0;
	if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
	return [$code, $r];
}

function tlb_post_result($code, $body, $name) {
	if ($code === 0) return [false, "Couldn't reach $name."];
	if ($code >= 200 && $code < 300) return [true, 'Sent.'];
	return [false, "$name replied with HTTP $code."];
}

// ---- senders: each returns [ok, message] ----

function tlb_send_telegram(array $s, $event, $text) {
	list($c, $b) = tlb_http_post('https://api.telegram.org/bot'.$s['tg_token'].'/sendMessage',
		http_build_query(['chat_id' => $s['tg_chat_id'], 'text' => $text]),
		['Content-Type: application/x-www-form-urlencoded']);
	if ($c === 0) return [false, "Couldn't reach Telegram."];
	$j = json_decode($b, true);
	return !empty($j['ok']) ? [true, 'Sent.'] : [false, 'Telegram said: '.($j['description'] ?? "HTTP $c")];
}

function tlb_send_slack(array $s, $event, $text) {
	list($c, $b) = tlb_http_post($s['slack_url'], json_encode(['text' => $text]), ['Content-Type: application/json']);
	return tlb_post_result($c, $b, 'Slack');
}

function tlb_send_discord(array $s, $event, $text) {
	list($c, $b) = tlb_http_post($s['discord_url'], json_encode(['content' => $text]), ['Content-Type: application/json']);
	return tlb_post_result($c, $b, 'Discord');
}

function tlb_send_ntfy(array $s, $event, $text) {
	$lines = explode("\n", $text, 2);
	$h = ['Title: '.str_replace(["\r", "\n"], ' ', $lines[0]), 'Content-Type: text/plain; charset=utf-8'];
	if ($s['ntfy_token'] !== '') $h[] = 'Authorization: Bearer '.$s['ntfy_token'];
	list($c, $b) = tlb_http_post(rtrim($s['ntfy_server'], '/').'/'.$s['ntfy_topic'], $lines[1] ?? $lines[0], $h);
	return tlb_post_result($c, $b, 'ntfy');
}

function tlb_send_pushover(array $s, $event, $text) {
	$lines = explode("\n", $text, 2);
	list($c, $b) = tlb_http_post('https://api.pushover.net/1/messages.json',
		http_build_query(['token' => $s['po_app'], 'user' => $s['po_user'], 'title' => $lines[0], 'message' => $lines[1] ?? $lines[0]]),
		['Content-Type: application/x-www-form-urlencoded']);
	return tlb_post_result($c, $b, 'Pushover');
}

function tlb_send_webhook(array $s, $event, $text) {
	$h = ['Content-Type: application/json'];
	if ($s['wh_header'] !== '') $h[] = $s['wh_header'];
	list($c, $b) = tlb_http_post($s['wh_url'], json_encode(['event' => $event, 'server' => $s['server_label'], 'text' => $text]), $h);
	return tlb_post_result($c, $b, 'the webhook');
}

// Minimal SMTP client: implicit TLS (465), STARTTLS (587), or plain (25); optional AUTH LOGIN.
function tlb_send_email(array $s, $event, $text) {
	$lines   = explode("\n", $text, 2);
	$subject = str_replace(["\r", "\n"], ' ', $lines[0]);
	$body    = $lines[1] ?? $lines[0];
	$host    = $s['smtp_host'];
	$port    = (int)$s['smtp_port'];
	$sec     = $s['smtp_sec'];

	$fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port, $en, $es, 10);
	if (!$fp) return [false, "Couldn't connect to $host:$port."];
	stream_set_timeout($fp, 10);

	$read = function () use ($fp) {
		$r = '';
		while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
		return $r;
	};
	$step = function ($cmd, $ok) use ($fp, $read) {
		if ($cmd !== null) fwrite($fp, $cmd."\r\n");
		$r = $read();
		return in_array(substr($r, 0, 3), (array)$ok, true) ? [true, ''] : [false, 'SMTP: '.trim($r)];
	};
	$fail = function ($msg) use ($fp) { @fclose($fp); return [false, $msg]; };

	$me = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
	if (!(($r = $step(null, '220'))[0])) return $fail($r[1]);
	if (!(($r = $step("EHLO $me", '250'))[0])) return $fail($r[1]);
	if ($sec === 'tls') {
		if (!(($r = $step('STARTTLS', '220'))[0])) return $fail($r[1]);
		if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail("Couldn't start TLS.");
		if (!(($r = $step("EHLO $me", '250'))[0])) return $fail($r[1]);
	}
	if ($s['smtp_user'] !== '') {
		if (!(($r = $step('AUTH LOGIN', '334'))[0])) return $fail($r[1]);
		if (!(($r = $step(base64_encode($s['smtp_user']), '334'))[0])) return $fail($r[1]);
		if (!(($r = $step(base64_encode($s['smtp_pass']), '235'))[0])) return $fail('SMTP login failed.');
	}
	if (!(($r = $step('MAIL FROM:<'.$s['mail_from'].'>', '250'))[0])) return $fail($r[1]);
	if (!(($r = $step('RCPT TO:<'.$s['mail_to'].'>', ['250', '251']))[0])) return $fail($r[1]);
	if (!(($r = $step('DATA', '354'))[0])) return $fail($r[1]);

	$msg = 'From: '.$s['server_label'].' <'.$s['mail_from'].">\r\n"
		.'To: <'.$s['mail_to'].">\r\n"
		.'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n"
		.'Date: '.date('r')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
		.chunk_split(base64_encode($body), 76, "\r\n");
	fwrite($fp, $msg."\r\n.\r\n");
	$r = $step(null, '250');
	@fwrite($fp, "QUIT\r\n");
	@fclose($fp);
	return $r[0] ? [true, 'Sent.'] : [false, $r[1]];
}
