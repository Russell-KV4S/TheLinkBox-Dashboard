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
 </body>
</html>
