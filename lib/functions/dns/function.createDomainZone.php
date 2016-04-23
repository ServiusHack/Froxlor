<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2016 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Froxlor team <team@froxlor.org> (2016-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Functions
 *
 */
function createDomainZone($domain_id, $froxlorhostname = false, $isMainButSubTo = false)
{
	if (!$froxlorhostname)
	{
		// get domain-name
		$dom_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_DOMAINS . "` WHERE id = :did");
		$domain = Database::pexecute_first($dom_stmt, array(
			'did' => $domain_id
		));
	}
	else
	{
		$domain = $domain_id;
	}

	if ($domain['isbinddomain'] != '1') {
		return;
	}

	$dom_entries = array();
	if (!$froxlorhostname)
	{
		// select all entries
		$sel_stmt = Database::prepare("SELECT * FROM `" . TABLE_DOMAIN_DNS . "` WHERE domain_id = :did ORDER BY id ASC");
		Database::pexecute($sel_stmt, array(
			'did' => $domain_id
		));
		$dom_entries = $sel_stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// check for required records
	$required_entries = array();

	$dynamicIPv4 = ($domain['isdynamicdomain'] && $domain['dynamicipv4'] != null) ? $domain['dynamicipv4'] : null;
	$dynamicIPv6 = ($domain['isdynamicdomain'] && $domain['dynamicipv6'] != null) ? $domain['dynamicipv6'] : null;
	addRequiredEntry('@', 'A', $required_entries, $dynamicIPv4);
	addRequiredEntry('@', 'AAAA', $required_entries, $dynamicIPv6);
	if (! $isMainButSubTo) {
		addRequiredEntry('@', 'NS', $required_entries);
	}
	if ($domain['isemaildomain'] === '1') {
		addRequiredEntry('@', 'MX', $required_entries);
		if (Settings::Get('system.dns_createmailentry')) {
			foreach(array('imap', 'pop3', 'mail', 'smtp') as $record) {
				foreach(array('AAAA', 'A') as $type) {
					addRequiredEntry($record, $type, $required_entries);
				}
			}
		}
	}

	// additional required records by setting
	if ($domain['iswildcarddomain'] == '1') {
		addRequiredEntry('*', 'A', $required_entries);
		addRequiredEntry('*', 'AAAA', $required_entries);
	} elseif ($domain['wwwserveralias'] == '1') {
		addRequiredEntry('www', 'A', $required_entries);
		addRequiredEntry('www', 'AAAA', $required_entries);
	}

	if (!$froxlorhostname)
	{
		// additional required records for subdomains
		$subdomains_stmt = Database::prepare("
			SELECT `domain`, `iswildcarddomain`, `wwwserveralias`, `isdynamicdomain`, `dynamicipv4`, `dynamicipv6` FROM `" . TABLE_PANEL_DOMAINS . "`
			WHERE `parentdomainid` = :domainid
		");
		Database::pexecute($subdomains_stmt, array(
			'domainid' => $domain_id
		));

		while ($subdomain = $subdomains_stmt->fetch(PDO::FETCH_ASSOC)) {
			$dynamicIPv4 = ($subdomain['isdynamicdomain'] && $subdomain['dynamicipv4'] != null) ? $subdomain['dynamicipv4'] : null;
			$dynamicIPv6 = ($subdomain['isdynamicdomain'] && $subdomain['dynamicipv6'] != null) ? $subdomain['dynamicipv6'] : null;

			// Listing domains is enough as there currently is no support for choosing
			// different ips for a subdomain => use same IPs as toplevel
			addRequiredEntry(str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries, $dynamicIPv4);
			addRequiredEntry(str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries, $dynamicIPv6);

			// Check whether to add a www.-prefix
			if ($subdomain['iswildcarddomain'] == '1') {
				addRequiredEntry('*.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries);
				addRequiredEntry('*.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries);
			} elseif ($subdomain['wwwserveralias'] == '1') {
				addRequiredEntry('www.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries);
				addRequiredEntry('www.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries);
			}
		}
	}

	// additional required records for SPF and DKIM if activated
	if ($domain['isemaildomain'] == '1') {
		if (Settings::Get('spf.use_spf') == '1') {
			// check for SPF content later
			addRequiredEntry('@SPF@', 'TXT', $required_entries);
		}
		if (Settings::Get('dkim.use_dkim') == '1') {
			// check for DKIM content later
			addRequiredEntry('dkim_' . $domain['dkim_id'] . '._domainkey', 'TXT', $required_entries);
			// check for ASDP
			if (Settings::Get('dkim.dkim_add_adsp') == "1") {
				addRequiredEntry('_adsp._domainkey', 'TXT', $required_entries);
			}
		}
	}

	// additional required records for mail autoconfiguration
	addRequiredEntry('@MAILAUTOCONF@', 'SRV', $required_entries);

	$primary_ns = null;
	$zonerecords = array();

	// now generate all records and unset the required entries we have
	foreach ($dom_entries as $entry) {
		if (array_key_exists($entry['type'], $required_entries) && array_key_exists(md5($entry['record']), $required_entries[$entry['type']])) {
			unset($required_entries[$entry['type']][md5($entry['record'])]);
		}
		if (Settings::Get('spf.use_spf') == '1' && $entry['type'] == 'TXT' && $entry['record'] == '@' && strtolower(substr($entry['content'], 0, 7)) == '"v=spf1') {
			// unset special spf required-entry
			unset($required_entries[$entry['type']][md5("@SPF@")]);
		}
		if (empty($primary_ns) && $entry['type'] == 'NS') {
			// use the first NS entry as primary ns
			$primary_ns = $entry['content'];
		}
		$zonerecords[] = new DnsEntry($entry['record'], $entry['type'], $entry['content'], $entry['prio'], $entry['ttl']);
	}

	// add missing required entries
	if (! empty($required_entries)) {

		// A / AAAA records
		if (array_key_exists("A", $required_entries) || array_key_exists("AAAA", $required_entries)) {
			if ($froxlorhostname) {
				// use all available IP's for the froxlor-hostname
				$result_ip_stmt = Database::prepare("
					SELECT `ip` FROM `" . TABLE_PANEL_IPSANDPORTS . "` GROUP BY `ip`
				");
				Database::pexecute($result_ip_stmt);
			} else {
				$result_ip_stmt = Database::prepare("
					SELECT `p`.`ip` AS `ip`
					FROM `" . TABLE_PANEL_IPSANDPORTS . "` `p`, `" . TABLE_DOMAINTOIP . "` `di`
					WHERE `di`.`id_domain` = :domainid AND `p`.`id` = `di`.`id_ipandports`
					GROUP BY `p`.`ip`;
				");
				Database::pexecute($result_ip_stmt, array(
					'domainid' => $domain_id
				));
			}
			$all_ips = $result_ip_stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($all_ips as $ip) {
				foreach ($required_entries as $type => $records) {
					foreach ($records as $record) {
						if ($type == 'A') {
							$finalIP = $record[1] != null ? $record[1] : $ip['ip'];
							if (filter_var($finalIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
								$zonerecords[] = new DnsEntry($record[0], 'A', $finalIP);
							}
						} elseif ($type == 'AAAA') {
							$finalIP = $record[1] != null ? $record[1] : $ip['ip'];
							if (filter_var($finalIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
								$zonerecords[] = new DnsEntry($record[0], 'AAAA', $finalIP);
							}
						}
					}
				}
			}
			unset($required_entries['A']);
			unset($required_entries['AAAA']);
		}

		// NS records
		if (array_key_exists("NS", $required_entries)) {
			if (Settings::Get('system.nameservers') != '') {
				$nameservers = explode(',', Settings::Get('system.nameservers'));
				foreach ($nameservers as $nameserver) {
					$nameserver = trim($nameserver);
					// append dot to hostname
					if (substr($nameserver, - 1, 1) != '.') {
						$nameserver .= '.';
					}
					foreach ($required_entries as $type => $records) {
						if ($type == 'NS') {
							foreach ($records as $record) {
								if (empty($primary_ns)) {
									// use the first NS entry as primary ns
									$primary_ns = $nameserver;
								}
								$zonerecords[] = new DnsEntry($record[0], 'NS', $nameserver);
							}
						}
					}
				}
				unset($required_entries['NS']);
			}
		}

		// MX records
		if (array_key_exists("MX", $required_entries)) {
			if (Settings::Get('system.mxservers') != '') {
				$mxservers = explode(',', Settings::Get('system.mxservers'));
				foreach ($mxservers as $mxserver) {
					$mxserver = trim($mxserver);
					if (substr($mxserver, - 1, 1) != '.') {
						$mxserver .= '.';
					}
					// split in prio and server
					$mx_details = explode(" ", $mxserver);
					if (count($mx_details) == 1) {
						$mx_details[1] = $mx_details[0];
						$mx_details[0] = 10;
					}
					foreach ($required_entries as $type => $records) {
						if ($type == 'MX') {
							foreach ($records as $record) {
								$zonerecords[] = new DnsEntry($record[0], 'MX', $mx_details[1], $mx_details[0]);
							}
						}
					}
				}
				unset($required_entries['MX']);
			}
		}

		// TXT (SPF and DKIM)
		if (array_key_exists("TXT", $required_entries)) {

			if (Settings::Get('dkim.use_dkim') == '1') {
				$dkim_entries = generateDkimEntries($domain);
			}

			foreach ($required_entries as $type => $records) {
				if ($type == 'TXT') {
					foreach ($records as $record) {
						if ($record[0] == '@SPF@') {
							$txt_content = Settings::Get('spf.spf_entry');
							$zonerecords[] = new DnsEntry('@', 'TXT', encloseTXTContent($txt_content));
						} elseif ($record[0] == 'dkim_' . $domain['dkim_id'] . '._domainkey' && ! empty($dkim_entries)) {
							// check for multiline entry
							$multiline = false;
							if (substr($dkim_entries[0], 0, 1) == '(') {
								$multiline = true;
							}
							$zonerecords[] = new DnsEntry($record[0], 'TXT', encloseTXTContent($dkim_entries[0], $multiline));
						} elseif ($record[0] == '_adsp._domainkey' && ! empty($dkim_entries) && isset($dkim_entries[1])) {
							$zonerecords[] = new DnsEntry($record[0], 'TXT', encloseTXTContent($dkim_entries[1]));
						}
					}
				}
			}
		}

		// SRV (Mail Autoconfiguration)
		if (array_key_exists("SRV", $required_entries)) {
			foreach ($required_entries as $type => $records) {
				if ($type == 'SRV') {
					foreach ($records as $record) {
						if ($record[0] == '@MAILAUTOCONF@')
						{
							# Microsoft Mail Autoconfiguration
							$zonerecords[] = new DnsEntry('_autodiscover._tcp', 'SRV', '0 443 ' . Settings::get('system.hostname') . '.');
							# RFC 6186 Mail Autoconfiguration
				 			foreach (getMailServers() as $server)
				 			{
				 				switch ($server['type'])
				 				{
				 					case 'POP3':
				 						if ($server['ssl'])
											$zonerecords[] = new DnsEntry('_pop3s._tcp', 'SRV', '0 1' . $server['port'] . ' ' . $server['hostname'] . '.', 2);
				 						else
											$zonerecords[] = new DnsEntry('_pop3._tcp', 'SRV', '0 1' . $server['port'] . ' ' . $server['hostname'] . '.', 0);
				 						break;
				 					case 'IMAP':
				 						if ($server['ssl'])
											$zonerecords[] = new DnsEntry('_imaps._tcp', 'SRV', '0 1' . $server['port'] . ' ' . $server['hostname'] . '.', 2);
				 						else
											$zonerecords[] = new DnsEntry('_imap._tcp', 'SRV', '0 1' . $server['port'] . ' ' . $server['hostname'] . '.', 0);
				 						break;
				 					case 'SMTP':
										$zonerecords[] = new DnsEntry('_submission._tcp', 'SRV', '0 1' . $server['port'] . ' ' . $server['hostname'] . '.');
				 						break;
				 				}
				 			}
						}
					}
				}
			}
		}
	}

	if (empty($primary_ns)) {
		// TODO log error: no NS given, use system-hostname
		$primary_ns = Settings::Get('system.hostname');
	}

	if (! $isMainButSubTo) {
		$date = date('Ymd');
		$domain['bindserial'] = (preg_match('/^' . $date . '/', $domain['bindserial']) ?
			$domain['bindserial'] + 1 :
			$date . '00');
		if (!$froxlorhostname) {
			$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
					`bindserial` = :serial
					 WHERE `id` = :id
				");
			Database::pexecute($upd_stmt, array('serial' => $domain['bindserial'], 'id' => $domain['id']));
		}

		// PowerDNS does not like multi-line-format
		$soa_content = $primary_ns . " " . escapeSoaAdminMail(Settings::Get('panel.adminmail')) . " ";
		$soa_content .= $domain['bindserial'] . " ";
		// TODO for now, dummy time-periods
		$soa_content .= "3600 900 604800 1200";

		$soa_record = new DnsEntry('@', 'SOA', $soa_content);
		array_unshift($zonerecords, $soa_record);
	}

	$zone = new DnsZone((int) Settings::Get('system.defaultttl'), $domain['domain'], $domain['bindserial'], $zonerecords);

	return $zone;
}

function addRequiredEntry($record = '@', $type = 'A', &$required, $dynamicIP = null)
{
	if (! isset($required[$type])) {
		$required[$type] = array();
	}
	$required[$type][md5($record)] = array($record, $dynamicIP);
}

function encloseTXTContent($txt_content, $isMultiLine = false)
{
	// check that TXT content is enclosed in " "
	if ($isMultiLine == false && Settings::Get('system.dns_server') != 'pdns') {
		if (substr($txt_content, 0, 1) != '"') {
			$txt_content = '"' . $txt_content;
		}
		if (substr($txt_content, - 1) != '"') {
			$txt_content .= '"';
		}
	}
	if (Settings::Get('system.dns_server') == 'pdns') {
		// no quotation for PowerDNS
		if (substr($txt_content, 0, 1) == '"') {
			$txt_content = substr($txt_content, 1);
		}
		if (substr($txt_content, - 1) == '"') {
			$txt_content = substr($txt_content, 0, -1);
		}
	}
	return $txt_content;
}

function escapeSoaAdminMail($email)
{
	$mail_parts = explode("@", $email);
	$escpd_mail = str_replace(".", "\.", $mail_parts[0]).".".$mail_parts[1].".";
	return $escpd_mail;
}
