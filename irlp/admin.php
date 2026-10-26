<?php
// TheLinkBox Dashboard - password-protected admin area.
//
// The password hash lives OUTSIDE the web root in /etc/tlb-admin.php (see set-admin-password.php).
// Settings (including channel tokens and passwords) are saved to /var/lib/tlb-admin/settings.json.
// Only the commands listed in $COMMANDS can be run, and arguments are validated against a strict
// pattern before they get anywhere near a shell.

require_once __DIR__.'/tlb-lib.php';

const CONFIG_FILE  = '/etc/tlb-admin.php';
const AUDIT_LOG    = '/var/lib/tlb-admin/audit.log';
const IDLE_SECS    = 1200;   // log out after 20 min idle
const MAX_SECS     = 28800;  // ...or 8 hours total
const MAX_FAILS    = 5;      // failed logins before lockout
const LOCKOUT_SECS = 900;    // 15 min lockout
const HEARD_DAYS   = [1 => '1 day', 3 => '3 days', 7 => '7 days', 14 => '14 days', 30 => '30 days'];

// label, tlbcmd text, arg pattern (null = no argument), needs confirm, group (info | node | list)
$NODE = '/^[A-Za-z0-9][A-Za-z0-9-]{2,15}$/';
$CALL = '/^[A-Za-z0-9][A-Za-z0-9-]{1,15}$/';
$COMMANDS = [
	'version'    => ['Version',    'version',      null,  false, 'info'],
	'users'      => ['Users',      'users',        null,  false, 'info'],
	'uptime'     => ['Uptime',     'uptime',       null,  false, 'info'],
	'disconnect' => ['Disconnect', 'disconnect',   $NODE, true,  'node'],
	'connect'    => ['Connect',    'connect',      $NODE, true,  'node'],
	'monitor'    => ['Monitor',    'monitor',      $NODE, true,  'node'],
	'ban_add'    => ['Ban',        'ban add',      $CALL, true,  'list'],
	'ban_del'    => ['Remove',     'ban delete',   $CALL, false, 'list'],
	'allow_add'  => ['Allow',      'allow add',    $CALL, false, 'list'],
	'allow_del'  => ['Remove',     'allow delete', $CALL, false, 'list'],
];
$SECTIONS = ['overview' => 'Overview', 'commands' => 'Commands', 'allow' => 'Allow list', 'ban' => 'Ban list', 'allstar' => 'AllStar', 'alerts' => 'Settings', 'activity' => 'Activity'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '?'; }

function audit($msg) {
	@file_put_contents(AUDIT_LOG, date('c').' '.client_ip().' '.str_replace(["\r", "\n"], ' ', $msg)."\n", FILE_APPEND | LOCK_EX);
}

// Alert for admin activity, sent to every enabled channel if "admin" alerts are on.
function notify_admin($text) {
	$s = tlb_load_settings();
	if ($s['on_admin'] && tlb_any_channel_ready($s)) tlb_notify($s, 'admin', $s['server_label']." · admin\n".$text."\nFrom ".client_ip());
}

function lock_file() { return TLB_STATE_DIR.'/fail-'.sha1(client_ip()).'.json'; }

function lockout_remaining() {
	$d = @json_decode(@file_get_contents(lock_file()), true);
	if (!$d || $d['count'] < MAX_FAILS) return 0;
	return max(0, $d['last'] + LOCKOUT_SECS - time());
}

function record_failure() {
	$d = @json_decode(@file_get_contents(lock_file()), true);
	if (!$d || time() - $d['last'] > LOCKOUT_SECS) $d = ['count' => 0, 'last' => 0];
	$d['count']++;
	$d['last'] = time();
	@file_put_contents(lock_file(), json_encode($d), LOCK_EX);
}

function clear_failures() { @unlink(lock_file()); }

// ---- HTTPS only ----
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
	header('Location: https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'], true, 301);
	exit;
}

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// inline style attributes are allowed (layout only); scripts stay blocked entirely
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');

