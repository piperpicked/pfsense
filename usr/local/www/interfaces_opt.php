<?php
/* $Id$ */
/*
	interfaces_opt.php
	part of m0n0wall (http://m0n0.ch/wall)

	Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>.
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
*/

require("guiconfig.inc");

unset($index);
if ($_GET['index'])
	$index = $_GET['index'];
else if ($_POST['index'])
	$index = $_POST['index'];

if (!$index)
	exit;

function remove_bad_chars($string) {
	return preg_replace('/[^a-z|_|0-9]/i','',$string);
}

$optcfg = &$config['interfaces']['opt' . $index];
$optcfg['descr'] = remove_bad_chars($optcfg['descr']);

if(is_array($config['aliases']['alias']))
	foreach($config['aliases']['alias'] as $alias) 
		if($alias['name'] == $optcfg['descr']) 
			$input_errors[] = gettext("Sorry, an alias with the name {$optcfg['descr']} already exists.");

$pconfig['descr'] = $optcfg['descr'];
$pconfig['bridge'] = $optcfg['bridge'];

$pconfig['enable'] = isset($optcfg['enable']);

$pconfig['blockpriv'] = isset($optcfg['blockpriv']);
$pconfig['blockbogons'] = isset($optcfg['blockbogons']);
$pconfig['spoofmac'] = $optcfg['spoofmac'];
$pconfig['mtu'] = $optcfg['mtu'];

$pconfig['disableftpproxy'] = isset($optcfg['disableftpproxy']);

/* Wireless interface? */
if (isset($optcfg['wireless'])) {
	require("interfaces_wlan.inc");
	wireless_config_init();
}

if ($optcfg['ipaddr'] == "dhcp") {
	$pconfig['type'] = "DHCP";
	$pconfig['dhcphostname'] = $optcfg['dhcphostname'];
} else {
	$pconfig['type'] = "Static";
	$pconfig['ipaddr'] = $optcfg['ipaddr'];
	$pconfig['subnet'] = $optcfg['subnet'];
	$pconfig['gateway'] = $optcfg['gateway'];
	$pconfig['pointtopoint'] = $optcfg['pointtopoint'];
}

