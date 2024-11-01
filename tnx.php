<?php

/*
Plugin Name: TNX-WP
Plugin URI: http://making-the-web.com/tnx-wp/
Description: Plugin to automize placement of links from TNX, the automatic link sales platform.
Author: Brendon Boshell
Version: 0.1 beta
Author URI: http://making-the-web.com
*/

$_tnx_ver = "0.1 beta";

/*
	Much of the code (for loading, getting links, etc) is based on code provided by www.tnx.net
*/

// do not edit settings here
// use the config page
$_tnx_opts = array(
		"username"		=> "",
		"exceptions"	=> array( "PHPSESSID" ),
		"timeout"		=> 5,
		"show"			=> array( "posts" => true, "pages" => true, "search" => true, "front" => true, "cats" => true, "tags" => true, "author" => true, "datepage" => true ),
		"num"			=> array( "before_content" => 0, "after_content" => 2, "after_comments" => 1, "footer" => -1 ),
		"display"		=> array( "before_content" => "br", "after_content" => "ul", "after_comments" => "br", "footer" => "space" ),
		"text_before"	=> array( "before_content" => "", "after_content" => "<p>Links</p>", "after_comments" => "<p>You may also like:</p>", "footer" => "" ),
		"text_after"	=> array( "before_content" => "", "after_content" => "", "after_comments" => "", "footer" => "" )
	);
	
$_tnx_opts_defaults = array(
		"username"		=> "",
		"exceptions"	=> array(),
		"timeout"		=> 5,
		"show"			=> array( "posts" => false, "pages" => false, "search" => false, "front" => false, "cats" => false, "tags" => false, "author" => false, "datepage" => false ),
		"num"			=> array( "before_content" => 0, "after_content" => 2, "after_comments" => 1, "footer" => -1 ),
		"display"		=> array( "before_content" => "br", "after_content" => "ul", "after_comments" => "br", "footer" => "space" ),
		"text_before"	=> array( "before_content" => "", "after_content" => "", "after_comments" => "", "footer" => "" ),
		"text_after"	=> array( "before_content" => "", "after_content" => "", "after_comments" => "", "footer" => "" )
	);
	
$_tnx_cache_table = $GLOBALS['wpdb']->prefix . "tnx_cache";
	
$_tnx_loaded = false;

define("_TNX_METHOD_FSOCK", 1);
define("_TNX_METHOD_CURL", 2);
define("_TNX_CACHE_EXPIRES", 3600);

$_tnx_method = function_exists('fsockopen') ? _TNX_METHOD_FSOCK : ( function_exists('curl_init') ? _TNX_METHOD_CURL : 0 );
// 0 = no method available
// 1 = fsock (pref)
// 2 = curl

$_tnx_links = array();
$_tnx_linkI = 0;

