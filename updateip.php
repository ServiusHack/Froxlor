<?php

define('FROXLOR_INSTALL_DIR', dirname(__FILE__));

/**
 * Includes the Usersettings eg. MySQL-Username/Passwort etc.
 */
require FROXLOR_INSTALL_DIR.'/lib/userdata.inc.php';

/**
 * Includes the Functions
 */
require FROXLOR_INSTALL_DIR.'/lib/functions.php';

/**
 * Includes the MySQL-Tabledefinitions etc.
 */
require FROXLOR_INSTALL_DIR.'/lib/tables.inc.php';

/**
 * Require authentication.
 */
if (!isset($_SERVER['PHP_AUTH_USER']) && !isset($_REQUEST['username']) && !isset($_REQUEST['password'])) {
	header('WWW-Authenticate: Basic realm="Dynamic IP Update"');
	header('HTTP/1.1 401 Unauthorized');
	header('Content-Type: text/plain');
	echo 'No username and password parameter set, HTTP authentication required.';
	exit;
}

/**
 * Get username and password.
 */
if (isset($_SERVER['PHP_AUTH_USER'])) {
	$loginname = validate($_SERVER['PHP_AUTH_USER'], 'loginname');
	$password = validate($_SERVER['PHP_AUTH_PW'], 'password');
}
else {
	$loginname = validate($_REQUEST['username'], 'loginname');
	$password = validate($_REQUEST['password'], 'password');
}

/**
 * Retreive user information.
 */
$userinfo_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_CUSTOMERS . "`
	WHERE `loginname`= :loginname"
);
Database::pexecute($userinfo_stmt, array("loginname" => $loginname));
$userinfo = $userinfo_stmt->fetch(PDO::FETCH_ASSOC);

/**
 * Validate password.
 */
if (!validatePasswordLogin($userinfo, $password)) {
	header('HTTP/1.1 403 Forbidden');
	header('Content-Type: text/plain');
	echo 'badauth';
	exit;
}

/**
 * Get domain.
 */
if (!isset($_REQUEST['domain'])) {
	header('HTTP/1.1 400 Bad Request');
	header('Content-Type: text/plain');
	echo 'nohost';
	exit;
}

$domain = $_REQUEST['domain'];

/**
 * Check if domain is dynamically updatable.
 */
$domain_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_DOMAINS . "`
	WHERE `domain`= :domain AND `customerid` = :customerid"
);
Database::pexecute($domain_stmt, array("domain" => $domain, "customerid" => $userinfo["customerid"]));

if ($domain_stmt->rowCount() == 0) {
	header('HTTP/1.1 400 Bad Request');
	header('Content-Type: text/plain');
	echo 'nohost';
	exit;
}

$domaininfo = $domain_stmt->fetch(PDO::FETCH_ASSOC);

if (!$domaininfo['isdynamicdomain']) {
	header('HTTP/1.1 400 Bad Request');
	header('Content-Type: text/plain');
	echo 'nohost';
	exit;
}


/**
 * Get IP addresses.
 */
$ipv4 = null;
$ipv6 = null;

if (isset($_REQUEST['ipv4'])) {
	$ipv4 = $_REQUEST['ipv4'];
	if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		header('HTTP/1.1 400 Bad Request');
		header('Content-Type: text/plain');
		echo 'Invalid IPv4.';
		exit;
	}
}
if (isset($_REQUEST['ipv6'])) {
	$ipv6 = $_REQUEST['ipv6'];
	if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		header('HTTP/1.1 400 Bad Request');
		header('Content-Type: text/plain');
		echo 'Invalid IPv6.';
		exit;
	}
}

if (isset($_REQUEST['detect'])) {
	// No ip provided, detect IP.

	$ip = $_SERVER['REMOTE_ADDR'];
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		$ipv4 = $ip;
	else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		$ipv6 = $ip;
	else {
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain');
		echo 'Unable to detect IP. It must be provided.';
		exit;
	}
}

$update_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "`
	SET `dynamicipv4` = :dynamicipv4,
	`dynamicipv6` = :dynamicipv6
	WHERE `domain` = :domain"
);
Database::pexecute($update_stmt, array("dynamicipv4" => $ipv4, "dynamicipv6" => $ipv6, "domain" => $domain));

$changed = false;

/**
 * Build response.
 */
header('HTTP/1.1 200 OK');
header('Content-Type: text/plain');
$messages = array();
if ($ipv4 == $domaininfo['dynamicipv4']) {
	$messages[] = "nochg $ipv4";
}
else {
	$messages[] = "good $ipv4";
	$changed = true;
}

if ($ipv6 == $domaininfo['dynamicipv6']) {
	$messages[] ="nochg $ipv6";
}
else {
	$messages[] ="good $ipv6";
	$changed = true;
}
echo implode($messages, "\n");

if ($changed)
{
	/**
	 * Insert a task which rebuilds the server config.
	 */
	inserttask('4');
}
?>
