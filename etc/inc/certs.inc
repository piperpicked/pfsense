<?php
/* $Id$ */
/*
	Copyright (C) 2008 Shrew Soft Inc
	Copyright (C) 2010 Jim Pingle <jimp@pfsense.org>
	All rights reserved.

        Redistribution and use in source and binary forms, with or without
        modification, are permitted provided that the following conditions are met:

        1. Redistributions of source code must retain the above copyright notice,
           this list of conditions and the following disclaimer.

        2. Redistributions in binary form must reproduce the above copyright
           notice, this list of conditions and the following disclaimer in the
           documentation and/or other materials provided with the distribution.

        THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
        INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
        AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
        AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
        OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
        SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
        INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
        CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
        ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
        POSSIBILITY OF SUCH DAMAGE.

		DISABLE_PHP_LINT_CHECKING
		pfSense_MODULE:	certificate_managaer
*/

require_once("functions.inc");

function & lookup_ca($refid) {
	global $config;

	if (is_array($config['ca']))
		foreach ($config['ca'] as & $ca)
			if ($ca['refid'] == $refid)
				return $ca;

	return false;
}

function & lookup_ca_by_subject($subject) {
	global $config;

	if (is_array($config['ca']))
		foreach ($config['ca'] as & $ca)
		{
			$ca_subject = cert_get_subject($ca['crt']);
			if ($ca_subject == $subject)
				return $ca;
		}

	return false;
}

function & lookup_cert($refid) {
	global $config;

	if (is_array($config['cert']))
		foreach ($config['cert'] as & $cert)
			if ($cert['refid'] == $refid)
				return $cert;

	return false;
}

function & lookup_cert_by_name($name) {
	global $config;
	if (is_array($config['cert']))
		foreach ($config['cert'] as & $cert)
			if ($cert['name'] == $name)
				return $cert;
}

function & lookup_crl($refid) {
	global $config;

	if (is_array($config['crl']))
		foreach ($config['crl'] as & $crl)
			if ($crl['refid'] == $refid)
				return $crl;

	return false;
}

function ca_chain_array(& $cert) {
	if($cert['caref']) {
		$chain = array();
		$crt =& lookup_ca($cert['caref']);
		$chain[] = $crt;
		while ($crt) {
			$caref = $crt['caref'];
			if($caref)
				$crt =& lookup_ca($caref);
			else
				$crt = false;
			if($crt)
				$chain[] = $crt;
		}
		return $chain;
	}
	return false;
}

function ca_chain(& $cert) {
	if($cert['caref']) {
		$ca = "";
		$cas = ca_chain_array($cert);
		if (is_array($cas))
			foreach ($cas as & $ca_cert)
			{
				$ca .= base64_decode($ca_cert['crt']);
				$ca .= "\n";
			}
		return $ca;
	}
	return "";
}

function ca_import(& $ca, $str, $key="") {
	global $config;

	$ca['crt'] = base64_encode($str);
	if (!empty($key))
		$ca['prv'] = base64_encode($key);

	$subject = cert_get_subject($str, false);
	$issuer = cert_get_issuer($str, false);
	
	// Find my issuer unless self-signed
	if($issuer <> $subject) {
		$issuer_crt =& lookup_ca_by_subject($issuer);
		if($issuer_crt)
			$ca['caref'] = $issuer_crt['refid'];
	}

	/* Correct if child certificate was loaded first */
	if (is_array($config['ca']))
		foreach ($config['ca'] as & $oca)
		{
			$issuer = cert_get_issuer($oca['crt']);
			if($ca['refid']<>$oca['refid'] && $issuer==$subject)
				$oca['caref'] = $ca['refid'];
		}
	if (is_array($config['cert']))
		foreach ($config['cert'] as & $cert)
		{
			$issuer = cert_get_issuer($cert['crt']);
			if($issuer==$subject)
				$cert['caref'] = $ca['refid'];
		}
	return true;
}