function _tnx_load() {
	global $_tnx_opts, $_tnx_links, $wpdb, $_tnx_cache_table, $_tnx_loaded;

	$_tnx_loaded = true;
	
	if( 
			(!$_tnx_opts["show"]["posts"]		&& is_single()		)
		|| 	(!$_tnx_opts["show"]["pages"]		&& is_page()		)
		|| 	(!$_tnx_opts["show"]["search"]		&& is_search()		)
		|| 	(!$_tnx_opts["show"]["front"]		&& (is_front_page() || is_home())	)
		|| 	(!$_tnx_opts["show"]["cats"]		&& is_category()	)
		|| 	(!$_tnx_opts["show"]["tags"]		&& is_tag()			)
		|| 	(!$_tnx_opts["show"]["author"]		&& is_author()		)
		|| 	(!$_tnx_opts["show"]["datepage"]	&& is_date()		)
		) 
		return false;
	
	$url = ($_SERVER['REQUEST_URI'] == '') ? "/" : $_SERVER['REQUEST_URI'];
	
	if(strlen($url) > 180) return false;
	if(!$_tnx_opts["username"]) return false;
	
	for($i = 0, $m = count($_tnx_opts["exceptions"]); $i < $m; $i++) {
		$e = $_tnx_opts["exceptions"][$i];
		
		if( $e && strpos($url, $e) !== false ) 
			return false; // blacklisted URL: return false (no links)
	}
	
	$url 		= base64_encode( $url );
	$urlhash 	= md5( $url );
	
	$urlhash_16 = pack("H*", $urlhash);
	
	$index 		= substr( $filehash, 0, 2 );
	$user_pref 	= substr( $_tnx_opts["username"], 0, 2 );
	$site = preg_replace( "#^www.#i", "", $_SERVER['HTTP_HOST'] );
	
	$path = '/users/' . $user_pref . '/' . $_tnx_opts["username"] . '/' . $site. '/' . substr($urlhash, 0, 1) . '/' . substr($urlhash, 1, 2) . '/' . $url . '.txt';
	
	$url = "http://" . $_tnx_opts["username"] . ".tnx.net" . $path;
	
	$time = time();
	
	$result = $wpdb->get_row( $wpdb->prepare( "SELECT data FROM `" . $_tnx_cache_table . "` WHERE idhash = %s AND updated_time > %d", $urlhash_16, $time - _TNX_CACHE_EXPIRES ) );
	
	if($result) {
		$_tnx_links = unserialize($result->data);
	}else{
		$content = _tnx_download($url);
		
		if($content === false)
			return false;
			
		$_tnx_links = array_map("trim", explode('<br>', $content));
		
		$wpdb->query(  $wpdb->prepare( "INSERT INTO `" . $_tnx_cache_table . "` (idhash, data, updated_time) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE updated_time = %d", $urlhash_16, serialize($_tnx_links) , $time, $time ) );
		
	}
		
	return true; // loaded
}

