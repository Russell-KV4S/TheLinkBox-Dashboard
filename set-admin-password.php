<?php
// Run on the server as root:  php set-admin-password.php
// Prompts for the admin password (hidden) and writes the bcrypt hash to /etc/tlb-admin.php,
// readable by the web server only. Also creates the state/audit directory.

if (php_sapi_name() !== 'cli') exit(1);

$conf = '/etc/tlb-admin.php';
$dir  = '/var/lib/tlb-admin';

function ask($prompt) {
	echo $prompt;
	system('stty -echo');
	$v = trim(fgets(STDIN));
	system('stty echo');
	echo "\n";
	return $v;
}

$p1 = ask('New admin password: ');
if (strlen($p1) < 10) { fwrite(STDERR, "Use at least 10 characters.\n"); exit(1); }
$p2 = ask('Repeat password:    ');
if ($p1 !== $p2) { fwrite(STDERR, "Passwords do not match.\n"); exit(1); }

$hash = password_hash($p1, PASSWORD_DEFAULT);
file_put_contents($conf, "<?php\nreturn ".var_export(['password_hash' => $hash], true).";\n");
chown($conf, 'root');
chgrp($conf, 'www-data');
chmod($conf, 0640);

if (!is_dir($dir)) mkdir($dir, 0750, true);
chown($dir, 'www-data');
chgrp($dir, 'www-data');
chmod($dir, 0750);

echo "Saved $conf. Admin screen: https://irlp.kv4s.com/irlp/admin.php\n";