function ca_create(& $ca, $keylen, $lifetime, $dn) {

	$args = array(
		"digest_alg" => "sha1",
		"private_key_bits" => (int)$keylen,
		"private_key_type" => OPENSSL_KEYTYPE_RSA,
		"encrypt_key" => false);

	// generate a new key pair
	$res_key = openssl_pkey_new();

	// generate a certificate signing request
	$res_csr = openssl_csr_new($dn, $res_key, $args);

	// self sign the certificate
	$res_crt = openssl_csr_sign($res_csr, null, $res_key, $lifetime, $args);

	// export our certificate data
	openssl_pkey_export($res_key, $str_key);
	openssl_x509_export($res_crt, $str_crt);

	// return our ca information
	$ca['crt'] = base64_encode($str_crt);
	$ca['prv'] = base64_encode($str_key);
	$ca['serial'] = 0;

	return true;
}

function cert_import(& $cert, $crt_str, $key_str) {

	$cert['crt'] = base64_encode($crt_str);
	$cert['prv'] = base64_encode($key_str);

	$subject = cert_get_subject($crt_str, false);
	$issuer = cert_get_issuer($crt_str, false);
	
	// Find my issuer unless self-signed
	if($issuer <> $subject) {
		$issuer_crt =& lookup_ca_by_subject($issuer);
		if($issuer_crt)
			$cert['caref'] = $issuer_crt['refid'];
	}
	return true;
}

function cert_create(& $cert, $caref, $keylen, $lifetime, $dn) {

	$ca =& lookup_ca($caref);
	if (!$ca)
		return false;

	$ca_str_crt = base64_decode($ca['crt']);
	$ca_str_key = base64_decode($ca['prv']);
	$ca_res_crt = openssl_x509_read($ca_str_crt);
	$ca_res_key = openssl_pkey_get_private(array(0 => $ca_str_key, 1 => ""));
	$ca_serial = ++$ca['serial'];

	$args = array(
		"digest_alg" => "sha1",
		"private_key_bits" => (int)$keylen,
		"private_key_type" => OPENSSL_KEYTYPE_RSA,
		"encrypt_key" => false);

	// generate a new key pair
	$res_key = openssl_pkey_new();

	// generate a certificate signing request
	$res_csr = openssl_csr_new($dn, $res_key, $args);

	// self sign the certificate
	$res_crt = openssl_csr_sign($res_csr, $ca_res_crt, $ca_res_key, $lifetime,
				 $args, $ca_serial);

	// export our certificate data
	openssl_pkey_export($res_key, $str_key);
	openssl_x509_export($res_crt, $str_crt);

	// return our certificate information
	$cert['caref'] = $caref;
	$cert['crt'] = base64_encode($str_crt);
	$cert['prv'] = base64_encode($str_key);

	return true;
}

function csr_generate(& $cert, $keylen, $dn) {

	$args = array(
		"digest_alg" => "sha1",
		"private_key_bits" => (int)$keylen,
		"private_key_type" => OPENSSL_KEYTYPE_RSA,
		"encrypt_key" => false);

	// generate a new key pair
	$res_key = openssl_pkey_new();

	// generate a certificate signing request
	$res_csr = openssl_csr_new($dn, $res_key, $args);

	// export our request data
	openssl_pkey_export($res_key, $str_key);
	openssl_csr_export($res_csr, $str_csr);

	// return our request information
	$cert['csr'] = base64_encode($str_csr);
	$cert['prv'] = base64_encode($str_key);

	return true;
}

function csr_complete(& $cert, $str_crt) {

	// return our request information
	$cert['crt'] = base64_encode($str_crt);
	unset($cert['csr']);

	return true;
}

