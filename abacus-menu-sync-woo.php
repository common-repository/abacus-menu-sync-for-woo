<?php
/**
 * Plugin Name: Abacus Menu Sync For Woo
 * Description: Abacus Menu Sync For Woo loads all products and categories of Abacus API on your WooCommerce website.
 * Version: 1.0
 * Author: Nazanin Hesamzadeh
 * Author URI: http://www.appdomain.com.au/
 */

$abacus_menu_upload = wp_upload_dir();
$abacus_menu_upload_dir = $abacus_menu_upload['basedir'];
define('ABACUS_MENU_FOLDER', $abacus_menu_upload_dir .'/abacusmenu');

register_activation_hook( __FILE__,'abacus_menu_activation');
add_action('activation_hook', 'abacus_menu_activation');
add_action( 'admin_init', 'abacus_menu_register_settings' );
add_action('admin_menu', 'abacus_menu_options_page_setting');
add_action('wp_enqueue_scripts', 'abacus_menu_load_custom_styles');
add_action('updated_option','abacus_menu_setting_updated', 10, 3);
add_action('admin_notices', 'abacus_menu_admin_notice');
add_action('admin_notices', 'abacus_menu_admin_error');
register_deactivation_hook( __FILE__,'abacus_menu_deactivation');

function abacus_menu_setting_updated($option_name, $old_value, $option_value){
	if($option_name == 'abacusInitialized' && ($old_value != $option_value)){
		add_filter( 'upload_dir', 'abacus_menu_set_upload_dir' );
		$resLoading = abacus_menu_load_menu();
		if($resLoading) set_transient( 'abacus-menu-admin-notice-success-update', true, 5 ); 
		else set_transient( 'abacus-menu-admin-notice-error-update', true, 5 );  
	}	
}

function abacus_menu_activation(){
	$upload_dir =  ABACUS_MENU_FOLDER;
    if (! is_dir($upload_dir)) {
       mkdir( $upload_dir, 0700 );
    }
}
function abacus_menu_deactivation(){
	$files = glob(ABACUS_MENU_FOLDER.'/*'); 
	foreach($files as $file){ 
	  if(is_file($file))
		unlink($file); 
	}
	$upload_dir =  ABACUS_MENU_FOLDER;
    if (is_dir($upload_dir)) {
       rmdir( $upload_dir);
    }
	abacus_menu_remove_data();
}
function abacus_menu_set_upload_dir($upload) {
    $upload['subdir']   = '/abacusmenu';
    $upload['path'] = $upload['basedir'] . $upload['subdir'];
    $upload['url'] = $upload['baseurl'] . $upload['subdir'];
    return $upload;
}
function abacus_menu_curl_get_contents($url, $apiKey) {
	$body = false;
	$args = array(
		'headers' => array(
			'apikey' => $apiKey
		)
	);
	$response = wp_remote_get( $url, $args );
    if(wp_remote_retrieve_response_code( $response ) === 200){
		$body = wp_remote_retrieve_body($response);
	}
	return $body;
}
function abacus_menu_load_custom_styles(){
    if(get_option( 'layoutStyle')=='light')
		wp_enqueue_style( 'abacus', plugin_dir_url( __FILE__) . 'public/abacus.css', array(), NULL, 'all' );
}
function abacus_menu_register_settings() {
	$args = array(
		'type' => 'string', 
		'sanitize_callback' => 'sanitize_text_field',
		'default' => NULL,
		);
	$argsStyle = array(
		'type' => 'string', 
		'sanitize_callback' => 'sanitize_text_field',
		'default' => 'none',
		);
    register_setting( 'abacusmenu_options_group', 'storeId', $args );
	register_setting( 'abacusmenu_options_group', 'apiKey', $args );
	register_setting( 'abacusmenu_options_group', 'layoutStyle', $argsStyle );
	register_setting('abacusmenu_options_init_group', 'abacusInitialized','');
 }
