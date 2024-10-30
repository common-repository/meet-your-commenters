<?php
/*
Plugin Name: Meet your commenters
Plugin URI: http://www.berriart.com/meet-your-commenters/
Description: When someone comments on your blog and writes a comment with his/her URL, is leaving more information than you think. This plugin displays web pages and profiles of those users in the dashboard, so you can add them as friends if you are in the same social network.
Version: 1.2
Author: Alberto Varela
Author URI: http://www.berriart.com
License: GPL2

== CHANGELOG V1.2 ==

 * Checking compatibility up to Wordpress 3.0
 * License Change from GPL to GPL2
 * Start using SimplePie_File from Wordpress Core to get the API URL content to avoid some hosting limitations
 * Allow Internationalization and Spanish language added
 * Removing some warnings
 * Allowing direct install from Wordpress backend via FTP. 

== CHANGELOG V1.1 ==

 * Support for Wordpress 2.7

*/

/*  Copyright 2008-2010  Alberto Varela  (email : alberto@berriart.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Internationalizing the plugin 
$currentLocale = get_locale();
if(!empty($currentLocale)) 
{
  $moFile = dirname(__FILE__) . '/lang/' . $currentLocale . '.mo';
  if(@file_exists($moFile) && is_readable($moFile)) load_textdomain('meetYourCommenters', $moFile);
}

// Add actions and filters
add_action( 'admin_menu', 'meetYourCommenters_admin_menu' );
add_action('wp_dashboard_setup', 'meetYourCommenters_register_dashboard_widget');

 
// Dashboard Widget Function
function meetYourCommenters_register_dashboard_widget() {
	wp_add_dashboard_widget('dashboard_meetYourCommenters', __('Meet your commenters', 'meetYourCommenters'), 'dashboard_meetYourCommenters');
}
 
 
// Print Dashboard Widget
function dashboard_meetYourCommenters($sidebar_args) {
	global $wpdb;
	
	$commentRes = $wpdb->get_results("SELECT DISTINCT `comment_author`,`comment_author_email`,`comment_author_url` FROM `" . $wpdb->comments . "` WHERE `comment_author_url` != '' AND `comment_author_url` != 'http://' AND `comment_approved` = '1' AND `user_id` = '0' AND `comment_type` != 'trackback' AND `comment_type` != 'pingback' ORDER BY `comment_date` DESC LIMIT 5");
	$comments = array();
	if($commentRes && is_array($commentRes) && count($commentRes) > 0) {
		foreach($commentRes as $comment) {
			$comments[$comment->comment_author_url]= array( "url" => $comment->comment_author_url, "name" => $comment->comment_author, "email" => $comment->comment_author_email );
			
		}	
    meetYourCommenters_print_visitors($comments, false);
    echo '<p class="textright"><a href="index.php?page=meetYourCommenters" class="button">'. __('View all', 'meetYourCommenters') . '</a></p>';
	}
  else
  {
    echo '<p>'. __('No commenters with webpage found on this blog.', 'meetYourCommenters') . '</p>';
  } 
}

// Get URl Funtion to access Google API
function meetYourCommenters_getURL($url) {	
  if ( !class_exists( 'SimplePie_File' ) ) {
    include( ABSPATH . WPINC . '/class-simplepie.php' );
  }
  if ( class_exists( 'SimplePie_File' ) ) {
    $content = '';
    $content_object = new SimplePie_File($url);
    if($content_object)
    {
      $content = $content_object->body;
    }
  }
  // If WP new version deprecate SimplePie...
  elseif( function_exists('curl_init') ) { // This is the first patch to allow cURL. Thanks to Rick [http://rick.jinlabs.com/]
		//Curl is available so use it
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$content = curl_exec($ch);
		curl_close($ch);
	} else {
		// try with fopen if is available so use it instead
		$file = fopen($url, 'rb');
		$content = '';
		while (!feof($file)) {
			$content .= fread($file, 8192);
		}
		fclose($file);
	}
	return $content;
}

// Get the profiles from Google Social Graph API
function meetYourCommenters_getRelMes ($url) {
	$json = meetYourCommenters_getURL("http://socialgraph.apis.google.com/lookup?q=" . $url . "&fme=1&edi=1");
	$results = array();
	$results = json_decode($json);
	$firstNodes = $results->nodes;
	$relMe = array();
  if(count($firstNodes) > 0 ) 
  {
	  foreach ( $firstNodes as $firstNode ) {
		  $attributes = $firstNode->attributes;
		  $claimed_nodes = $firstNode->claimed_nodes;
		  $nodes_referenced_by = $firstNode->nodes_referenced_by;
		  $parsedURL = parse_url($url);
		  $urlHost = $parsedURL['host'];
		  if ( count($nodes_referenced_by) > 0 ) {
			  foreach ( $nodes_referenced_by as $key => $node_referenced ) {
				  $isMe = false;
				  foreach ( $node_referenced->types as $type ) {
					  if ( $type == 'me') $isMe = true;
				  }
				  $parsedURL = parse_url($key);
				  if ( $key != '' && $parsedURL['host'] != $urlHost && $isMe ) {
					  $relMe[$parsedURL['host']] = array( "url" => $key, "host" => $parsedURL['host'], "type" => "external" );
				  }
			  }
		  }

		  if ( count($claimed_nodes) > 0 ) {		
			  foreach ( $claimed_nodes as $new_node ) {
				  $parsedURL = parse_url($new_node);
				  if ( $new_node != '' && $parsedURL['host'] != $urlHost ) {
					  $relMe[$parsedURL['host']] = array( "url" => $new_node, "host" => $parsedURL['host'], "type" => "internal" );
				  }
			  }
		  }
      if(isset($attributes->url))
      {
		    $parsedURL = parse_url($attributes->url);
		    if ( $attributes->url != '' && $parsedURL['host'] != $urlHost ) {
			    $relMe[$parsedURL['host']] = array( "url" => $attributes->url, "host" => $parsedURL['host'], "type" => "internal" );
		    }
      }
	  }
  }
	return $relMe;
}

function meetYourCommenters_reports_page() {
	global $wpdb;
	if ( isset($_REQUEST['from'])) {
		$from = $_REQUEST['from'];
	}
	else {
		$from = 0;
	}
	$commentRes = $wpdb->get_results("SELECT DISTINCT `comment_author`,`comment_author_email`,`comment_author_url` FROM `" . $wpdb->comments . "` WHERE `comment_author_url` != '' AND `comment_author_url` != 'http://' AND `comment_approved` = '1' AND `user_id` = '0' AND `comment_type` != 'trackback' AND `comment_type` != 'pingback'  ORDER BY `comment_date` DESC LIMIT " . $from . ", 10");
	$comments = array();
	if($commentRes) {
		foreach($commentRes as $comment) {
			$comments[$comment->comment_author_url]= array( "url" => $comment->comment_author_url, "name" => $comment->comment_author, "email" => $comment->comment_author_email );
		}	
	}
	echo "<div style=\"padding:0 5em;\">";
	echo "<h1>" . __('Meet your commenters', 'meetYourCommenters') . "</h1>";
	echo "<p>" . sprintf( __('Here are listed the profiles and web pages of the commenters that have leave their URL. This is possible thanks to the Google Social Graph API. The profiles are showed because the commenter claims them as its owner on his web linking them with <em>rel="me"</em>. The ones which are with italic font are not reliable and they could not be of the user. Visit %s if you have any doubt, comment or sugestion.</p>', 'meetYourCommenters'), '<a href="http://www.berriart.com/meet-your-commenters/">http://www.berriart.com/meet-your-commenters/</a>');	
	meetYourCommenters_print_visitors($comments, true);
	$antFrom = $from - 10;
	$posFrom = $from + 10;
	echo "<p style=\"clear:both;background:#E4F2FD;border-bottom:#C6D9E9 solid 1px;border-top:#C6D9E9 solid 1px; font-size:1.2em;padding:0.5em;\">";
	if ( $antFrom >= 0 ) {
	echo "<a href=\"index.php?page=meetYourCommenters&amp;from=" . $antFrom . "\">" . __('Previous', 'meetYourCommenters') . "</a> | ";
	}
	echo "<a href=\"index.php?page=meetYourCommenters&amp;from=" . $posFrom . "\">" . __('Next', 'meetYourCommenters') . "</a>";
	echo "</p>";
	echo "</div>";
}

function meetYourCommenters_print_visitors($comments, $isMYCPage) {
	global $wpdb;
	echo "<style type=\"text/css\">";
	echo "#meetYourCommenters {} ";
	echo "#meetYourCommenters h4 {font-family:Georgia,\"Times New Roman\",\"Bitstream Charter\",Times,serif;font-size:14px;margin:0 0 0.2em;padding:0;color:#999999;line-height:1.4;margin-top:-0.2em;font-weight:bold;} ";
	echo "#meetYourCommenters .avatar {float:left;margin-left:-60px;} ";
	echo "#meetYourCommenters .meetYourCommentersItem {border-top:1px solid #DFDFDF;margin:0 -10px;padding:1em 10px 1em 70px;} ";
	echo "#meetYourCommenters li.visitor-profile-external a {font-style:italic;}";
	echo "#meetYourCommenters li {line-height:20px;}";
	echo "#meetYourCommenters li img {margin:2px 8px 0 0;float:left;}";
	echo "</style>";
	
	echo "<div id=\"meetYourCommenters\" class=\"list:comment\">";
	foreach ( $comments as $comment ) {
		$dataArray = meetYourCommenters_getRelMes($comment["url"]);
		
		echo "<div class=\"meetYourCommentersItem\">";
		echo get_avatar( $comment["email"], $size = '50', $default = '' );
		echo "<h4>" . $comment["name"] . "</h4>";
		echo "<ul>";
		$parsedURL = parse_url( $comment["url"] );
		echo "<li class='visitor-profile-internal'><img src=\"http://" . $parsedURL['host'] . "/favicon.ico\" alt=\"\" width=\"16\" height=\"16\" /><a href='" . $comment["url"] . "'>" . $comment["url"] . "</a></li>";
		if ( count($dataArray) > 0 ) {
			foreach ( $dataArray as $meURL ) {
				echo "<li class='visitor-profile-" . $meURL['type'] . "'><img src=\"http://" . $meURL['host'] . "/favicon.ico\" alt=\"\" width=\"16\" height=\"16\" /><a href='" . $meURL['url'] . "'>" . $meURL['url'] . "</a></li>";
			}
		}
		else {
			echo "<li class='visitor-profile-internal'><img src=\"http://" . $parsedURL['host'] . "/favicon.ico\" alt=\"\" width=\"16\" height=\"16\" />" . __("No more data available") . "</li>";
		}
		// If is the plugin page, print commentors' posts
		if ($isMYCPage) {
			echo "<li class='visitor-profile-internal'><strong>" . sprintf( __('Latest posts commented by %s:', 'meetYourCommenters'), $comment["name"]) . "</strong></li>";
			$commentRes2 = $wpdb->get_results("SELECT DISTINCT `comment_post_ID` FROM `" . $wpdb->comments . "` WHERE `comment_author_email` = '" . $comment["email"] . "' ORDER BY `comment_date` DESC LIMIT 5");
			$comments2 = array();
			if($commentRes2) {
				foreach($commentRes2 as $comment2) {
					$thePost = get_post($comment2->comment_post_ID); 
					$theTitle = $thePost->post_title;
					echo "<li class='visitor-profile-internal'><a href=\"" . get_permalink($comment2->comment_post_ID) . "\" title=\"" . $theTitle . "\">" . $theTitle . "</a></li>";
				}	
			}
		}
		
		
		echo "</ul>";
		echo "</div>";

	}
	echo "</div>";
}

function meetYourCommenters_admin_menu() {
	$hook = add_submenu_page('index.php', __('Meet your commenters', 'meetYourCommenters'), __('Meet your commenters', 'meetYourCommenters'), 'manage_options', 'meetYourCommenters', 'meetYourCommenters_reports_page');
}


// If PHP < 5.2.0 
if ( !function_exists('json_decode') ){
	function json_decode($content){
		// If you have the class defined by another plugin it won't work
		if ( !class_exists( "Services_JSON" ) ) {
			require_once dirname(__FILE__) . '/JSON.php';
		}
		$json = new Services_JSON;
		return $json->decode($content);
	}
}
?>