function csr_get_subject($str_crt, $decode = true) {

	if ($decode)
		$str_crt = base64_decode($str_crt);

	$components = openssl_csr_get_subject($str_crt);

	if (!is_array($components))
		return "unknown";

	foreach ($components as $a => $v) {
		if (!strlen($subject))
			$subject = "{$a}={$v}";
		else
			$subject = "{$a}={$v}, {$subject}";
	}

	return $subject;
}

function cert_get_subject($str_crt, $decode = true) {

	if ($decode)
		$str_crt = base64_decode($str_crt);

	$inf_crt = openssl_x509_parse($str_crt);
	$components = $inf_crt['subject'];

	if (!is_array($components))
		return "unknown";

	foreach ($components as $a => $v) {
		if (!strlen($subject))
			$subject = "{$a}={$v}";
		else
			$subject = "{$a}={$v}, {$subject}";
	}

	return $subject;
}

function cert_get_subject_array($crt) {
	$str_crt = base64_decode($crt);
	$inf_crt = openssl_x509_parse($str_crt);
	$components = $inf_crt['subject'];

	if (!is_array($components))
		return;

	$subject_array = array();

	foreach($components as $a => $v)
		$subject_array[] = array('a' => $a, 'v' => $v);

	return $subject_array;
}

function cert_get_subject_hash($crt) {
	$str_crt = base64_decode($crt);
	$inf_crt = openssl_x509_parse($str_crt);
	return $inf_crt['subject'];
}

function cert_get_issuer($str_crt, $decode = true) {

	if ($decode)
		$str_crt = base64_decode($str_crt);

	$inf_crt = openssl_x509_parse($str_crt);
	$components = $inf_crt['issuer'];
	
	if (!is_array($components))
		return "unknown";
	foreach ($components as $a => $v) {
		if (!strlen($issuer))
			$issuer = "{$a}={$v}";
		else
			$issuer = "{$a}={$v}, {$issuer}";
	}

	return $issuer;
}

function is_user_cert($certref) {
	global $config;
	if (!is_array($config['system']['user']))
		return;
	foreach ($config['system']['user'] as $user) {
		if (!is_array($user['cert']))
			continue;
		foreach ($user['cert'] as $cert) {
			if ($certref == $cert)
				return true;
		}
	}
	return false;
}

function is_openvpn_server_cert($certref) {
	global $config;
	if (!is_array($config['openvpn']['openvpn-server']))
		return;
	foreach ($config['openvpn']['openvpn-server'] as $ovpns) {
		if ($ovpns['certref'] == $certref)
			return true;
	}
	return false;
}

function is_openvpn_client_cert($certref) {
	global $config;
	if (!is_array($config['openvpn']['openvpn-client']))
		return;
	foreach ($config['openvpn']['openvpn-client'] as $ovpnc) {
		if ($ovpnc['certref'] == $certref)
			return true;
	}
	return false;
}

function is_ipsec_cert($certref) {
	global $config;
	if (!is_array($config['ipsec']['phase1']))
		return;
	foreach ($config['ipsec']['phase1'] as $ipsec) {
		if ($ipsec['certref'] == $certref)
			return true;
	}
	return false;
}

function is_webgui_cert($certref) {
	global $config;
	if ($config['system']['webgui']['ssl-certref'] == $certref)
		return true;
}

function cert_in_use($certref) {
	return (is_webgui_cert($certref) ||
		is_user_cert($certref) ||
		is_openvpn_server_cert($certref) ||
		is_openvpn_client_cert($certref) ||
		is_ipsec_cert($certref));
}

/*
CRL code is a *WORK IN PROGRESS* do not try to use these functions yet.

OpenSSL CRL status code constants.
OCSP_REVOKED_STATUS_NOSTATUS
OCSP_REVOKED_STATUS_UNSPECIFIED
OCSP_REVOKED_STATUS_KEYCOMPROMISE
OCSP_REVOKED_STATUS_CACOMPROMISE
OCSP_REVOKED_STATUS_AFFILIATIONCHANGED
OCSP_REVOKED_STATUS_SUPERSEDED
OCSP_REVOKED_STATUS_CESSATIONOFOPERATION
OCSP_REVOKED_STATUS_CERTIFICATEHOLD
OCSP_REVOKED_STATUS_REMOVEFROMCRL
*/

