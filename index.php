<?php
$page = $_SERVER['PHP_SELF'];
$sec = "5";
?>
<html>
 <head>
  <meta http-equiv="refresh" content="<?php echo $sec?>">
  <title>TheLinkBox Dashboard</title>
 </head>
 <body>
	<center><h1><?php echo 'TheLinkBox Dashboard'; ?> </h1></center>
	<center><h3 style="color:DodgerBlue;">
		<?php
			$output = shell_exec('tlbcmd users');
			$output = substr($output, 51, $output-1);
			$output = "Connected Nodes:\r\n" .$output;
			echo "<pre>$output</pre>";
		?>
	</center></h3>
	<center><h3 style="color:MediumSeaGreen;">
		<?php
			$output = shell_exec('tlbcmd uptime');
			$output = substr($output, 51, $output-1);
			$output = str_replace("Uptime", "Server Uptime", $output);
			echo "<pre>$output</pre>";
		?>
	</center></h3>
	<center>
		<br>
		<p>___________________________________________________________________</p>
		<table>
			<tr>
				<th>
					<a href="http://experimental.irlp.net" target="_blank">IRLP Experimental Reflector Page</a>
				</th>
			</tr>
		</table>
		<br>
		<p>Make sure you have the following statement in the environment file of your IRLP node computer.</p>
		<table>
		<tr>
			<th>
				export ALLOW_EXPERIMENTAL_NODES=YES
			</th>
		</tr>
		</table
	</center>
	<br>
	<p>The meaning of the connected node's attribute characters are as follows:</p>

	<table border="1" style="border-collapse:collapse">
		<tr><th>A</th><td>logged in as an administrator.</td></tr>
		<tr><th>a</th><td>ADPCM CODEC</td></tr>
		<tr><th>B</th><td>a linked theBridge conference.</td></tr>
		<tr><th>C</th><td>a linked conference other than theBridge.</td></tr>
		<tr><th>c</th><td>chat text suppressed</td></tr>
		<tr><th>F</th><td>playing a file (using the .play or .test commands).</td></tr>
		<tr><th>f</th><td>Full duplex connection</td></tr>
		<tr><th>K</th><td>Kicked.</td></tr>
		<tr><th>L</th><td>lurker.</td></tr>
		<tr><th>M</th><td>audio and text are muted.</td></tr>
		<tr><th>m</th><td>audio is muted.</td></tr>
		<tr><th>P</th><td>a permanent connection (connected by a .connect command).</td></tr>
		<tr><th>R</th><td>receive only (monitored).</td></tr>
		<tr><th>S</th><td>logged in as a sysop.</td></tr>
		<tr><th>T</th><td>currently Talking.</td></tr>
		<tr><th>u</th><td>uLaw CODEC</td></tr>
		<tr><th>x</th><td>not currently active in the conference.</td></tr>
		<tr><th>*</th><td>using Asterisk protocol.</td></tr>
		<tr><th>0</th><td>using Speak Freely protocol.</td></tr>
		<tr><th>1</th><td>using RTP protocol.</td></tr>
		<tr><th>!</th><td>an old version of theBridge which sent SDES packets containing a private "txt" extension field.</td></tr>
	</table>
 </body>
</html>
