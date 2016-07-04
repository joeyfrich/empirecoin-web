<?php
$skip_select_db = TRUE;
include("includes/connect.php");

if ($_REQUEST['key'] == $GLOBALS['cron_key_string']) {
	if ($GLOBALS['mysql_database'] != "") {
		if (!strpos($GLOBALS['mysql_database'], "'") && $GLOBALS['mysql_database'] === strip_tags($GLOBALS['mysql_database'])) {
			$db_exists = false;

			$r = $app->run_query("SHOW DATABASES;");
			while ($dbname = $r->fetch()) {
				if ($dbname['Database'] == $GLOBALS['mysql_database']) $db_exists = true;
			}

			if (!$db_exists) {
				$r = $app->run_query("CREATE DATABASE ".$GLOBALS['mysql_database']);
				$app->set_db($GLOBALS['mysql_database']);
				
				$cmd = $app->mysql_binary_location()." -u ".$GLOBALS['mysql_user']." -h ".$GLOBALS['mysql_server'];
				if ($GLOBALS['mysql_password'] != "") $cmd .= " -p".$GLOBALS['mysql_password'];
				$cmd .= " ".$GLOBALS['mysql_database']." < ".realpath(dirname(__FILE__))."/sql/schema-initial.sql";
				echo exec($cmd);
			}
			else {
				$app->set_db($GLOBALS['mysql_database']);
			}
			
			$result = $app->run_query("SHOW TABLES;");
			$table_exists = $result->rowCount() > 0;
			if (!$table_exists) {
				echo "Database tables failed to be created, please install manually by importing all files in the \"sql\" folder via phpMyAdmin or any other MySQL interface.<br/>\n";
				die();
			}
			
			$app->update_schema();
			
			$q = "SELECT * FROM games WHERE url_identifier='empirecoin-launch';";
			$r = $app->run_query($q);
			
			if ($r->rowCount() == 0) {
				$address_id = $app->new_invoice_address();
				
				$q = "INSERT INTO games SET invoice_address_id='".$address_id."', option_group_id=1, featured=1, invite_currency=1, url_identifier='empirecoin-launch', game_status='published', giveaway_status='public_free', giveaway_amount=100000000000, pow_reward=2500000000, pos_reward=75000000000, game_type='simulation', block_timing='realistic', payout_weight='coin_round', seconds_per_block=120, name='EmpireCoin Launch', num_voting_options=16, maturity=1, round_length=10, max_voting_fraction=0.25, option_name='empire', option_name_plural='empires', buyin_policy='none';";
				$r = $app->run_query($q);
				$primary_game_id = $app->last_insert_id();
				
				$primary_game = new Game($app, $primary_game_id);
				
				$primary_game->ensure_game_options();
				
				$primary_game->start_game();
			}

			$q = "SELECT * FROM games WHERE url_identifier='empirecoin-testnet';";
			$r = $app->run_query($q);
			
			if ($r->rowCount() == 0) {
				$address_id = $app->new_invoice_address();
				
				$q = "INSERT INTO games SET invoice_address_id='".$address_id."', option_group_id=1, featured=1, invite_currency=1, url_identifier='empirecoin-testnet', start_condition='fixed_time', game_status='running', giveaway_status='public_free', giveaway_amount=0, pow_reward=2500000000, pos_reward=75000000000, game_type='real', rpc_port=23345, rpc_username='', rpc_password='', block_timing='realistic', payout_weight='coin', seconds_per_block=120, name='EmpireCoin Testnet', num_voting_options=16, maturity=9, round_length=10, max_voting_fraction=0.25, option_name='empire', option_name_plural='empires', buyin_policy='none', always_generate_coins=0, sync_coind_by_cron=1;";
				$r = $app->run_query($q);
				
				$testnet_game_id = $app->last_insert_id();
				
				$testnet_game = new Game($app, $testnet_game_id);
				
				$testnet_game->ensure_game_options();
				
				$app->set_site_constant('primary_game_id', $testnet_game_id);
			}
			else {
				$db_testnet_game = $r->fetch();
				$testnet_game = new Game($app, $db_testnet_game['game_id']);
			}
			
			$q = "SELECT * FROM currency_prices WHERE currency_id=1 AND reference_currency_id=1;";
			$r = $app->run_query($q);
			if ($r->rowCount() == 0) {
				$q = "INSERT INTO currency_prices SET currency_id=1, reference_currency_id=1, price=1, time_added='".time()."';";
				$r = $app->run_query($q);
			}
			
			$app->set_site_constant("game_loop_seconds", 2);
			$app->set_site_constant("reference_currency_id", 1);
			
			if ($_REQUEST['action'] == "save_rpc_params") {
				$game_id = (int) $_REQUEST['game_id'];
				$q = "SELECT * FROM games WHERE game_type='real' AND rpc_username='' AND rpc_password='' AND game_id=".$app->quote_escape($game_id).";";
				$r = $app->run_query($q);
				if ($r->rowCount() == 1) {
					$temp_rpc_game = $r->fetch();
					$rpc_username = $_REQUEST['rpc_username'];
					$rpc_password = $_REQUEST['rpc_password'];
					$r = $app->run_query("UPDATE games SET rpc_username=".$app->quote_escape($rpc_username).", rpc_password=".$app->quote_escape($rpc_password)." WHERE game_id=".$temp_rpc_game['game_id'].";");
				}
				else die("Error, please manually save RPC parameters in the database ($q).");
			}
			
			$pagetitle = $GLOBALS['site_name']." - Installing...";
			$include_crypto_js = TRUE;
			include("includes/html_start.php");
			?>
			<div class="container" style="max-width: 1000px; padding: 10px;">
				<h2>Install the MySQL database</h1>
				Great, the database was installed.<br/>
				If there was an error installing the database please use mysql to delete the database, then try again.<br/>
				
				<h2>Run Empirecoin Web</h1>
				Make sure this line has been added to your /etc/crontab:<br/>
<pre>
* * * * * root <?php echo $app->php_binary_location(); ?> <?php echo realpath(dirname(__FILE__))."/cron/minutely.php key=".$GLOBALS['cron_key_string']; ?>
</pre>
				If you can't use cron, open <a target="_blank" href="cron/minutely.php?key=<?php echo $GLOBALS['cron_key_string']; ?>">cron/minutely.php?key=<?php echo $GLOBALS['cron_key_string']; ?></a> in your browser any time you want to sync empirecoin web. Or run this command:
				<pre>
<?php echo $app->php_binary_location(); ?> <?php echo realpath(dirname(__FILE__))."/scripts/main.php key=".$GLOBALS['cron_key_string']; ?>
				</pre>
				
				<h2>Configure Apache for symlinked URLs</h1>
				Please run "a2enmod rewrite"<br/>
				Then make sure the line "AllowOverride All" is included in your apache configuration file (/etc/apache2/apache2.conf or /etc/httpd/httpd.conf or /etc/httpd/conf/httpd.conf)<br/>
				Example:
<pre>
&lt;Directory <?php echo realpath(dirname(__FILE__)); ?>&gt;
	Options Indexes FollowSymLinks
	AllowOverride All
	Require all granted
&lt;/Directory&gt;
</pre>
				
				<h2>Configure Bitcoin for accepting payments</h1>
				<script type="text/javascript">
				function generate_keypair() {
					$('#keypair_details').slideDown('fast');

					var rsa = new RSAKey();
					var e = '10001';
					rsa.generate(1024, e);
				  
					n_value = rsa.n.toString(16);
					d_value = rsa.d.toString(16);
					p_value = rsa.p.toString(16);
					q_value = rsa.q.toString(16);
					dmp1_value = rsa.dmp1.toString(16);
					dmq1_value = rsa.dmq1.toString(16);
					coeff_value = rsa.coeff.toString(16);

					$('#pub_key_disp').val(n_value);
					$('#priv_key_disp').val(d_value+':'+p_value+':'+q_value+':'+dmp1_value+':'+dmq1_value+':'+coeff_value);
					$('#pub_key_config_line').html("$GLOBALS['rsa_keyholder_email'] = 'myname@myemailprovider.com';\n$GLOBALS['rsa_pub_key'] = '"+n_value+"';");
				}
				</script>
				<?php
				if ($GLOBALS['rsa_pub_key'] != "") { ?>
					Great, it looks like you've already configured an RSA key for accepting Bitcoin payments.
					<br/>
					<?php
				}
				else { ?>
					You have not yet specified an RSA keypair for accepting Bitcoin payments.<br/>
					To allow private games to accept Bitcoin payments, please generate an RSA key pair.<br/>
					<button class="btn btn-primary" onclick="generate_keypair();">Generate RSA Keypair</button>
					<br/>
					<div id="keypair_details" style="display: none; border: 1px solid #aaa; padding: 10px; margin-top: 10px;">
						<b>A new RSA keypair has just been generated.</b><br/>
						<br/>
						This is your <font class="greentext">public key</font>. Copy and save your public key into includes/config.php.
						<input type="text" id="pub_key_disp" class="form-control" /><br/>
						This is your <font class="redtext">private key</font>. Save it somewhere safe.
						<input type="text" id="priv_key_disp" class="form-control" />
						<br/>
						Add your public key into includes/config.php like this:<br/>
						<pre id="pub_key_config_line"></pre>
						But replace 'myname@myemailprovider.com' with an email address.  This email address will not be shown to anyone but will receive an email prompting you to enter your private key whenever a game that you administer finishes.<br/>
						<br/>
						After saving your public key in includes/config.php, save your private key somewhere safe. Your public key can be derived from your private key. Next <a href="" onclick="window.location=window.location;">click here</a> to reload this page.<br/>
						<br/>
						If you lose or leak your private key, all escrowed bitcoins on this site will be irrevocably lost.<br/>
						
					</div>
					<?php
				}
				?>
				
				<h2>Connect to bitcoind/empirecoind</h2>
				<?php
				$rpc_games_r = $app->run_query("SELECT * FROM games WHERE game_type='real' AND game_status='running';");
				while ($rpc_game = $rpc_games_r->fetch()) {
					if ($rpc_game['rpc_username'] != "" && $rpc_game['rpc_password'] != "") {
						echo "<b>Connecting RPC client to ".$rpc_game['name']."...";
						try {
							$coin_rpc = new jsonRPCClient('http://'.$rpc_game['rpc_username'].':'.$rpc_game['rpc_password'].'@127.0.0.1:'.$rpc_game['rpc_port'].'/');
							$getinfo = $coin_rpc->getinfo();
							echo " <font class=\"greentext\">Connected on port ".$rpc_game['rpc_port']."</font></b><br/>\n";
							echo "<pre>getinfo()\n";
							print_r($getinfo);
							echo "</pre>";
							
							echo "To reset and synchronize this game, run <a target=\"_blank\" href=\"/scripts/sync_coind_initial.php?key=".$GLOBALS['cron_key_string']."&game_id=".$testnet_game->db_game['game_id']."\">scripts/sync_coind_initial.php?game_id=".$rpc_game['game_id']."</a>\n";
							echo "<br/><br/>\n";
						}
						catch (Exception $e) {
							echo " <font class=\"redtext\">Failed to connect on port ".$rpc_game['rpc_port']."</font></b><br/>";
							echo "<pre>Make sure the coin daemon is running.</pre>\n";
							echo "<br/>\n";
						}
					}
					else { ?>
						Please enter the RPC username and password for connecting to the <b><?php echo $rpc_game['name']; ?></b> daemon:<br/>
						<form method="post" action="install.php">
							<input type="hidden" name="key" value="<?php echo $GLOBALS['cron_key_string']; ?>" />
							<input type="hidden" name="action" value="save_rpc_params" />
							<input type="hidden" name="game_id" value="<?php echo $rpc_game['game_id']; ?>" />
							<input class="form-control" name="rpc_username" placeholder="RPC username" />
							<input class="form-control" name="rpc_password" placeholder="RPC password" />
							<input type="submit" class="btn btn-primary" value="Save" />
						</form>
						<br/>
						<?php
					}
				}
				?>
				
				<a class="btn btn-success" href="/">Check if installation was successful</a>
				<br/><br/>
			</div>
			<?php
			include("includes/html_stop.php");
		}
		else echo "An invalid database name was specified in includes/config.php";
	}
	else {
		echo 'Please set the $GLOBALS[\'mysql_database\'] variable in includes/config.php';
	}
}
else {
	echo 'Please set the correct value for "key" in the URL.<br/>';
	echo 'To find the correct key value, open includes/config.php and look for $GLOBALS[\'cron_key_string\'].';
}
?>