$openssl_crl_status = array(
	OCSP_REVOKED_STATUS_NOSTATUS              => "No Status (default)",
	OCSP_REVOKED_STATUS_UNSPECIFIED           => "Unspecified",
	OCSP_REVOKED_STATUS_KEYCOMPROMISE         => "Key Compromise",
	OCSP_REVOKED_STATUS_CACOMPROMISE          => "CA Compromise",
	OCSP_REVOKED_STATUS_AFFILIATIONCHANGED    => "Affiliation Changed",
	OCSP_REVOKED_STATUS_SUPERSEDED            => "Superseded",
	OCSP_REVOKED_STATUS_CESSATIONOFOPERATION  => "Cessation of Operation",
	OCSP_REVOKED_STATUS_CERTIFICATEHOLD       => "Certificate Hold",
	OCSP_REVOKED_STATUS_REMOVEFROMCRL         => "Remove from CRL"
);

function crl_create(& $crl, $caref, $name, $serial=0, $lifetime=9999) {
	global $config;
	$ca =& lookup_ca($caref);
	if (!$ca)
		return false;
	$crl['name'] = $name;
	$crl['caref'] = $caref;
	$crl['serial'] = $serial;
	$crl['lifetime'] = $lifetime;
	$crl['cert'] = array();
	$crl_res = crl_update($crl);
	$config['crl'][] = $crl;
	return $crl_res;
}

function crl_update(& $crl) {
	global $config;
	$ca =& lookup_ca($crl['caref']);
	if (!$ca)
		return false;
	// If we have text but no certs, it was imported and cannot be updated.
	if (!empty($crl['text']) && empty($crl['cert']))
		return false;
	$crl['serial']++;
	$ca_str_crt = base64_decode($ca['crt']);
	$ca_str_key = base64_decode($ca['prv']);
	$crl_res = openssl_crl_new($ca_str_crt, $crl['serial'], $crl['lifetime']);
	foreach ($crl['cert'] as $cert) {
		openssl_crl_revoke_cert($crl_res, base64_decode($cert["crt"]), $cert["revoke_time"], $cert["reason"]);
	}
	openssl_crl_export($crl_res, $crl_text, $ca_str_key);
	$crl['text'] = base64_encode($crl_text);
	return $crl_res;
}

function cert_revoke($cert, & $crl, $reason=OCSP_REVOKED_STATUS_UNSPECIFIED) {
	global $config;
	if (is_cert_revoked($cert))
		return true;
	// If we have text but no certs, it was imported and cannot be updated.
	if (!empty($crl['text']) && empty($crl['cert']))
		return false;
	$cert["reason"] = $reason;
	$cert["revoke_time"] = time();
	$crl["cert"][] = $cert;
	crl_update($crl);
}

function cert_unrevoke($cert, & $crl) {
	global $config;
	// If we have text but no certs, it was imported and cannot be updated.
	if (!empty($crl['text']) && empty($crl['cert']))
		return false;
	foreach ($crl['cert'] as $id => $rcert) {
		if (($rcert['refid'] == $cert['refid']) || ($rcert['name'] == $cert['name'])) {
			unset($crl['cert'][$id]);
			crl_update($crl);
			return true;
		}
	}
	return false;
}

function is_cert_revoked($cert) {
	global $config;
	if (!is_array($config['crl']))
		return false;

	foreach ($config['crl'] as $crl) {
		if (!is_array($crl['cert']))
			continue;
		foreach ($crl['cert'] as $rcert) {
			if (($rcert['refid'] == $cert['refid']) || ($rcert['name'] == $cert['name']))
				return true;
		}
	}
	return false;
}

?>