if ($_POST) {

	unset($input_errors);

	/* filter out spaces from descriptions  */
	$POST['descr'] = remove_bad_chars($POST['descr']);

	if($_POST['gateway'] and $pconfig['gateway'] <> $_POST['gateway']) {
		/* enumerate slbd gateways and make sure we are not creating a route loop */
		if(is_array($config['load_balancer']['lbpool'])) {
			foreach($config['load_balancer']['lbpool'] as $lbpool) {
				if($lbpool['type'] == "gateway") {
				    foreach ((array) $lbpool['servers'] as $server) {
			            $svr = split("\|", $server);
			            if($svr[1] == $pconfig['gateway'])  {
			            		$_POST['gateway']  = $pconfig['gateway'];
			            		$input_errors[] = "Cannot change {$svr[1]} gateway.  It is currently referenced by the load balancer pools.";
			            		break;
			            }
					}
				}
			}
			foreach($config['filter']['rule'] as $rule) {
				if($rule['gateway'] == $_POST['gateway']) {
	            		$input_errors[] = "Cannot change {$_POST['gateway']} gateway.  It is currently referenced by the filter rules via policy based routing.";
	            		break;
				}
			}
		}
	}

	$pconfig = $_POST;

	/* input validation */
	if ($_POST['enable']) {

		/* description unique? */
		for ($i = 1; isset($config['interfaces']['opt' . $i]); $i++) {
			if ($i != $index) {
				if ($config['interfaces']['opt' . $i]['descr'] == $_POST['descr']) {
					$input_errors[] = "An interface with the specified description already exists.";
				}
			}
		}

		if ($_POST['bridge']) {
			/* double bridging? */
			for ($i = 1; isset($config['interfaces']['opt' . $i]); $i++) {
				if ($i != $index) {
					if ($config['interfaces']['opt' . $i]['bridge'] == $_POST['bridge']) {
						//$input_errors[] = "Optional interface {$i} " .
						//	"({$config['interfaces']['opt' . $i]['descr']}) is already bridged to " .
						//	"the specified interface.";
					} else if ($config['interfaces']['opt' . $i]['bridge'] == "opt{$index}") {
						//$input_errors[] = "Optional interface {$i} " .
						//	"({$config['interfaces']['opt' . $i]['descr']}) is already bridged to " .
						//	"this interface.";
					}
				}
			}
			if ($config['interfaces'][$_POST['bridge']]['bridge']) {
				//$input_errors[] = "The specified interface is already bridged to " .
				//	"another interface.";
			}
			/* captive portal on? */
			if (isset($config['captiveportal']['enable'])) {
				//$input_errors[] = "Interfaces cannot be bridged while the captive portal is enabled.";
			}
		} else {
			if ($_POST['type'] <> "DHCP") {
				$reqdfields = explode(" ", "descr ipaddr subnet");
				$reqdfieldsn = explode(",", "Description,IP address,Subnet bit count");
				do_input_validation($_POST, $reqdfields, $reqdfieldsn, &$input_errors);
				if (($_POST['ipaddr'] && !is_ipaddr($_POST['ipaddr']))) {
					if($_POST['ipaddr'] <> "none")
						$input_errors[] = "A valid IP address must be specified.";
				}
				if (($_POST['subnet'] && !is_numeric($_POST['subnet']))) {
					$input_errors[] = "A valid subnet bit count must be specified.";
				}
				if($_POST['gateway'] <> "" && !is_ipaddr($_POST['gateway'])) {
					$input_errors[] = "A valid gateway must be specified.";
				}
			}
		}
	        if ($_POST['mtu'] && (($_POST['mtu'] < 576) || ($_POST['mtu'] > 1500))) {
			$input_errors[] = "The MTU must be between 576 and 1500 bytes.";
		}		
		if (($_POST['spoofmac'] && !is_macaddr($_POST['spoofmac']))) {
			$input_errors[] = "A valid MAC address must be specified.";
		}		
	}

	if($_POST['mtu']) {
		if($_POST['mtu'] < 24 or $_POST['mtu'] > 1501)
			$input_errors[] = "A valid MTU is required 24-1500.";
	}
	
	/* Wireless interface? */
	if (isset($optcfg['wireless'])) {
		$wi_input_errors = wireless_config_post();
		if ($wi_input_errors) {
			$input_errors = array_merge($input_errors, $wi_input_errors);
		}
	}

	if (!$input_errors) {

		$bridge = discover_bridge($optcfg['if'], filter_translate_type_to_real_interface($optcfg['bridge']));
		if($bridge <> "-1") {
			destroy_bridge($bridge);
                        setup_bridge();
		}

		unset($optcfg['dhcphostname']);
		unset($optcfg['disableftpproxy']);
		
		/* per interface pftpx helper */
		if($_POST['disableftpproxy'] == "yes") {
			$optcfg['disableftpproxy'] = true;
			system_start_ftp_helpers();
		} else {			
			system_start_ftp_helpers();
		}		

		$optcfg['descr'] = remove_bad_chars($_POST['descr']);
		$optcfg['bridge'] = $_POST['bridge'];
		$optcfg['enable'] = $_POST['enable'] ? true : false;

		if ($_POST['type'] == "Static") {
			$optcfg['ipaddr'] = $_POST['ipaddr'];
			$optcfg['subnet'] = $_POST['subnet'];
			$optcfg['gateway'] = $_POST['gateway'];
			if (isset($optcfg['ispointtopoint']))
				$optcfg['pointtopoint'] = $_POST['pointtopoint'];
		} else if ($_POST['type'] == "DHCP") {
			$optcfg['ipaddr'] = "dhcp";
			$optcfg['dhcphostname'] = $_POST['dhcphostname'];
		}

		$optcfg['blockpriv'] = $_POST['blockpriv'] ? true : false;
		$optcfg['blockbogons'] = $_POST['blockbogons'] ? true : false;
		$optcfg['spoofmac'] = $_POST['spoofmac'];
		$optcfg['mtu'] = $_POST['mtu'];

		write_config();
		
		$savemsg = get_std_save_message($retval);
	}
}


$pgtitle = "Interfaces: Optional {$index} (" . htmlspecialchars($optcfg['descr']) . ")";
include("head.inc");

?>

<script language="JavaScript">
<!--
function enable_change(enable_over) {
	var endis;
	endis = !((document.iform.bridge.selectedIndex == 0) || enable_over);
	document.iform.ipaddr.disabled = endis;
	document.iform.subnet.disabled = endis;
}
function ipaddr_change() {
	document.iform.subnet.selectedIndex = gen_bits_opt(document.iform.ipaddr.value);
}
function type_change(enable_change,enable_change_pptp) {
	switch (document.iform.type.selectedIndex) {
		case 0:
			document.iform.ipaddr.type.disabled = 0;
			document.iform.ipaddr.disabled = 0;
			document.iform.subnet.disabled = 0;
			document.iform.gateway.disabled = 0;
			break;
		case 1:
			document.iform.ipaddr.type.disabled = 1;
			document.iform.ipaddr.disabled = 1;
			document.iform.subnet.disabled = 1;
			document.iform.gateway.disabled = 1;
			break;
	}
}
//-->
</script>