function _tnx_download( $url ) {
	global $_tnx_method, $_tnx_opts, $_tnx_ver;
	
	$u = parse_url($url);
	$ua = "TNX_n PHP 0.2b; TNX-WP ".$_tnx_ver." (Brendon Boshell)  ip: " . $_SERVER['REMOTE_ADDR'];
	$timeout = $_tnx_opts["timeout"] ? $_tnx_opts["timeout"] : 5;
	
	if( _TNX_METHOD_FSOCK == $_tnx_method ) {
	
		$f = @fsockopen( $u["host"], isset($u["port"]) ? $u["port"] : 80, $errno, $errstr, $timeout );
		if($f) {
			fwrite($f, "GET " . $u["path"] . " HTTP/1.1\r\nHost: " . $u["host"] . "\r\nUser-Agent: " . $ua . "\r\nConnection: close\r\n\r\n");
			stream_set_blocking($f, true);
			stream_set_timeout($f, $timeout);
			
			$info = stream_get_meta_data($f);
			
			$buffer = "";
			
			while (!feof($f) && !$info['timed_out']) {
			
					$buffer .= fgets($f, 4096);
					$info = stream_get_meta_data($f);
					
			}
			
			fclose($f);
			
			if($info['timed_out']) return false;
			
			$page = explode("\r\n\r\n", $buffer);
			$head = $page[0];
			$body = $page[1];
			
			if(!preg_match("#^HTTP/1\.[0-9] (200|404)#i", $head) || $errno != 0)
				return false;
			
			return $body;			
		}
		
	}elseif( _TNX_METHOD_CURL == $_tnx_method ) {
		$c = curl_init($url);
		
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($c, CURLOPT_HEADER, false);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($c, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($c, CURLOPT_USERAGENT, $ua);
		
		$page = curl_exec($c);
		
		if( curl_error($c) || !in_array( curl_getinfo($c, CURLINFO_HTTP_CODE), array("200", "404") ) )
		{
				curl_close($c);
				return false;
		}
		curl_close($c);
		return $page;
	}
	return false;
}
	
function _tnx_get_link() {
	global $_tnx_loaded, $_tnx_links, $_tnx_linkI;
	
	if( !$_tnx_loaded && !_tnx_load() ) // not loaded?
		return false;
		
	$linki = $_tnx_linkI++ ;
		
	if( ! isset($_tnx_links[ $linki ]) )
		return false;
		
	return get_option("blog_charset") ? html_entity_decode( htmlentities($_tnx_links[ $linki ], ENT_QUOTES, "windows-1251"), ENT_QUOTES, get_option("blog_charset") ) : $_tnx_links[ $linki ];
	
}

function _tnx_stripslashes_recursive($v)
{
   	return is_array($v) ? array_map('_tnx_stripslashes_recursive', $v) : stripslashes($v);
}

// this does not behave like PHP's array_merge_recursive
function _tnx_array_merge_recursive($a, $b) {
	$out = array_merge($a, $b);
	
	foreach($out as $k => $o)
		if(is_array($o))
			if( !is_array($a[$k]) )
				$out[$k] = $a[$k];
			else
				$out[$k] = _tnx_array_merge_recursive( is_array($a[$k]) ? $a[$k] : array(), is_array($b[$k]) ? $b[$k] : array() );

	return $out;
}

function _tnx_options_page() {
	global $_tnx_opts, $_tnx_opts_defaults;
	
	if(isset($_POST['Submit'])) {
	
		check_admin_referer('tnx-wp-update-options');
		
		$post = _tnx_stripslashes_recursive( $_POST );
		
		$_tnx_opts["username"] = $post["_tnx_username"];
		$_tnx_opts["exceptions"] = explode("\n", $post["_tnx_blacklist"]);
		
		foreach($_tnx_opts["exceptions"] as $k => $e)
			if(empty($e))
				unset($_tnx_opts["exceptions"][$k]);
		
		$_tnx_opts["show"] = $post["_tnx_show"];
		$_tnx_opts["num"] = $post["_tnx_num"];
		$_tnx_opts["display"] = $post["_tnx_display"];
		$_tnx_opts["text_before"] = $post["_tnx_text_before"];
		$_tnx_opts["text_after"] = $post["_tnx_text_after"];
		
		$_tnx_opts = _tnx_array_merge_recursive($_tnx_opts_defaults, $_tnx_opts);
		
		update_option( "tnx_opts", serialize($_tnx_opts) );	
		
	}
	
	?>
	
	<div class="wrap">
		
		<h2><?php _e("TNX-WP Options", "tnx"); ?></h2>
		
		<?php if(isset($_POST['Submit'])) { ?>
			<p><div id="message" class="updated fade"><p><strong><?php _e("Your settings have been updated.", 'tnx'); ?></strong></p></div></p>
		<?php } ?>
		
		<p><?php _e("This TNX plugin allows you to integrate TNX links into your WordPress blog with one-click. It allows you to place links before and after content, at the bottom of the page (footer), and in sidebar widgets; or a mixture of all. It uses caching to avoid continuous querying to the TNX servers.", "tnx"); ?></p>
		
		<style type="text/css">
				
				small {
					color: #999999;
				}
				
				.sep {
					margin-left: 20px;
				}
				
				.sep div {
					border-top: 1px solid #BBBBBB;
					padding: 10px 0;
					margin: 5px 0;
					text-indent: -20px;
				}
		
		</style>
		
		<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__) ?>">
		
			<?php wp_nonce_field('tnx-wp-update-options'); ?>
			
			<table cellspacing="7" cellpadding="5" border="0">
				
				<tr>
					<td valign="top"><?php _e("TNX Username", "tnx"); ?>:</td>
					
					<td>
						<input size="40" type="text" tabindex="1" name="_tnx_username" value="<?php echo attribute_escape($_tnx_opts["username"]); ?>" /> <small><?php _e('If you do not already have an account, you can <a href="http://www.tnx.net/?p=119614652">sign up</a> today.', 'tnx'); ?></small>
					</td>
				</tr>
				
				<tr>
					<td valign="top"><?php _e("Blacklist", "tnx"); ?>:</td>
					
					<td>
						<textarea name="_tnx_blacklist" id="_tnx_blacklist" tabindex="2" cols="70" rows="6"><?php foreach($_tnx_opts["exceptions"] as $b) { echo htmlspecialchars($b) . "\n"; } ?></textarea> <br /><small><?php _e('Any URLs containing these strings will not be indexed. <strong>Seperate with newlines.</strong>', 'tnx'); ?></small>
					</td>
				</tr>
				
				<tr>
					<td valign="top"><?php _e("Display", "tnx"); ?>:</td>
					
					<td class="sep">
						
						<div><?php _e("Show links on:", "tnx"); ?> <small><?php printf( __("(See \"%sConditional Tags%s\" for information about pages types)", "tnx"), '<a href="http://codex.wordpress.org/Conditional_Tags">', '</a>'); ?></small></div>
						
						<ul style="margin-left: 20px;">
						
							<li><?php printf(__("%s Posts", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[posts]"'.($_tnx_opts["show"]["posts"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf(__("%s Pages", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[pages]"'.($_tnx_opts["show"]["pages"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Search Pages", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[search]"'.($_tnx_opts["show"]["search"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Front Page", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[front]"'.($_tnx_opts["show"]["front"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Category Archive Pages", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[cats]"'.($_tnx_opts["show"]["cats"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Tag Archive Pages", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[tags]"'.($_tnx_opts["show"]["tags"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Author Pages", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[author]"'.($_tnx_opts["show"]["author"] ? ' checked="checked"' : '' ) . ">"); ?></li>
							
							<li><?php printf( __("%s Date Archive Page", "tnx"), '<input type="checkbox" value="true" name="_tnx_show[datepage]"'.($_tnx_opts["show"]["datepage"] ? ' checked="checked"' : '' ) . ">"); ?></li>
						
						</ul>
						
						<div><?php printf( __("Show %s links <em>before</em> a post or page's content as %s; text before: %s; text after: %s", "tnx"), '<input name="_tnx_num[before_content]" size="3" tabindex="3" type="text" value="'.attribute_escape((int)$_tnx_opts["num"]["before_content"]).'"  />', _tnx_stylesList("_tnx_display[before_content]", $_tnx_opts["display"]["before_content"]), '<input name="_tnx_text_before[before_content]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_before"]["before_content"]).'"  />', '<input name="_tnx_text_after[before_content]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_after"]["before_content"]).'"  />'); ?></div>
						
						<div><?php printf( __("Show %s links <em>after</em> a post or page's content as %s; text before: %s; text after: %s", "tnx"), '<input name="_tnx_num[after_content]" size="3" tabindex="3" type="text" value="'.attribute_escape((int)$_tnx_opts["num"]["after_content"]).'"  />', _tnx_stylesList("_tnx_display[after_content]", $_tnx_opts["display"]["after_content"]), '<input name="_tnx_text_before[after_content]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_before"]["after_content"]).'"  />', '<input name="_tnx_text_after[after_content]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_after"]["after_content"]).'"  />'); ?></div>
						
						<div><?php printf( __("Show %s links <em>after</em> a post or page's comments section as %s; text before: %s; text after: %s", "tnx"), '<input name="_tnx_num[after_comments]" size="3" tabindex="3" type="text" value="'.attribute_escape((int)$_tnx_opts["num"]["after_comments"]).'"  />', _tnx_stylesList("_tnx_display[after_comments]", $_tnx_opts["display"]["after_comments"]), '<input name="_tnx_text_before[after_comments]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_before"]["after_comments"]).'"  />', '<input name="_tnx_text_after[after_comments]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_after"]["after_comments"]).'"  />'); ?></div>
						
						<div><?php printf( __("Show remaining links in the page footer as %s; text before: %s; text after: %s", "tnx"), _tnx_stylesList("_tnx_display[footer]", $_tnx_opts["display"]["footer"]), '<input name="_tnx_text_before[footer]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_before"]["footer"]).'"  />', '<input name="_tnx_text_after[footer]" size="18" tabindex="3" type="text" value="'.attribute_escape($_tnx_opts["text_after"]["footer"]).'"  />'); ?></div>
						
						<div><?php _e("Further links can be placed in the sidebar using the TNX-WP widget (See Appearance->Widgets). Any links not displayed on the page will be displayed in the footer."); ?></div>
						
					</td>
				</tr>
				
			</table>
			
			<p class="submit">
			<input type="submit" name="Submit" value="<?php _e('Save Changes'); ?>" />
			</p>
			
		</form>	
		
		<h2><?php _e("About TNX-WP", "tnx"); ?></h2>
		
		<p style="width: 700px;"><?php _e("TNX-WP is created by Wordpress plugin author Brendon Boshell. Thank you for using TNX-WP, software provided for free. If you feel that this plugin has made your life easier in integrating TNX into your WordPress blog, please consider making a small donation to me. You can donate via PayPal, or transfer TNX points to TNX ID <code>119614652</code>. It is thanks to donations that I am able to continue creating useful WordPress plugins.", "tnx"); ?></p>	
		
		<p><form action="https://www.paypal.com/cgi-bin/webscr" method="post">
<input type="hidden" name="cmd" value="_s-xclick">
<input type="hidden" name="hosted_button_id" value="5802797">
<input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
<img alt="" border="0" src="https://www.paypal.com/en_GB/i/scr/pixel.gif" width="1" height="1">
</form></p>
		
		<p><?php printf( __("Check out: %sThank Me Later%s, %sStumble! for WordPress%s.", "tnx"), '<a href="http://wordpress.org/extend/plugins/thank-me-later/">', "</a>", '<a href="http://wordpress.org/extend/plugins/stumble-for-wordpress/">', "</a>"); ?>
		
		<p><?php _e("This plugin is not directly affiliated with TNX.net.", "tnx"); ?></p>
		
	</div>
	
	<?php
}

function _tnx_stylesList($name, $value) {
	return '<select name="'.$name.'"><option value="spaces"' . (($value == "spaces") ? ' selected="selected"' : '') . '>'.__("Space separated text", "tnx").'</option><option value="br"' . (($value == "br") ? ' selected="selected"' : '') . '>'.__("Line separated text", "tnx").'</option><option value="ul"' . (($value == "ul") ? ' selected="selected"' : '') . '>'.__("Unordered List", "tnx").'</option><option value="ol"' . (($value == "ol") ? ' selected="selected"' : '') . '>'.__("Ordered List", "tnx").'</option></select>';
}

function _tnx_options_links() {	
    if (function_exists('add_options_page')) {
		add_options_page( __("TNX-WP", "tnx"), __("TNX-WP", "tnx"), 8, plugin_basename(__FILE__), '_tnx_options_page');
	}
}

// _tnx_update_db
// check the database table's structure, and update if the structure for this release/install has changed
function _tnx_update_db($name, $structure, $createsql) {
	global $wpdb;

	$results = $wpdb->get_results("SHOW COLUMNS FROM `".$name."`", ARRAY_A);
	
	$needsupdate = false;
	$tf = count($structure);
	
	if(is_array($results)) foreach($results as $result) {
		if( isset(  $structure[ $result['Field'] ]  ) ) {
			if(is_array($structure[$result['Field']])) foreach($structure[$result['Field']] as $req => $value) {
				if($result[$req] != $value)
					$needsupdate = true;
			}
			
			$tf--;
			
		}
	}
	if($tf != 0) $needsupdate = true;
	
	if( $wpdb->get_var("SHOW TABLES LIKE '".$name."'") != $name  ||  $needsupdate ) {
	
		$sql = "DROP TABLE IF EXISTS `".$name."`";
		$wpdb->query($sql);
	
		dbDelta($createsql);
				
	}
}

function _tnx_install() {
	global $_tnx_opts, $_tnx_cache_table;	
	
	require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
	
	$structure = array(
		"idhash" => array("Key" => "PRI"),
		"data" => array(),
		"updated_time" => array()
	);
	
	$createsql = "CREATE TABLE ".$_tnx_cache_table." (
						`idhash` BINARY(16) NOT NULL,
						`data` BLOB NOT NULL,
						`updated_time` INT UNSIGNED NOT NULL, 
						PRIMARY KEY ( `idhash` )
					);";
					
	_tnx_update_db( $_tnx_cache_table, $structure, $createsql);
	
	update_option( "tnx_opts", serialize($_tnx_opts) );	
}

function _tnx_widget_get_options($id) {
	$opts = get_option("_tnx_widget_" . $id);
	
	if($opts === false) {
		$opts = array(
			"num" => 1,
			"title" => "Links",
			"before" => "",
			"after" => "",
		);
	}elseif(! is_array($opts))
		$opts = unserialize($opts);

	return $opts;
}

function _tnx_widget($info) {	
	extract($info);
	
	$id = intval(preg_replace("#^.*?\(([0-9]*?)\).*?$#", "$1", $widget_name)) - 1;

	$opts = _tnx_widget_get_options($id);

	$links = _tnx_show_links( $opts["num"], "ul", "", "", false);
	
	if($links) {

		echo $before_widget;
		echo $before_title . apply_filters('widget_title', $opts['title']) . $after_title;
		echo $opts["before"];
		echo $links;
		echo $opts["after"];
		echo $after_widget;
	
	}
}

function _tnx_widget_setup($id) {
	$opts = _tnx_widget_get_options($id);
	
	if ( isset($_POST["_tnx" . $id ."_widget_submit"]) ) {
	
		$opts["num"] = intval($_POST['_tnx'.$id.'_num']);
		$opts["title"] = stripslashes($_POST['_tnx'.$id.'_title']);
		$opts["before"] = stripslashes($_POST['_tnx'.$id.'_before']);
		$opts["after"] = stripslashes($_POST['_tnx'.$id.'_after']);
		
		update_option( "_tnx_widget_" . $id, serialize($opts) );
		return;
	}

	?>
			<p>
				<label for="num">
					<?php _e("Title:"); ?>
				</label>
				<input class="widefat" id="_tnx_title" name="_tnx<?php echo $id; ?>_title" type="text" value="<?php echo attribute_escape($opts["title"]); ?>" />
				
			</p>
			<p>
				<label for="num">
					<?php _e("Number of Links:"); ?>
				</label>
				<input class="widefat" id="_tnx_num" name="_tnx<?php echo $id; ?>_num" type="text" value="<?php echo (int)$opts["num"]; ?>" />
				
			</p>
			<p>
				<label for="num">
					<?php _e("Text Before Links:"); ?>
				</label>
				<input class="widefat" id="_tnx_before" name="_tnx<?php echo $id; ?>_before" type="text" value="<?php echo attribute_escape($opts["before"]); ?>" />
				
			</p>
			<p>
				<label for="num">
					<?php _e("Text After Links:"); ?>
				</label>
				<input class="widefat" id="_tnx_after" name="_tnx<?php echo $id; ?>_after" type="text" value="<?php echo attribute_escape($opts["after"]); ?>" />
				
			</p>
			<input type="hidden" name="_tnx<?php echo $id; ?>_widget_submit" value="1" />
	<?php
}

for($i = 0; $i < 4; $i++) {
	${"_tnx_widget_func" . $i} = create_function("", "_tnx_widget_setup(".$i.");");
}

function _tnx_init() {
	global $_tnx_opts;
	
	$o = get_option("tnx_opts");
	
	if( ! is_array($o) ) 
		$o = unserialize( $o );
	
	if( is_array($o) ) {
		$_tnx_opts = $o;
	}
	
	load_plugin_textdomain("tnx", "/wp-content/plugins/".dirname(plugin_basename(__FILE__))); // we'll need the language data

	for($a = 0; $a < 4; $a++) {
		register_sidebar_widget( sprintf(__("TNX-WP: Link Block (%d)", "tnx"), $a+1), '_tnx_widget');
		register_widget_control( sprintf(__("TNX-WP: Link Block (%d)", "tnx"), $a+1), $GLOBALS{"_tnx_widget_func" . $a} );
	}
}

function _tnx_show_links($num, $style, $before = "", $after = "", $echo = true) {
	$links = array();
	$out = "";
	
	$num = min ( $num, 4 );
	
	for($i = 0; $i < $num; $i++) {
		$t = _tnx_get_link();
		if($t)
			$links[] = $t;
	}
	
	if( ! count($links) )
		return $echo ? false : "";
		
	if($echo) 
		echo $before;
	else
		$out .= $before;
		
	if($style == "br") 
		foreach($links as $l)
		
			if($echo) 
				echo $l . "<br />";
			else
				$out .= $l . "<br />";
				
	elseif($style == "ul") {
	
		if($echo) 
			echo "<ul>\n";
		else
			$out .= "<ul>\n";
		foreach($links as $l)
			if($echo) 
				echo "<li>" . $l . "</li>\n";
			else
				$out .= "<li>" . $l . "</li>\n";
		if($echo) 
			echo "</ul>\n";
		else
			$out .= "</ul>\n";
			
	}elseif($style == "ol") {
	
		if($echo) 
			echo "<ol>\n";
		else
			$out .= "<ol>\n";
		foreach($links as $l)
			if($echo) 
				echo "<li>" . $l . "</li>\n";
			else
				$out .= "<li>" . $l . "</li>\n";
		if($echo) 
			echo "</ol>\n";
		else
			$out .= "</ol>\n";
			
	}else
		foreach($links as $l)
			if($echo) {
				echo $l . " ";
			}else{
				$out .= $l . " ";
			}
			
	if($echo) 
		echo $after;
	else
		$out .= $after;
		
	return $echo ? true : $out;	
}

function _tnx_comment_form() {
	global $_tnx_opts;
	return _tnx_show_links($_tnx_opts["num"]["after_comments"], $_tnx_opts["display"]["after_comments"], $_tnx_opts["text_before"]["after_comments"], $_tnx_opts["text_after"]["after_comments"]);
}

function _tnx_the_content($content) {
	global $_tnx_opts;
	
	return 
			_tnx_show_links($_tnx_opts["num"]["before_content"], $_tnx_opts["display"]["before_content"], $_tnx_opts["text_before"]["before_content"], $_tnx_opts["text_after"]["before_content"],  false)
	
			. $content 
			
			. _tnx_show_links($_tnx_opts["num"]["after_content"], $_tnx_opts["display"]["after_content"], $_tnx_opts["text_before"]["after_content"], $_tnx_opts["text_after"]["after_content"],  false);
}

function _tnx_wp_footer($content) {
	global $_tnx_opts;
	_tnx_show_links(4, $_tnx_opts["display"]["footer"], $_tnx_opts["text_before"]["footer"], $_tnx_opts["text_after"]["footer"], true);
	return $content;
}

add_action('comment_form', '_tnx_comment_form', 10, 2);
add_action('the_content','_tnx_the_content',1);
add_action('wp_footer', '_tnx_wp_footer', 1);

add_action("init", "_tnx_init");
add_action('admin_menu', '_tnx_options_links');
add_action('activate_'.plugin_basename(__FILE__), '_tnx_install');

?>