function abacus_menu_admin_notice(){
	if(get_transient( 'abacus-menu-admin-notice-success-update' )){
		echo '<div class="notice is-dismissible notice-success">
			<p><strong>Menu synced successfully</strong>.</p>
	  		</div>';
		delete_transient( 'abacus-menu-admin-notice-success-update' );
	}
}
function abacus_menu_admin_error(){
	if(get_transient( 'abacus-menu-admin-notice-error-update' )){
		echo '<div class="notice is-dismissible notice-error">
		  	<p>Menu syncing failed!</p>
			</div>';
		delete_transient( 'abacus-menu-admin-notice-error-update' );
	}
}
function abacus_menu_options_page_setting() {
    add_options_page('WooCommerce Abacus Menu Sync Settings', 'WC Abacus Menu', 'manage_options', 'abacus_menu', 'abacus_menu_options_page_form');
}
function abacus_menu_remove_data(){
	global $wpdb;
	$sql_1 = "DELETE FROM ".$wpdb->prefix."term_relationships WHERE object_id IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type = 'product');";
	$sql_2 = "DELETE FROM ".$wpdb->prefix."postmeta WHERE post_id IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type = 'product');";
	$sql_3 = "DELETE FROM ".$wpdb->prefix."posts WHERE post_type = 'product';";

	$sql_4 = "DELETE relations.*, taxes.*, terms.*
	FROM ".$wpdb->prefix."term_relationships AS relations
	INNER JOIN ".$wpdb->prefix."term_taxonomy AS taxes
		ON relations.term_taxonomy_id=taxes.term_taxonomy_id
	INNER JOIN ".$wpdb->prefix."terms AS terms
		ON taxes.term_id=terms.term_id
	WHERE object_id IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type='product');";
	try{
		
		$wpdb->query($sql_1);
		$wpdb->query($sql_2);
		$wpdb->query($sql_3);
		$wpdb->query($sql_4);
		
	}catch(Exception $e){}
	$wpdb->query("DELETE a,c FROM wp_terms AS a
			  LEFT JOIN wp_term_taxonomy AS c ON a.term_id = c.term_id
			  LEFT JOIN wp_term_relationships AS b ON b.term_taxonomy_id = c.term_taxonomy_id
			  WHERE c.taxonomy = 'product_cat'");
}					  
function abacus_menu_load_menu() {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    $user_id = get_current_user_id();
    $apiKey= get_option('apiKey');
    $storeId=get_option('storeId');

	if(current_user_can('manage_options')){
		if($apiKey && $storeId){
			$url = "http://pouria-eval-test.apigee.net/getstarted?storeID=" . $storeId;
			$response = abacus_menu_curl_get_contents($url, $apiKey);
			if($response){
				$json= json_decode($response);
				$menu=  $json->{'Menus'};
				abacus_menu_remove_data();
				foreach($menu as $mnu) { 
					$doc= $mnu->{'Doc'};
					$Categories= $doc->{'Categories'};
					$Products= $doc->{'Products'};
					foreach($Categories as $cat) {
						$cid= $cat->{'ID'};
						$cname=$cat->{'Name'};
						$cDescription = $cat->{'Description'};

						wp_insert_term(
							$cname, 
							'product_cat',
							array(
								'description'=> $cDescription,
								'slug' => $cname,
								'parent' => "0"
							)
						);
						
						$subItems= $cat->{'SubMenuItems'};
						foreach($subItems as $sub) {
							$productCode=$sub->{'ProductCode'};
							$pid=$sub->{'ID'};
							$price= $sub->{'Price'};
							$productID= $sub->{'ProductID'};

							$thumb_url= $Products->{$productID}->{'PhotoUrl'};
							$post = array(
								'post_author' => $user_id,
								'post_status' => "publish",
								'post_title' => $sub->{'Name'},
								'post_parent' => '',
								'post_type' => "product",
							);
							
							$post_id = wp_insert_post( $post );
							if($post_id){
								wp_set_object_terms($post_id, $cname, 'product_cat' );
								wp_set_object_terms($post_id, 'simple', 'product_type');
								update_post_meta( $post_id,  '_price',  $sub->{'Price'});
								if($thumb_url){
									preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $thumb_url, $matches);
									if(!empty($matches)){
										$imagename = 'p_'. $pid . '.' . $matches[1];
										$new = $image_path = ABACUS_MENU_FOLDER . '/'. $imagename;
										if(!file_exists($image_path)){
											$tmp_file = download_url( $thumb_url );
											if (!is_wp_error($tmp_file)) {
												$tmppath = pathinfo( $tmp_file );
												$new = $tmppath['dirname'] . "/". $imagename;          
												rename($tmp_file, $new);    
												$file_array['tmp_name'] = $new;
												$file_array['name'] = $imagename;  
												$post_data['post_title'] = $imagename;
												$thumbid = media_handle_sideload( $file_array, $post_id, 'gallery desc',$post_data );
												set_post_thumbnail($post_id, $thumbid);
											}
										}	
									}
								}
							}
						}
					}
				}
				return true;
			}else{ return false; }
		}else{ return false; }
	}
	return false;
  }
function abacus_menu_options_page_form() {
 ?>
 <h1>WooCommerce Abacus Menu Sync</h1>
 <h2>Abacus API Server Info:</h2>
 <form method="post" action="options.php">
 	<?php 
	 settings_fields( 'abacusmenu_options_group' );
	 do_settings_fields( 'abacusmenu_options_group', '') 
	?>
	<table class="form-table">
    <tr valign="top">
        <th scope="row"><label for="apiKey">API Key:</label></th>
        <td><input type="text" class="regular-text" id="apiKey" name="apiKey" value="<?php echo get_option('apiKey'); ?>" /></td>
    </tr>
    <tr valign="top">
        <th scope="row"><label for="storeId">Store Id:</label></th>
        <td><input type="text" class="regular-text"  id="storeId" name="storeId" value="<?php echo get_option('storeId'); ?>" /></td>
    </tr>
    <tr valign="top">
        <th scope="row"><label for="layoutStyle">Layout:</label></th>
        <td><select id="layoutStyle" name="layoutStyle">
            <option value="none" <?php echo get_option('layoutStyle')==='none'?'selected':'' ?>>No Style</option>
            <option value="light" <?php echo get_option('layoutStyle')==='light'?'selected':'' ?> >Light</option>
            </select>
        </td>
    </tr>
   </table>
	<?php 
		$other_attributes = array( 'id' => 'abacus-menu-save-settings' );
		submit_button( __( 'Save Settings', 'textdomain' ), 'primary', 'abacus-menu-save-settings', true, $other_attributes );
	?>
 </form>
 <h2>Data Initialization:</h2>
   <p>Synchronize the menu to initialize categories and products.</p>
   <form method="post" action="options.php">
   <?php 
	 settings_fields( 'abacusmenu_options_init_group' );
	 do_settings_fields( 'abacusmenu_options_init_group', '') 
	?>
	<input type="hidden" id="abacusInitialized" name="abacusInitialized" value="<?php echo date('Y/m/d h:i:s'); ?>" />
	<?php 
		$syc_attributes = array( 'id' => 'abacus-menu-sync' );
		submit_button( __( 'Sync', 'textdomain' ), 'primary', 'abacus-menu-sync', true, $syc_attributes );
	 ?>
   </form>
 <?php
}
 