<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
<?php include("fbegin.inc"); ?>
<p class="pgtitle"><?=$pgtitle?></p>
<?php if ($input_errors) print_input_errors($input_errors); ?>
<?php if ($savemsg) print_info_box($savemsg); ?>
<?php if ($optcfg['if']): ?>
            <form action="interfaces_opt.php" method="post" name="iform" id="iform">
              <table width="100%" border="0" cellpadding="6" cellspacing="0">
                <tr>
                  <td colspan="2" valign="top" class="listtopic">Optional Interface Configuration</td>
                </tr>	      
                <tr>
                  <td width="22%" valign="top" class="vtable">&nbsp;</td>
                  <td width="78%" class="vtable">
			<input name="enable" type="checkbox" value="yes" <?php if ($pconfig['enable']) echo "checked"; ?> onClick="enable_change(false)">
                    <strong>Enable Optional <?=$index;?> interface</strong></td>
		</tr>
                <tr>
                  <td width="22%" valign="top" class="vncell">Description</td>
                  <td width="78%" class="vtable">
                    <input name="descr" type="text" class="formfld" id="descr" size="30" value="<?=htmlspecialchars($pconfig['descr']);?>">
					<br> <span class="vexpl">Enter a description (name) for the interface here.</span>
		  </td>
		</tr>

                <tr>
                  <td colspan="2" valign="top" height="16"></td>
                </tr>
                <tr>
                  <td colspan="2" valign="top" class="listtopic">General configuration</td>
                </tr>
                <tr>
                  <td valign="middle" class="vncell"><strong>Type</strong></td>
                  <td class="vtable"> <select name="type" class="formfld" id="type" onchange="type_change()">
                      <?php $opts = split(" ", "Static DHCP");
				foreach ($opts as $opt): ?>
                      <option <?php if ($opt == $pconfig['type']) echo "selected";?>>
                      <?=htmlspecialchars($opt);?>
                      </option>
                      <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr>
                  <td valign="top" class="vncell">MAC address</td>
                  <td class="vtable"> <input name="spoofmac" type="text" class="formfld" id="spoofmac" size="30" value="<?=htmlspecialchars($pconfig['spoofmac']);?>">
		    <?php
			$ip = getenv('REMOTE_ADDR');
			$mac = `/usr/sbin/arp -an | grep {$ip} | cut -d" " -f4`;
			$mac = str_replace("\n","",$mac);
		    ?>
		    <a OnClick="document.forms[0].spoofmac.value='<?=$mac?>';" href="#">Copy my MAC address</a>   
		    <br>
                    This field can be used to modify (&quot;spoof&quot;) the MAC
                    address of the WAN interface<br>
                    (may be required with some cable connections)<br>
                    Enter a MAC address in the following format: xx:xx:xx:xx:xx:xx
                    or leave blank</td>
                </tr>
                <tr>
                  <td valign="top" class="vncell">MTU</td>
                  <td class="vtable"> <input name="mtu" type="text" class="formfld" id="mtu" size="8" value="<?=htmlspecialchars($pconfig['mtu']);?>">
                    <br>
                    If you enter a value in this field, then MSS clamping for
                    TCP connections to the value entered above minus 40 (TCP/IP
                    header size) will be in effect. If you leave this field blank,
                    an MTU of 1492 bytes for PPPoE and 1500 bytes for all other
                    connection types will be assumed.</td>
                </tr>
		
                <tr>
                  <td colspan="2" valign="top" height="16"></td>
		</tr>
		<tr>
                  <td colspan="2" valign="top" class="listtopic">IP configuration</td>
		</tr>
		<tr>
                  <td width="22%" valign="top" class="vncellreq">Bridge with</td>
                  <td width="78%" class="vtable">
			<select name="bridge" class="formfld" id="bridge" onChange="enable_change(false)">
				  	<option <?php if (!$pconfig['bridge']) echo "selected";?> value="">none</option>
                      <?php $opts = array('lan' => "LAN", 'wan' => "WAN");
					  	for ($i = 1; isset($config['interfaces']['opt' . $i]); $i++) {
							if ($i != $index)
								$opts['opt' . $i] = "Optional " . $i . " (" .
									$config['interfaces']['opt' . $i]['descr'] . ")";
						}
					foreach ($opts as $opt => $optname): ?>
                      <option <?php if ($opt == $pconfig['bridge']) echo "selected";?> value="<?=htmlspecialchars($opt);?>">
                      <?=htmlspecialchars($optname);?>
                      </option>
                      <?php endforeach; ?>
                    </select> </td>
		</tr>
                <tr>
                  <td width="22%" valign="top" class="vncellreq">IP address</td>
                  <td width="78%" class="vtable">
                    <input name="ipaddr" type="text" class="formfld" id="ipaddr" size="20" value="<?=htmlspecialchars($pconfig['ipaddr']);?>">
                    /
                	<select name="subnet" class="formfld" id="subnet">
					<?php
					for ($i = 32; $i > 0; $i--) {
						if($i <> 31) {
							echo "<option value=\"{$i}\" ";
							if ($i == $pconfig['subnet']) echo "selected";
							echo ">" . $i . "</option>";
						}
					}
					?>                    </select>
				 </td>
				</tr>
		<tr>
                  <td width="22%" valign="top" class="vncell">Gateway</td>
                  <td width="78%" class="vtable">
			<input name="gateway" value="<?php echo $pconfig['gateway']; ?>">
			<br>
			If this interface is an Internet connection, enter its next hop gateway (router) IP address here.  Otherwise, leave this option blank.
		  </td>
		</tr>
                <tr>
                  <td colspan="2" valign="top" height="16"></td>
                </tr>
                <tr>
                  <td colspan="2" valign="top" class="listtopic">FTP Helper</td>
                </tr>		
		<tr>
			<td width="22%" valign="top" class="vncell">FTP Helper</td>
			<td width="78%" class="vtable">
				<input name="disableftpproxy" type="checkbox" id="disableftpproxy" value="yes" <?php if ($pconfig['disableftpproxy']) echo "checked"; ?> onclick="enable_change(false)" />
				<strong>Disable the userland FTP-Proxy application</strong>
				<br />
			</td>
		</tr>			
				<?php /* Wireless interface? */
				if (isset($optcfg['wireless']))
					wireless_config_print();
				?>		
                <tr>
                  <td colspan="2" valign="top" height="16"></td>
                </tr>
                <tr>
                  <td colspan="2" valign="top" class="listtopic">DHCP client configuration</td>
                </tr>
                <tr>
                  <td valign="top" class="vncell">Hostname</td>
                  <td class="vtable"> <input name="dhcphostname" type="text" class="formfld" id="dhcphostname" size="40" value="<?=htmlspecialchars($pconfig['dhcphostname']);?>">
                    <br>
                    The value in this field is sent as the DHCP client identifier
                    and hostname when requesting a DHCP lease. Some ISPs may require
                    this (for client identification).</td>
                </tr>
                <tr>
                  <td colspan="2" valign="top" height="16"></td>
                </tr>		
		<tr>
                  <td width="22%" valign="top">&nbsp;</td>
                  <td width="78%">
                    <input name="index" type="hidden" value="<?=$index;?>">
				  <input name="Submit" type="submit" class="formbtn" value="Save" onclick="enable_change(true)">
                  </td>
                </tr>
                <tr>
                  <td width="22%" valign="top">&nbsp;</td>
                  <td width="78%"><span class="vexpl"><span class="red"><strong>Note:<br>
                    </strong></span>be sure to add <a href="firewall_rules.php">firewall rules</a> to permit traffic
                    through the interface. You also need firewall rules for an interface in
                    bridged mode as the firewall acts as a filtering bridge.</span></td>
                </tr>
              </table>
</form>
<script language="JavaScript">
<!--
enable_change(false);
//-->
</script>
<?php else: ?>
<p><strong>Optional <?=$index;?> has been disabled because there is no OPT<?=$index;?> interface.</strong></p>
<?php endif; ?>
<?php include("fend.inc"); ?>
</body>
</html>

<?php
if ($_POST) {

	if (!$input_errors) {
		
		ob_flush();
		flush();
		sleep(1);		
		
		interfaces_optional_configure_if($index);
		
		reset_carp();

		/* load graphing functions */
		enable_rrd_graphing();	
		
		/* sync filter configuration */
		filter_configure();

 		/* set up static routes */
		system_routing_configure();

	}
}
?>