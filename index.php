<?php 
/*
Plugin Name: Reclaim WPMS SSO Helper
Plugin URI:  https://github.com/
Description: For creating a better login experience with SSO. Use the [list-sites] shortcode.
Version:     1.1
Author:      Tom Woodward
Author URI:  https://bionicteaching.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: my-toolset

*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/*
**
REDIRECTS
**
*/

//get http or https
function reclaim_wpms_sso_protocol(){
    if (isset($_SERVER['HTTPS']) &&
    ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
    $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
     $protocol = 'https://';
   }
   else {
     $protocol = 'http://';
   }
   return $protocol;
}

//get page requested URL
function reclaim_wpms_sso_page_requested(){
   global $wp;
   $protocol = reclaim_wpms_sso_protocol();
   return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];//URL WITH HTTP/HTTPS
}


//do things if page is wp-login.php
function reclaim_wpms_sso_check_login(){
   $url = reclaim_wpms_sso_page_requested();//what page did you try to go to?
   $root_login = 'https://dev.wordpress.kpu.ca/wp-login.php?saml_sso';
   //$root_login = network_home_url() . 'wp-login.php';//this is the basic login page and includes https://  
   //******test against custom domains?????
   $site_id = get_current_blog_id();

   $parsed = parse_url($root_login);

   if (is_user_logged_in() === false){//make sure you aren't already logged in

         $plain_url = strtok($url, '?');//removes any query/redirect elements in the URL to make things simpler
         if($plain_url != $root_login && is_login()){//your are NOT on the main root login page but you are on a login page

            reclaim_wpms_sso_cookie_maker($site_id);//set cookie with the URL of the site where you tried to login
            wp_redirect( $root_login); //redirect to root login page           
            exit;
         } elseif ($plain_url === $root_login && is_login()) {
            return;
         }

   } else {
      //you are logged in already no need for any redirection or cookie examinations
      return;
   }
  
}

add_action( 'init', 'reclaim_wpms_sso_check_login' );

/*
**
COOKIE
**
*/

//set cookie
function reclaim_wpms_sso_cookie_maker($site_id){
    //set the cookie about where you were trying to go with the site ID
   $domain = ($_SERVER['HTTP_HOST'] != 'localhost') ? $_SERVER['HTTP_HOST'] : false;
   //setcookie("reclaim_redirect_url", $plain_url, 0, '/', $domain);
   setcookie("reclaim_redirect_site_id", $site_id, 0, '/', $domain);//???set expiration for cookie to 10 mins?
}

//read and destroy cookie
function reclaim_wpms_sso_cookie_action(){
   $protocol = reclaim_wpms_sso_protocol();//get http or https

    global $post;//get current post data
    if($post && is_user_logged_in()){//make sure they are logged in and this is a post (not admin page etc.)
      $user_id = get_current_user_id();//get user ID
      $post_slug = $post->post_name;//check current page slug ****make plugin settings page???
         if('my-sites' == $post_slug){//if my-sites slug
            if(isset($_COOKIE['reclaim_redirect_site_id'])){//if cookie set           
               $site_id = $_COOKIE['reclaim_redirect_site_id'];//get original site id from cookie
               $site_data = get_site($site_id);//get site info using ID
               $base_site_url = $site_data->domain . $site_data->path;//get base URL to site
               if(current_user_can_for_blog( $site_id, 'edit_posts' )){//if current user is contributor or higher
                  reclaim_wpms_sso_delete_cookie('reclaim_redirect_site_id');//delete cookie
                  wp_redirect("https://". $base_site_url . 'wp-admin/');//send them to backend
                   exit;
               } else {//if just subscriber, send to front end site
                  reclaim_wpms_sso_delete_cookie('reclaim_redirect_site_id');
                  wp_redirect("https://". $base_site_url);
                   exit;                  
               }

            } //if no cookie, remain on my-sites page with list of sites

         }

    }
    
}

add_action( 'wp', 'reclaim_wpms_sso_cookie_action', 11 );

function reclaim_wpms_sso_delete_cookie($cookie_name){
   if (isset($_COOKIE[$cookie_name])) {
      $domain = ($_SERVER['HTTP_HOST'] != 'localhost') ? $_SERVER['HTTP_HOST'] : false;
       setcookie($cookie_name, '', time()-3600, '/', $domain); 
       unset($_COOKIE[$cookie_name]); 
       return true;
   } else {
       return false;
   }
}

/*
**
LIST ALL THE SITES YOU ARE A MEMBER OF USING THE [list-sites] SHORTCODE
**
*/
function reclaim_wpms_sso_list_all_the_sites_now(){
   if(is_user_logged_in()){
      $user_id = get_current_user_id();
      $sites = reclaim_wpms_sso_sort_sites_alpha(get_blogs_of_user($user_id)); //get blogs and sort a-z
      $html = '';
      foreach ($sites as $key => $site) {
         $title = $site->blogname;
         $url = $site->siteurl;
         $blog_id = $site->userblog_id;//not in use now but might be relevant later
         $html .= "<li><a href='{$url}'>{$title}</a> - <a href='{$url}/wp-admin/'>dashboard</a></li>";
      }
      return "<ul id='site-list'>{$html}</ul>";
   } else {
      $login_url = wp_login_url();
      return "<p>Please <a href='{$login_url}'>login</a> to see a list of your sites.</p>";
   }
}

add_shortcode( 'list-sites', 'reclaim_wpms_sso_list_all_the_sites_now' );

//a to z blog sorting
function reclaim_wpms_sso_sort_sites_alpha($blogs){
    uasort( $blogs, function( $a, $b ) { 
        return strcasecmp( $a->blogname, $b->blogname );
    });
    return $blogs;
}



/**
 * WordPress function for redirecting users on root page login based on user role
 */
function reclaim_wpms_sso_login_redirect( $url, $request, $user ) {
    if ( $user && is_object( $user ) && is_a( $user, 'WP_User' ) ) {
        if ( $user->has_cap( 'administrator' ) ) {
            $url = admin_url();
        } else {
            $url = home_url( '/my-sites/' );
        }
    }
    return $url;
}

add_filter( 'login_redirect', 'reclaim_wpms_sso_login_redirect', 10, 3 );


//LOGGER -- for logging var_dumps, variables, errors etc.

if ( ! function_exists('write_log')) {
   function write_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }
}
