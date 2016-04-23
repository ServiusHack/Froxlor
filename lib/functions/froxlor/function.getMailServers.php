<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Functions
 *
 */

/**
 *
 * Get a list of mailservers to communicate for autoconfiguration.
 *
 * @return array
 */
function getMailServers() {
	$types = array(
		//    type  , port, ssl
		array('IMAP',  995, true),
		array('POP3',  993, true),
		array('SMTP',  465, true),
		array('SMTP',  587, false) // can still support STARTTLS
	);

	$servers = array();

	foreach ($types as $type)
		$servers[] = array(
			'hostname' => Settings::get('system.mail_domain'),
			'type' => $type[0],
			'port' => $type[1],
			'ssl' => $type[2]
		);
	return $servers;
}