session_name('tlbadmin');
session_set_cookie_params([
	'lifetime' => 0,
	'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/', // "/irlp/", or "/" at the web root
	'secure'   => true,
	'httponly' => true,
	'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

$config = is_readable(CONFIG_FILE) ? include CONFIG_FILE : null;
$hash   = is_array($config) ? ($config['password_hash'] ?? '') : '';
$error  = '';
$flash  = '';
$output = null;
$title  = '';
$confirm = null;

// ---- session expiry ----
if (!empty($_SESSION['auth'])) {
	if (time() - $_SESSION['last'] > IDLE_SECS || time() - $_SESSION['start'] > MAX_SECS) {
		session_unset();
		session_regenerate_id(true);
		$error = 'Your session expired. Log in again.';
	} else {
		$_SESSION['last'] = time();
	}
}

function csrf_ok() {
	return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

$do = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['do'] ?? '') : '';

// ---- login / logout ----
if ($do === 'login') {
	$wait = lockout_remaining();
	if ($hash === '') {
		$error = 'No admin password has been set on this server yet.';
	} elseif ($wait > 0) {
		$error = 'Too many failed attempts. Try again in '.ceil($wait / 60).' minute(s).';
	} elseif (password_verify($_POST['password'] ?? '', $hash)) {
		clear_failures();
		session_regenerate_id(true);
		$_SESSION = ['auth' => true, 'start' => time(), 'last' => time(), 'csrf' => bin2hex(random_bytes(32))];
		audit('LOGIN ok');
		notify_admin('Admin logged in');
		header('Location: '.$_SERVER['SCRIPT_NAME']);
		exit;
	} else {
		record_failure();
		audit('LOGIN failed');
		notify_admin('Failed admin login attempt');
		usleep(500000);
		$error = 'That password is incorrect.';
	}
}

if ($do === 'logout' && !empty($_SESSION['auth']) && csrf_ok()) {
	audit('LOGOUT');
	session_unset();
	session_destroy();
	header('Location: '.$_SERVER['SCRIPT_NAME']);
	exit;
}

$authed  = !empty($_SESSION['auth']);
$section = $_GET['s'] ?? 'overview';
if (!isset($SECTIONS[$section])) $section = 'overview';

// ---- authenticated actions ----
if ($authed && $do !== '' && $do !== 'login' && $do !== 'logout') {
	if (!csrf_ok()) {
		$error = 'Your security token was missing or out of date. Reload the page and try again.';
	} elseif ($do === 'cmd') {
		$section = 'commands';
		$key = $_POST['cmd'] ?? '';
		$arg = trim($_POST['arg'] ?? '');
		if ($arg === '') $arg = trim($_POST['arg_pick'] ?? '');
		if (!isset($COMMANDS[$key])) {
			$error = 'Unknown command.';
		} else {
			list($label, $text, $pattern, $changes, $group) = $COMMANDS[$key];
			if ($group === 'list') {
				$section = strpos($key, 'ban') === 0 ? 'ban' : 'allow';
				$arg = preg_replace('/-[LR]$/i', '', $arg); // lists use the base callsign
			}
			if ($pattern !== null && !preg_match($pattern, $arg)) {
				$error = $group === 'list' ? 'Enter a valid callsign, like N4RGJ.' : 'Enter a valid node, like stn8087 or N4RGJ-R.';
			} elseif ($changes && empty($_POST['confirmed'])) {
				$confirm = [$key, $label, $arg];
			} else {
				$full   = $pattern === null ? $text : $text.' '.$arg;
				$output = tlb_cmd($full);
				$title  = $full;
				audit('CMD '.$full);
				if ($pattern !== null) tlb_recent_add($arg);
				if ($pattern !== null) notify_admin('Ran: '.$full);
			}
		}
	} elseif ($do === 'aslsettings') {
		$section = 'allstar';
		$s = tlb_load_settings();
		$node = trim($_POST['asl_node'] ?? '');
		$base = trim($_POST['asl_live_base'] ?? '');
		if ($node !== '' && !preg_match('/^\d{3,7}$/', $node)) {
			$error = 'The node number should be 3 to 7 digits, like 523800.';
		} elseif ($base !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $base)) {
			$error = 'The live feed address should look like https://example.org/allmon3/';
		} else {
			$s['asl_node'] = $node;
			$s['asl_live_base'] = $base;
			if (!tlb_save_settings($s)) {
				$error = 'Couldn\'t save settings. Check that /var/lib/tlb-admin is writable by the web server.';
			} else {
				audit('SETTINGS allstar saved');
				$flash = 'AllStar settings saved.';
				if (!empty($_POST['test']) && $node !== '') {
					$GLOBALS['tlb_cache_bypass'] = true;
					$snap = tlb_asl_snapshot($s);
					if (!empty($snap['error'])) {
						$error = 'Saved, but '.$snap['error'];
					} else {
						$flash = 'Saved. Node '.$node.' found on AllStarLink with '.count($snap['links']).' link(s).';
						if ($base !== '') {
							if (!empty($snap['live']['ws'])) $flash .= ' The live feed was found.';
							else $error = 'Saved, but the live feed failed: '.($snap['live']['error'] ?? 'unknown problem');
						}
					}
				}
			}
		}
	} elseif ($do === 'heardclear') {
		$section = 'alerts';
		if (empty($_POST['sure'])) {
			$error = 'Tick the box to confirm, then press Clear the log.';
		} else {
			tlb_heard_clear();
			audit('HEARD log cleared');
			notify_admin('Cleared the Last heard log');
			$flash = 'The Last heard log was cleared.';
		}
	} elseif ($do === 'settings') {
		$section = 'alerts';
		$s = tlb_load_settings();
		$label = trim(preg_replace('/[\x00-\x1f]/', '', $_POST['server_label'] ?? ''));
		$new = [];
		if (strlen($label) > 40) $error = 'Server name is limited to 40 characters.';
		foreach (tlb_channels() as $ck => $ch) {
			if ($error) break;
			foreach ($ch['fields'] as $f) {
				list($fk, $flabel, $type, $pat) = $f;
				$v = trim($_POST[$fk] ?? '');
				if ($type === 'select') { $new[$fk] = isset(tlb_select_options($fk)[$v]) ? $v : $s[$fk]; continue; }
				if ($type === 'secret') {
					if (!empty($_POST['clear_'.$fk])) { $new[$fk] = ''; continue; }
					if ($v === '') { $new[$fk] = $s[$fk]; continue; }
				}
				if ($v !== '' && $pat && !preg_match($pat, $v)) { $error = $ch['label'].': "'.$flabel.'" doesn\'t look right.'; break 2; }
				if ($fk === 'smtp_port' && $v !== '' && ((int)$v < 1 || (int)$v > 65535)) { $error = 'Email: the port must be 1 to 65535.'; break 2; }
				$new[$fk] = $v;
			}
			$new['ch_'.$ck] = !empty($_POST['ch_'.$ck]);
		}
		if (!$error) {
			$s = $new + $s;
			$s['server_label'] = $label !== '' ? $label : 'TheLinkBox';
			foreach (['on_connect', 'on_disconnect', 'on_admin', 'skip_lurkers', 'skip_permanent', 'viewer_alerts', 'heard_public', 'server_stats'] as $k) $s[$k] = !empty($_POST[$k]);
			$furl  = trim($_POST['footer_url'] ?? '');
			$ftext = trim(preg_replace('/[\x00-\x1f]/', '', $_POST['footer_text'] ?? ''));
			if ($furl !== '' && (!preg_match('#^https?://[^\s<>"\']+$#i', $furl) || strlen($furl) > 300)) {
				$error = 'The footer link should start with http:// or https://';
			} elseif (strlen($ftext) > 80) {
				$error = 'The footer link wording is too long. Keep it to about 40 characters.';
			} else {
				$s['footer_url'] = $furl;
				$s['footer_text'] = $ftext;
			}
			if (array_key_exists((int)($_POST['heard_days'] ?? 0), HEARD_DAYS)) $s['heard_days'] = (int)$_POST['heard_days'];
			if ($error) {
				// nothing saved
			} elseif (!tlb_save_settings($s)) {
				$error = 'Couldn\'t save settings. Check that /var/lib/tlb-admin is writable by the web server.';
			} else {
				audit('SETTINGS saved');
				$flash = 'Settings saved.';
				$test = $_POST['test'] ?? '';
				if (isset(tlb_channels()[$test])) {
					list($ok, $msg) = tlb_channel_send($s, $test, 'test', $s['server_label']." · test\nAlerts are working.");
					audit('ALERT test '.$test.' '.($ok ? 'ok' : 'failed'));
					if ($ok) $flash = 'Settings saved. Test sent to '.tlb_channels()[$test]['label'].'.';
					else $error = 'Settings saved, but the test failed: '.$msg;
				}
			}
		}
	}
}

$settings = tlb_load_settings();
$csrf = $authed ? $_SESSION['csrf'] : '';

function show_result($confirm, $output, $title, $csrf, $section) {
	if ($confirm) {
		list($ckey, $clabel, $carg) = $confirm;
		echo '<form method="post" class="card" style="border-color:var(--danger)"><h3>Confirm</h3><p style="margin:0 0 12px">'.h($clabel).' <b>'.h($carg).'</b>?'
			.($ckey === 'ban_add' ? ' This also disconnects them if they are connected.' : '').'</p>'
			.'<input type="hidden" name="do" value="cmd"><input type="hidden" name="cmd" value="'.h($ckey).'"><input type="hidden" name="arg" value="'.h($carg).'"><input type="hidden" name="confirmed" value="1">';
		field_csrf($csrf);
		echo '<div class="btns"><button class="danger" type="submit">Yes, '.h(strtolower($clabel)).'</button><a class="btn" href="?s='.h($section).'" style="display:inline-flex;align-items:center">Cancel</a></div></form>';
	}
	if ($output !== null) echo '<div class="card"><h3>tlbcmd '.h($title).'</h3><pre class="out">'.h(trim($output) === '' ? '(no output)' : trim($output)).'</pre></div>';
}

// "Recent nodes" dropdown plus a text box; what is typed wins over what is picked.
function node_picker($label) {
	$recent = tlb_recent_list();
	if ($recent) {
		echo '<label class="fld">Recent nodes<select name="arg_pick"><option value="">Pick a recent node&hellip;</option>';
		foreach ($recent as $rn => $rt) echo '<option value="'.h($rn).'">'.h($rn).'</option>';
		echo '</select></label>';
	}
	echo '<label class="fld">'.h($label).'<input type="text" name="arg" maxlength="16" autocapitalize="off" autocorrect="off"></label>';
}

function field_csrf($csrf) { echo '<input type="hidden" name="csrf" value="'.h($csrf).'">'; }
function sw($name, $label, $on) {
	echo '<label class="set"><span>'.h($label).'</span><input class="sw" type="checkbox" name="'.h($name).'" value="1"'.($on ? ' checked' : '').'></label>';
}

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>Admin · <?= h($settings['server_label']) ?></title>
	<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
	<div class="top">
		<div class="brand"><span>Admin <span class="sub"><?= h($settings['server_label']) ?></span></span></div>
		<div style="display:flex;gap:8px;align-items:center">
			<a class="pill" href="index.php">Dashboard</a>
			<?php if ($authed): ?>
			<form method="post" style="margin:0"><input type="hidden" name="do" value="logout"><?php field_csrf($csrf); ?><button class="pill" type="submit" style="min-height:36px;padding:0 14px">Log out</button></form>
			<?php endif; ?>
		</div>
	</div>

<?php if ($flash): ?><div class="msg ok"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

<?php if (!$authed): ?>
	<form method="post" class="card login" autocomplete="off">
		<h2>Log in</h2>
		<input type="hidden" name="do" value="login">
		<label class="fld">Password<input type="password" name="password" autofocus required></label>
		<button class="primary" type="submit" style="width:100%">Log in</button>
	</form>
<?php else: ?>
<div class="shell">
	<nav class="nav">
		<?php foreach ($SECTIONS as $k => $v) echo '<a href="?s='.h($k).'"'.($k === $section ? ' class="on"' : '').'>'.h($v).'</a>'; ?>
	</nav>
	<main>
<?php if (in_array($section, ['commands', 'allow', 'ban'], true)) show_result($confirm, $output, $title, $csrf, $section); ?>

<?php if ($section === 'overview'):
	$users = tlb_users();
	$visible = 0; foreach ($users as $u) if (strpos($u['attrs'], 'L') === false) $visible++; ?>
		<div class="stats" style="grid-template-columns:repeat(2,1fr)">
			<div class="stat"><small>Connected</small><b><?= $visible ?></b></div>
			<div class="stat"><small>Alerts</small><b style="font-size:16px"><?= tlb_any_channel_ready($settings) ? 'On' : 'Off' ?></b></div>
		</div>
		<div class="card"><h3>Connected now</h3>
			<pre class="out"><?= h(tlb_cmd('users') ?: 'Nobody is connected.') ?></pre></div>
		<div class="card"><h3>Server</h3>
			<pre class="out"><?= h(tlb_cmd('uptime')."\n".tlb_cmd('version')) ?></pre></div>

<?php elseif ($section === 'commands'): ?>
		<div class="card"><h3>Information</h3>
			<div class="cmdgrid">
			<?php foreach ($COMMANDS as $k => $c) { if ($c[4] !== 'info') continue; ?>
				<form method="post"><input type="hidden" name="do" value="cmd"><input type="hidden" name="cmd" value="<?= h($k) ?>"><?php field_csrf($csrf); ?><button type="submit"><?= h($c[0]) ?></button></form>
			<?php } ?>
			</div></div>
		<?php $now = tlb_users(); $protos = $now ? tlb_protocols() : []; ?>
		<div class="card" style="padding:0;overflow:hidden">
			<h3 style="padding:14px 16px 4px">Connected now</h3>
			<?php if (!$now): ?><div class="empty">Nobody is connected.</div><?php endif;
			foreach ($now as $n => $u): $p = $protos[$n] ?? ['proto' => 'Unknown', 'ip' => '']; ?>
			<div class="row adm">
				<span class="av <?= h(['IRLP' => 'p-irlp', 'EchoLink' => 'p-echolink', 'RTP' => 'p-rtp'][$p['proto']] ?? 'p-other') ?>"><?= h(strtoupper(substr($p['proto'], 0, 2))) ?></span>
				<div><b><?= h($n) ?></b><div class="sub"><?= h($p['proto']) ?> &middot; <?= h($u['time']) ?><?= strpos($u['attrs'], 'L') !== false ? ' &middot; lurker' : '' ?></div></div>
				<div class="btns">
					<form method="post"><input type="hidden" name="do" value="cmd"><input type="hidden" name="cmd" value="disconnect"><input type="hidden" name="arg" value="<?= h($n) ?>"><?php field_csrf($csrf); ?><button type="submit">Disconnect</button></form>
					<form method="post"><input type="hidden" name="do" value="cmd"><input type="hidden" name="cmd" value="ban_add"><input type="hidden" name="arg" value="<?= h($n) ?>"><?php field_csrf($csrf); ?><button type="submit">Ban</button></form>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="card"><h3>Node control</h3>
			<form method="post" autocomplete="off">
				<input type="hidden" name="do" value="cmd"><?php field_csrf($csrf); ?>
				<?php node_picker('Or type or paste a node, like stn8087 or N4RGJ-R'); ?>
				<div class="btns">
				<?php foreach ($COMMANDS as $k => $c) { if ($c[4] !== 'node') continue; ?>
					<button type="submit" name="cmd" value="<?= h($k) ?>"><?= h($c[0]) ?></button>
				<?php } ?>
				</div>
			</form></div>

<?php elseif ($section === 'ban' || $section === 'allow'):
	$isBan = $section === 'ban'; $word = $section; $entries = tlb_acl_entries($word); ?>
		<div class="card" style="padding:0;overflow:hidden">
			<h3 style="padding:14px 16px 4px"><?= $isBan ? 'Ban list' : 'Allow list' ?></h3>
			<?php if (!$entries): ?><div class="empty">The <?= $isBan ? 'ban' : 'allow' ?> list is empty.</div><?php endif;
			foreach ($entries as $e): ?>
			<div class="row adm" style="grid-template-columns:1fr auto">
				<div><b><?= h($e[0]) ?></b><?php if ($e[1] !== ''): ?><div class="sub"><?= h($e[1]) ?></div><?php endif; ?></div>
				<form method="post"><input type="hidden" name="do" value="cmd"><input type="hidden" name="cmd" value="<?= h($word) ?>_del"><input type="hidden" name="arg" value="<?= h($e[0]) ?>"><?php field_csrf($csrf); ?><button type="submit">Remove</button></form>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="card"><h3>Add to the <?= $isBan ? 'ban' : 'allow' ?> list</h3>
			<form method="post" autocomplete="off">
				<input type="hidden" name="do" value="cmd"><?php field_csrf($csrf); ?>
				<?php node_picker('Or type or paste a callsign'); ?>
				<div class="btns"><button class="<?= $isBan ? 'danger' : 'primary' ?>" type="submit" name="cmd" value="<?= h($word) ?>_add"><?= $isBan ? 'Ban' : 'Allow' ?></button></div>
			</form>
			<p class="sub">The -L and -R endings on EchoLink calls are removed for you.<?= $isBan ? ' Banning also disconnects the station if it is connected.' : '' ?></p>
		</div>

<?php elseif ($section === 'allstar'): ?>
		<form method="post" autocomplete="off">
			<input type="hidden" name="do" value="aslsettings"><?php field_csrf($csrf); ?>
			<div class="card"><h3>AllStar node</h3>
				<label class="fld">Node number<input type="text" name="asl_node" inputmode="numeric" maxlength="7" value="<?= h($settings['asl_node']) ?>"></label>
				<p class="sub"><?= $settings['asl_node'] !== '' ? 'Saved: node '.h($settings['asl_node']).'.' : 'Not set yet.' ?> The node's links show on the main page under the TheLinkBox list, with the live diagram and the AllStar network map. Leave it empty to hide that section.</p>
			</div>
			<div class="card"><h3>Real-time feed (optional)</h3>
				<label class="fld">Allmon3 address<input type="text" name="asl_live_base" value="<?= h($settings['asl_live_base']) ?>" placeholder="https://example.org/allmon3/" autocapitalize="off" autocorrect="off"></label>
				<p class="sub">Without this, the list comes from AllStarLink's public stats and updates about once a minute. With the web address of your node's Allmon3 dashboard, the list is live and shows who is talking.</p>
			</div>
			<div class="btns"><button class="primary" type="submit">Save</button><button type="submit" name="test" value="1">Save and test</button></div>
		</form>

<?php elseif ($section === 'alerts'): ?>
		<form method="post" autocomplete="off">
			<input type="hidden" name="do" value="settings"><?php field_csrf($csrf); ?>
			<div class="card"><h3>Browser alerts for visitors</h3>
				<?php sw('viewer_alerts', 'Show the Alerts button on the main page', $settings['viewer_alerts']); ?>
				<p class="sub">Lets anyone viewing the dashboard turn on a chime and phone or desktop notifications in their own browser. Turn it off to remove the button for everyone.</p>
			</div>
			<div class="card"><h3>Main page</h3>
				<?php sw('heard_public', 'Show "Last heard" and the 24-hour timeline', $settings['heard_public']);
				sw('server_stats', 'Show server health (CPU, memory, disk, network)', $settings['server_stats']); ?>
				<label class="fld" style="margin-top:12px">Footer link address<input type="text" name="footer_url" value="<?= h($settings['footer_url']) ?>" placeholder="https://example.org" autocapitalize="off" autocorrect="off"></label>
				<label class="fld">Footer link wording<input type="text" name="footer_text" maxlength="40" value="<?= h($settings['footer_text']) ?>" placeholder="Our club website"></label>
				<p class="sub">Shows as a link at the bottom of the main page, next to the IRLP experimental reflectors link. Leave the address empty to hide it.</p>
				<label class="fld" style="margin-top:12px">Keep the Last heard log for
					<select name="heard_days"><?php foreach (HEARD_DAYS as $dv => $dl) echo '<option value="'.$dv.'"'.((int)$settings['heard_days'] === $dv ? ' selected' : '').'>'.h($dl).'</option>'; ?></select></label>
				<p class="sub">Who talked and for how long is logged by the <code>tlb-notify</code> service, even while the section is hidden. Talk that comes in over the AllStar link is credited to the AllStar station that keyed up when the Allmon3 address is set on the AllStar page.</p>
			</div>
			<div class="card"><h3>Send me an alert when</h3>
				<?php sw('on_connect', 'A node connects', $settings['on_connect']);
				sw('on_disconnect', 'A node disconnects', $settings['on_disconnect']);
				sw('on_admin', 'Someone uses the admin area', $settings['on_admin']);
				sw('skip_lurkers', 'Skip lurkers', $settings['skip_lurkers']);
				sw('skip_permanent', 'Skip permanent links', $settings['skip_permanent']); ?>
				<label class="fld" style="margin-top:12px">Server name in alerts<input type="text" name="server_label" maxlength="40" value="<?= h($settings['server_label']) ?>"></label>
			</div>
			<?php foreach (tlb_channels() as $ck => $ch):
				$on = !empty($settings['ch_'.$ck]); $ready = tlb_channel_ready($settings, $ck); ?>
			<details class="card chan"<?= $on ? ' open' : '' ?>>
				<summary><span><?= h($ch['label']) ?></span>
					<?php if ($on && $ready): ?><span class="chip p-echolink">On</span>
					<?php elseif ($on): ?><span class="chip p-rtp">Needs setup</span>
					<?php else: ?><span class="sub">Off</span><?php endif; ?></summary>
				<?php sw('ch_'.$ck, 'Send alerts to '.$ch['label'], $on);
				foreach ($ch['fields'] as $f) { list($fk, $flabel, $type, $pat, $hint) = $f;
					$saved = $type === 'secret' && $settings[$fk] !== ''; ?>
				<label class="fld" style="margin-top:10px"><?= h($flabel) ?><?= $saved ? ' (saved, ends …'.h(substr($settings[$fk], -4)).')' : '' ?>
				<?php if ($type === 'select'): ?>
					<select name="<?= h($fk) ?>"><?php foreach (tlb_select_options($fk) as $ov => $ol) echo '<option value="'.h($ov).'"'.($settings[$fk] === $ov ? ' selected' : '').'>'.h($ol).'</option>'; ?></select>
				<?php elseif ($type === 'secret'): ?>
					<input type="password" name="<?= h($fk) ?>" autocomplete="new-password" placeholder="<?= $saved ? 'Leave blank to keep the saved value' : h($hint) ?>">
				<?php else: ?>
					<input type="text" name="<?= h($fk) ?>" value="<?= h($settings[$fk]) ?>" placeholder="<?= h($hint) ?>" autocapitalize="off" autocorrect="off">
				<?php endif; ?></label>
				<?php if ($saved) sw('clear_'.$fk, 'Remove the saved '.strtolower($flabel), false); } ?>
				<div class="btns" style="margin-top:12px"><button type="submit" name="test" value="<?= h($ck) ?>">Save and send test</button></div>
			</details>
			<?php endforeach; ?>
			<div class="btns"><button class="primary" type="submit">Save settings</button></div>
		</form>
		<p class="sub">Alerts are sent by the <code>tlb-notify</code> service. Settings apply within a couple of seconds, no restart needed.</p>
		<form method="post" class="card" style="margin-top:12px">
			<input type="hidden" name="do" value="heardclear"><?php field_csrf($csrf); ?>
			<h3>Clear the Last heard log</h3>
			<?php sw('sure', 'Yes, delete every logged over', false); ?>
			<div class="btns" style="margin-top:8px"><button class="danger" type="submit">Clear the log</button></div>
		</form>

<?php else: ?>
		<div class="card"><h3>Recent activity</h3>
			<div class="log"><?php
				$lines = @file(AUDIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
				$lines = array_reverse(array_slice($lines, -40));
				echo $lines ? implode('<br>', array_map('h', $lines)) : 'Nothing yet.';
			?></div></div>
<?php endif; ?>

	</main>
</div>
<?php endif; ?>
</div>
</body>
</html>
