<?php
/**
 * Linode DNS API Plugin v1.0
 *
 * Uses the Linode DNS API to create a redundant DNS setup.
 * Creates, updates, and deletes Linode DNS slaves entries to match ISPConfig's DNS SOAs.
 *
 * ===== Installation =====
 * 1.) Install the Linode PHP API Library (https://github.com/krmdrms/linode/)
 *
 * 2.) Copy linode_dns_plugin.inc.php to /usr/local/ispconfig/server/plugins-available/linode_dns_plugin.inc.php
 *
 *
 * ===== Configuration =====
 * 1.) Create an API key in Linode (https://manager.Linode.com/profile/)
 *         For extra security, you may want to create a new Linode user with only
 *         the "Can add Domains using the DNS Manager" permission.
 *
 * 2.) Create or edit /usr/local/ispconfig/server/lib/config.inc.local.php
 *         It should look something like this:
 *         <?php
 *             $conf['linode_api_key'] = 'your_linode_api_key';
 *             $conf['linode_dns'] = true;
 *         ?>
 *
 *  3.) Create a symlink from /usr/local/ispconfig/server/plugins-available/linode_dns_plugin.inc.php to /usr/local/ispconfig/server/plugins-enabled/linode_dns_plugin.inc.php
 *      OR run ispconfig_update.sh
 *
 *  == IMPORTANT NOTE ==
 *  SOAs MUST give the linode servers xfer access. I created a zone template that contains:
 *  xfer=69.93.127.10,65.19.178.10,75.127.96.10,207.192.70.10,109.74.194.10,96.126.114.97,96.126.114.98
 *
 * ===== License =====
 * Copyright (c) 2012 Chris Roemmich
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

require_once('Services/Linode.php');

class linode_dns_plugin {

	var $plugin_name = 'linode_dns_plugin';
	var $class_name  = 'linode_dns_plugin';

	/**
	 * This function is called during ispconfig installation to determine
	 * if a symlink shall be created for this plugin.
	 */
	function onInstall() {
		global $conf;

		if(isset($conf['linode_dns']) && $conf['linode_dns'] == true) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * This function is called when the plugin is loaded
	 */
	function onLoad() {
		global $app;

		// register for the SOA events
		$app->plugins->registerEvent('dns_soa_insert',$this->plugin_name,'soa_insert');
		$app->plugins->registerEvent('dns_soa_update',$this->plugin_name,'soa_update');
		$app->plugins->registerEvent('dns_soa_delete',$this->plugin_name,'soa_delete');
	}

	/**
	 * Called when a SOA is created
	 */
	function soa_insert($event_name,$data) {
		$this->update_linode_dns($data);
	}

	/**
	 * Called when a SOA is updated
	 */
	function soa_update($event_name,$data) {
		$this->update_linode_dns($data);
	}

	/**
	 * Called when a SOA is deleted
	 */
	function soa_delete($event_name,$data) {
		$this->delete_linode_dns($data);
	}

	/**
	 * Checks for a Linode api key in the configuration
	 */
	function get_linode_api_key() {
		global $conf;

		if(isset($conf['linode_api_key'])) {
			return $conf['linode_api_key'];
		} else {
			$this->log("API key not set");
			return null;
		}
	}

	/**
	 * Performs a nslookup used to determine the ip address to use as the master
	 */
	function nslookup($domain) {
		$dnsr = dns_get_record($domain, DNS_A);
		foreach ($dnsr as $record) {
			if (isset($record['ip'])) {
				$this->log("Master dns server ip: " . $record['ip'], LOGLEVEL_DEBUG);
				return $record['ip'];
			}
		}
		$this->log("Could not establish the IP for the master server");
		return null;
	}

	/**
	 * Creates or updates a Linode slave record
	 */
	function update_linode_dns($data) {
		if (!empty($data['new']['id'])) {
			$zone = $data['new'];
			$old_zone = $data['old'];
			$domain = strtolower(trim($zone['origin'], '. '));
			$ns = $zone['ns'];
			$master_ip = $this->nslookup($ns);

			if ($domain == null || $ns == null || master_ip == null) {
				$this->log("Failed to update dns slave due to missing data.");
				return;
			}

			$api_key = $this->get_linode_api_key();
			if ($api_key != null) {
				try {
					$linode = new Services_Linode($api_key);
					$response = $domains = $linode->domain_list(array());
					if ($this->has_errors($response) === true) {
						$this->log("Failed to list the linode dns domains " . $domain);
						return;
					}

					if ($zone['active'] == 'Y') {
						// the ns has changed, update the record, otherwise create a new record
						if($old_zone['ns'] != $zone['ns']  && !empty($old_zone['ns'])) {
							foreach ($domains['DATA'] as $d) {
								if ($d['DOMAIN'] == $domain) {
									$response = $linode->domain_update(array('DomainID' => $d['DOMAINID'], 'master_ips'=>$master_ip));
									if ($this->has_errors($response) === false) {
										$this->log("Updated the dns slave record for " . $domain, LOGLEVEL_DEBUG);
									} else {
										$this->log("Failed to update the dns slave record for " . $domain);
									}
									break;
								}
							}
						} else {
							$response = $linode->domain_create(array('Domain' => $domain, 'Type'=>'slave', 'master_ips'=>$master_ip));
							if ($this->has_errors($response) === false) {
								$this->log("Created a new dns slave record for " . $domain, LOGLEVEL_DEBUG);
							} else {
								$this->log("Failed to create a new dns slave record for " . $domain);
							}
						}
					} else {
						$this->log("Zone is not active.", LOGLEVEL_DEBUG);
					}

					// the domain name has changed, delete the old dns slave
					if(($old_zone['origin'] != $zone['origin'] && !empty($old_zone['origin'])) || $zone['active'] != 'Y') {
						$this->delete_linode_dns($data, $domains);
					}
				} catch (Services_Linode_Exception $e) {
					$this->log("Could not update/create dns slave. " . $e->getMessage());
				}
			} else {
				$this->log("Could not update/create slave. No api key.");
			}
		}
	}

	/**
	 * Deletes a Linode slave record
	 */
	function delete_linode_dns($data, $domains = null) {
		if (!empty($data['old']['id'])) {
			$zone = $data['old'];
			$domain = strtolower(trim($zone['origin'], '. '));

			if ($domain == null) {
				$this->log("Failed to delete dns slave due to missing data.");
				return;
			}

			$api_key = $this->get_linode_api_key();
			if ($api_key != null) {
				try {
					$linode = new Services_Linode($api_key);
					if ($domains == null) {
						$response = $domains = $linode->domain_list(array());
						if ($this->has_errors($response) === true) {
							$this->log("Failed to list the linode dns domains " . $domain);
							return;
						}
					}
					foreach ($domains['DATA'] as $d) {
						if ($d['DOMAIN'] == $domain) {
							$response = $linode->domain_delete(array('DomainID' => $d['DOMAINID']));
							if ($this->has_errors($response) === false) {
								$this->log("Deleted dns slave for " . $domain, LOGLEVEL_DEBUG);
							} else {
								$this->log("Failed to delete dns slave for " . $domain);
							}
							break;
						}
					}
				} catch (Services_Linode_Exception $e) {
					$this->log("Could not delete dns slave. " . $e->getMessage());
				}
			} else {
				$this->log("Could not delete dns slave. No api key.");
			}
		} else {
			$this->log("Could not delete dns slave. No passed data.");
		}
	}

	/**
	 * Parses the response of a Linode API call for errors
	 */
	function has_errors($body) {
		if (!empty($body) && !empty($body['ERRORARRAY'])) {
			$errors = $body['ERRORARRAY'];
			foreach ($errors as $error) {
				$this->log("api error " . $error['ERRORCODE'] . ' - ' . $error['ERRORMESSAGE'], LOGLEVEL_ERROR);
			}
			return true;
		}
		return false;
	}

	/**
	 * Logs to the ISPConfig log with a prefix
	 */
	function log($message, $level = LOGLEVEL_WARN) {
		global $app;
		$app->log("LINODE_DNS_PLUGIN:: " . $message, $level);
	}
}
?>
