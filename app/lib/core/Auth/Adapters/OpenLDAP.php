<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Auth/Adapters/OpenLDAP.php : OpenLDAP authentication backend
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage Auth
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/core/Auth/BaseAuthAdapter.php');
require_once(__CA_LIB_DIR__.'/core/Auth/PasswordHash.php');

class OpenLDAPAuthAdapter extends BaseAuthAdapter implements IAuthAdapter {
	# --------------------------------------------------------------------------------
	public static function authenticate($ps_username, $ps_password = '', $pa_options=null) {
		$po_auth_config = Configuration::load(Configuration::load()->get('authentication_config'));
		
		if(!function_exists("ldap_connect")){
			throw new OpenLDAPException(_t("PHP's LDAP module is required for LDAP authentication!"));
		}

		// ldap config
		$vs_ldaphost = $po_auth_config->get("ldap_host");
		$vs_ldapport = $po_auth_config->get("ldap_port");
		$vs_base_dn = $po_auth_config->get("ldap_base_dn");
		$vs_user_ou = $po_auth_config->get("ldap_user_ou");
		$vs_bind_rdn = self::postProcessLDAPConfigValue("ldap_bind_rdn_format", $ps_username, $vs_user_ou, $vs_base_dn);


		$vo_ldap = ldap_connect($vs_ldaphost,$vs_ldapport);
		ldap_set_option($vo_ldap, LDAP_OPT_PROTOCOL_VERSION, 3);

		if (!$vo_ldap) {
			return false;
		}

		// log in
		$vo_bind = @ldap_bind($vo_ldap, $vs_bind_rdn, $ps_password);

		if(!$vo_bind) { // wrong credentials
			ldap_unbind($vo_ldap);
			return false;
		}

		// check group membership
		if(!self::isMemberinAtLeastOneGroup($ps_username, $vo_ldap)) {
			ldap_unbind($vo_ldap);
			return false;
		}

		ldap_unbind($vo_ldap);
		return true;
	}
	# --------------------------------------------------------------------------------
	public static function createUserAndGetPassword($ps_username, $ps_password) {
		// We don't create users in directories, we assume they're already there

		// We will create a password hash that is compatible with the CaUsers authentication adapter though
		// That way users could, in theory, turn off LDAP authentication later. The hash will not be used
		// for authentication in this adapter though.
		return create_hash($ps_password);
	}
	# --------------------------------------------------------------------------------
	public static function getUserInfo($ps_username, $ps_password) {
		$po_auth_config = Configuration::load(Configuration::load()->get('authentication_config'));

		if(!function_exists("ldap_connect")){
			throw new OpenLDAPException(_t("PHP's LDAP module is required for LDAP authentication!"));
		}

		// ldap config
		$vs_ldaphost = $po_auth_config->get("ldap_host");
		$vs_ldapport = $po_auth_config->get("ldap_port");
		$vs_base_dn = $po_auth_config->get("ldap_base_dn");
		$vs_user_ou = $po_auth_config->get("ldap_user_ou");
		//$va_group_cn = $po_auth_config->getList("ldap_group_cn");
		$vs_attribute_email = $po_auth_config->get("ldap_attribute_email");
		$vs_attribute_fname = $po_auth_config->get("ldap_attribute_fname");
		$vs_attribute_lname = $po_auth_config->get("ldap_attribute_lname");
		$vs_bind_rdn = self::postProcessLDAPConfigValue("ldap_bind_rdn_format", $ps_username, $vs_user_ou, $vs_base_dn);
		$vs_search_dn = self::postProcessLDAPConfigValue("ldap_user_search_dn_format", $ps_username, $vs_user_ou, $vs_base_dn);
		$vs_search_filter = self::postProcessLDAPConfigValue("ldap_user_search_filter_format", $ps_username, $vs_user_ou, $vs_base_dn);

		$vo_ldap = ldap_connect($vs_ldaphost,$vs_ldapport);
		if (!$vo_ldap) {
			throw new OpenLDAPException(_t("Could not connect to LDAP server."));
		}

		ldap_set_option($vo_ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
		$vo_bind = @ldap_bind($vo_ldap, $vs_bind_rdn, $ps_password);
		if (!$vo_bind) {
			// wrong credentials
			ldap_unbind($vo_ldap);
			throw new OpenLDAPException(_t("User could not be authenticated with LDAP server."));
		}

		// check group membership
		if(!self::isMemberinAtLeastOneGroup($ps_username, $vo_ldap)) {
			ldap_unbind($vo_ldap);
			throw new OpenLDAPException(_t("User is not member of at least one of the required groups."));
		}

		/* query directory service for additional info on user */
		$vo_results = @ldap_search($vo_ldap, $vs_search_dn, $vs_search_filter);
		if (!$vo_results) {
			// search error
			$vs_message = _t("LDAP search error: %1", ldap_error($vo_ldap));
			ldap_unbind($vo_ldap);
			throw new OpenLDAPException($vs_message);
		}

		$vo_entry = ldap_first_entry($vo_ldap, $vo_results);
		if (!$vo_entry) {
			// no results returned
			ldap_unbind($vo_ldap);
			throw new OpenLDAPException(_t("User could not be found."));
		}

		$va_attrs = ldap_get_attributes($vo_ldap, $vo_entry);

		$va_return = array();

		$va_return['email'] = $va_attrs[$vs_attribute_email][0];
		$va_return['fname'] = $va_attrs[$vs_attribute_fname][0];
		$va_return['lname'] = $va_attrs[$vs_attribute_lname][0];
		$va_return['user_name'] = $ps_username;
		$va_return['active'] = $po_auth_config->get("ldap_users_auto_active");

		$va_return['roles'] = $po_auth_config->get("ldap_users_default_roles");
		$va_return['groups'] = $po_auth_config->get("ldap_users_default_groups");

		return $va_return;
	}
	# --------------------------------------------------------------------------------
	private static function postProcessLDAPConfigValue($key, $ps_user_group_name, $ps_user_ou, $ps_base_dn) {
		$o_auth_config = Configuration::load(Configuration::load()->get('authentication_config'));

		$result = $o_auth_config->get($key);
		$result = str_replace('{username}', $ps_user_group_name, $result);
		$result = str_replace('{groupname}', $ps_user_group_name, $result);
		$result = str_replace('{user_ou}', $ps_user_ou, $result);
		$result = str_replace('{base_dn}', $ps_base_dn, $result);
		return $result;
	}
	# --------------------------------------------------------------------------------
	private static function isMemberinAtLeastOneGroup($ps_user, $po_ldap){
		$o_auth_config = Configuration::load(Configuration::load()->get('authentication_config'));
		$vs_base_dn = $o_auth_config->get("ldap_base_dn");

		$vs_group_search_dn = self::postProcessLDAPConfigValue("ldap_group_search_dn_format", '', '', $vs_base_dn);
		$va_group_cns = $o_auth_config->getList('ldap_group_cn_list');

		if(is_array($va_group_cns) && sizeof($va_group_cns)>0){
			foreach($va_group_cns as $vs_group_cn) {
				$vs_search_filter = self::postProcessLDAPConfigValue("ldap_group_search_filter_format", $vs_group_cn, '', $vs_base_dn);
				$vo_result = ldap_search($po_ldap, $vs_group_search_dn, $vs_search_filter, array("memberuid"));
				$va_entries = ldap_get_entries($po_ldap, $vo_result);
				if($va_members = $va_entries[0]["memberuid"]){
					if(in_array($ps_user, $va_members)){ // found group
						return true;
					}
				}
			}
		} else { // if no list is configured, all is good
			return true;
		}

		return false;
	}
	# --------------------------------------------------------------------------------
	public static function supports($pn_feature) {
		switch($pn_feature){
			case __CA_AUTH_ADAPTER_FEATURE_AUTOCREATE_USERS__:
				return true;
			case __CA_AUTH_ADAPTER_FEATURE_RESET_PASSWORDS__:
			case __CA_AUTH_ADAPTER_FEATURE_UPDATE_PASSWORDS__:
			default:
				return false;
		}
	}
	# --------------------------------------------------------------------------------
	public static function deleteUser($ps_username) {
		// do something?
		return true;
	}
	# --------------------------------------------------------------------------------
	public static function getAccountManagementLink() {
		$po_auth_config = Configuration::load(Configuration::load()->get('authentication_config'));

		if($vs_link = $po_auth_config->get('ldap_manage_account_url')) {
			return $vs_link;
		}

		return false;
	}
	# --------------------------------------------------------------------------------
}

class OpenLDAPException extends Exception {}