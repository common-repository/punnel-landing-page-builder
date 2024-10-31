<?php
/*
Plugin Name: Punnel - Landing Page Builder
Plugin URI: https://profiles.wordpress.org/punnel
Description: Landing Page Builder WordPress Plugin. Connector to access content from Punnel service. Powerful Landing Page Builder for marketing events
Author: Punnel
Author URI: https://punnel.com
Version: 1.3.1
*/

if( ! class_exists( "PageTemplater" ) ){
	require plugin_dir_path( __FILE__ ) . 'add-template.php';
}

if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
	header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
	header( 'Access-Control-Allow-Credentials: true' );
	header( 'Access-Control-Max-Age: 86400' ); // cache for 1 day
}

if ( $_SERVER['REQUEST_METHOD'] == 'OPTIONS' ) {
	if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) ) {
		header( "Access-Control-Allow-Methods: GET, POST, OPTIONS" );
	}
	if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ) ) {
		header( "Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}" );
	}
	exit( 0 );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Punnel' ) ) {

	class Punnel {
		protected static $_instance = null;

		protected $_notices = array();

		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		public function __construct() {
			add_action( 'init', array( $this, 'init_endpoint' ) );
			add_action( 'admin_init', array( $this, 'check_environment' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
			add_action( 'admin_menu', array( $this, 'add_punnel_menu_item' ) );
			add_action( 'wp_ajax_punnel_save_config', array( $this, 'save_config' ) );
			add_action( 'wp_ajax_punnel_publish_lp', array( $this, 'publish_lp' ) );

			register_activation_hook( __FILE__, array( $this, 'activation_process' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivation_process' ) );
		}

		public function activation_process() {
		    $this->init_endpoint();
			flush_rewrite_rules();
		}

		public function deactivation_process() {
			flush_rewrite_rules();
		}

		/* add hook and do action */
		public function init_endpoint() {
			add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
			add_action( 'parse_request', array( $this, 'sniff_requests' ) );
			add_rewrite_rule( '^punnel/api', 'index.php?punnel_api=1', 'top' );
		}

		public function add_query_vars( $vars ) {
			$vars[] = 'punnel_api';

			return $vars;
		}

		public function sniff_requests() {
			global $wp;
			$isPunnel = isset( $wp->query_vars['punnel_api'] ) ? $wp->query_vars['punnel_api'] : null;

			if ( ! is_null( $isPunnel ) && $isPunnel === "1" ) {
				$params      = filter_input_array( INPUT_POST );
				$api_key     = isset( $params['token'] ) ? sanitize_text_field($params['token']) : null;
				$action      = isset( $params['action'] ) ? sanitize_text_field($params['action']) : null;
				$url         = isset( $params['url'] ) ? sanitize_text_field($params['url']) : null;
				$title       = isset( $params['title'] ) ? sanitize_text_field($params['title']) : null;
				$html        = isset( $params['html'] ) ? $params['html'] : '';
				$type       = isset( $params['type'] ) ? sanitize_text_field($params['type']) : null;

				$config = get_option( 'punnel_config', '');
					
				if ( $api_key !== $config['api_key'] ) {
					
					wp_send_json( array(
						'code'    => 190
					) );
				}
				switch ( $action ) {
					case 'create':
						if ( $this->get_id_by_slug($url) ) {
							wp_send_json( array(
								'code'    => 205
							) );
						}
						kses_remove_filters();
						if($type==null){
							$post_type = 'page';
						}else{
							$post_type = $type;
						}
						$id = wp_insert_post(
							array(
								'post_title'=>$title, 
								'post_name'=>$url, 
								'post_type'=>$post_type, 
								'post_content'=> trim($html), 
								'post_status' => 'publish',
								'filter' => true ,
								'page_template'  => 'null-template.php'
							)
						);
						if($id){
							wp_send_json( array(
								'code'    => 200,
								'url'    => get_permalink($id)
							) );
						}else{
							wp_send_json( array(
								'code'    => 400,
								'message' => __( 'Create failed, please try again.' )
							) );
						}
						break;
					
					case 'update':
						if ( ! $this->get_id_by_slug($url) ) {
							wp_send_json( array(
								'code'    => 400,
								'message' => __( 'URL does not exist' )
							) );
						}else{
							$id = $this->get_id_by_slug($url);
							$post = array(
					            'ID' => $id,
					            'post_title'=>$title, 
					            'post_content' => trim($html), 
					        );
					        kses_remove_filters();
					        $result = wp_update_post($post, true);
					        if($result){
								wp_send_json( array(
									'code'    => 200,
									'url'    => get_permalink($id)
								) );
					        }else{
					        	wp_send_json( array(
									'code'    => 400,
									'message' => __( 'Update failed, please try again.' )
								) );
					        }
						}
						break;

					case 'delete':
						if ( ! $this->get_id_by_slug($url) ) {
							wp_send_json( array(
								'code'    => 400,
								'message' => __( 'URL does not exist' )
							) );
						}else{
							$id = $this->get_id_by_slug($url);
							$result = wp_delete_post($id);
					        if($result){
					        	wp_send_json( array(
									'code'    => 200
								) );
					        }else{
					        	wp_send_json( array(
									'code'    => 400,
									'message' => __( 'Delete failed, please try again.' )
								) );
					        }
						}
						break;
					case 'checkurl':
						if ( ! $this->get_id_by_slug($url) ) {
							wp_send_json( array(
								'code'    => 206
							) );
						}else{
							wp_send_json( array(
								'code'    => 205
							) );
						}
						break;
					case 'checktoken':
						if ( $api_key === $config['api_key'] ) {
							wp_send_json( array(
								'code'    => 191
							) );
						}
						break;		
					default:
						wp_send_json( array(
							'code'    => 400,
							'message' => __( 'Punnel action is not set or incorrect.' )
						) );
						break;
				}
			}
		}


		protected function get_id_by_slug($page_slug) {
		    $page = get_page_by_path($page_slug,'OBJECT', ['post','page','product','property']);
		    if ($page) {
		        return $page->ID;
		    } else {
		        return null;
		    }
		} 



		public function get_option( $id, $default = '' ) {
			$options = get_option( 'punnel_config', array() );
			if ( isset( $options[ $id ] ) && $options[ $id ] != '' ) {
				return $options[ $id ];
			} else {
				return $default;
			}
		}

		/* Add menu and option */
		public function check_environment() {
			if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
				if ( ! function_exists( 'curl_init' ) ) {
					$this->add_admin_notice( 'curl_not_exist', 'error', __( 'Punnel requires cURL to be installed.', 'punnel' ) );
				}
			}
		}

		public function add_admin_notice( $slug, $class, $message ) {
			$this->_notices[ $slug ] = array(
				'class'   => $class,
				'message' => $message,
			);
		}

		public function admin_notices() {
			foreach ( $this->_notices as $notice_key => $notice ) {
				echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
				echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
				echo '</p></div>';
			}
		}

		public function add_punnel_menu_item() {
			add_menu_page( __( "Punnel" ), __( "Punnel" ), "manage_options", "punnel-config", array(
				$this,
				'punnel_settings_page'
			), null, 30 );
		}

		public function punnel_settings_page() {
			if ( ! empty( $this->_notices ) ) {
				?>
                <div>Please install cURL to use Punnel plugin</div>
				<?php
			} else {
				?>
                <div class="wrap">
                    <h2 class="title">Punnel - Landing Page Builder</h2><br>
                    <form id="punnel_config" class="ladiui-panel">
                    	<h3><strong>Config API Key</strong></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="api_key">API KEY</label>
                                </th>
                                <td>
                                	<?php
                                		$config = get_option( 'punnel_config', array());
                                		
                            		    if(!isset($config['api_key']) || trim($config['api_key']) == ''){
                            		    	$config['api_key'] = $this->generateRandomString(32);
                            		    	update_option( 'punnel_config', $config );
                            		    }

                                	?>
                                    <input onClick="this.select();" readonly="readonly" name="api_key" id="api_key" type="text" class="regular-text ladiui input"
                                           value="<?php echo $this->get_option( 'api_key', '' ); ?>">
                                           <button type="button" id="punnel_new_api" class="ladiui button primary">NEW API KEY</button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="webiste_punnel">API URL</label>
                                </th>
                                <td>
                                    <input onClick="this.select();" readonly="readonly" name="webiste_punnel" id="webiste_punnel" type="text" class="regular-text ladiui input" value="<?php echo get_home_url(); ?>">
                                </td>
                            </tr>
                        </table>

                        <div class="submit">
                            <button class="button button-primary ladiui button primary" id="punnel_save_option" type="button">Save Changes
                            </button>
                        </div>
                    </form>
                    <form class="ladiui-panel" id="punnel-publish-form">
                    	<h3><strong>Manualy Punnel Publish</strong></h3>
						<input name="api_key" id="api_key" type="hidden" value="<?php echo $this->get_option( 'api_key', '' ); ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="api_key">Landing Page ID</label>
                                </th>
                                <td>					    
                                    <input name="punnel_key" id="punnel_key" type="text" class="regular-text ladiui input" placeholder="Your Punnel Landing Page ID"><br/>
                                </td>
                            </tr>
                        </table>
                        <div class="submit">
                            <button type="button" id="punnel_publish" class="button button-primary ladiui button primary">Publish</button>
                        </div>
                    </form>
                    <script>
                        (function ($) {
                        	function generateRandomString(length = 10) {
							  	var text = "";
							  	var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
							  	for (var i = 0; i < length; i++)
							    text += possible.charAt(Math.floor(Math.random() * possible.length));
							  	return text;
							}
                            $(document).ready(function () {
                                $('#punnel_save_option').on('click', function (event) {
                                    var data = JSON.stringify($('#punnel_config').serializeArray());
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'punnel_save_config',
                                            data: data
                                        },
                                        success: function (response) {
                                            alert('Save Success');
                                        }
                                    });
                                    event.preventDefault();
                                });
                                $("#punnel_new_api").click(function(){
                                	var api = generateRandomString(32);
                                	$("#punnel_config #api_key").val(api);
                                });

                                $('#punnel_publish').on('click', function (event) {
                                	event.preventDefault();
                                	var punnelKey = $('#punnel_key').val();
									var apiKey = $('#api_key').val();
                                	if (punnelKey == '') {
                                		alert('Please enter your Landing Page ID!');
                                		return false;
                                	}
									if (apiKey == '') {
                                		alert('Please create new Api Key!');
                                		return false;
                                	}

                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'punnel_publish_lp',
                                            punnel_key: punnelKey,
											api_key: apiKey
                                        },
                                        success: function (res) {
                                           	alert(res.message);
                                        }
                                    });
                                    event.preventDefault();

                                });

                            });
                        })(jQuery);
                    </script>
                </div>
				<?php
			}
		}

		public function save_config() {
			$data   = sanitize_text_field($_POST['data']);
			$data   = json_decode( stripslashes( $data ) );
			$option = array();

			foreach ( $data as $key => $value ) {
				$option[ $value->name ] = $value->value;
			}
			update_option( 'punnel_config', $option );
			die;
		}

		public function publish_lp() {
			if (isset($_POST['punnel_key']) && trim($_POST['punnel_key']) != '') {
				$punnelKey = trim($_POST['punnel_key']) . '_' . trim($_POST['api_key']);
				$url = sprintf("https://api.punnel.com/api/page/getbykey?punnel_key=%s", $punnelKey);
				$jsonString = get_web_page($url);
				if ($jsonString) {
					$response = json_decode($jsonString);
					if (isset($response->code) && $response->code == 200) {
						$data = $response->data;
						if (!isset($data->url) || $data->url == '') {
							wp_send_json( array(
								'code'    => 403,
								'message' => __( 'Request is invalid!' )
							) ); exit;
						}

						$pageId = $this->get_id_by_slug($data->url);
						if (!$pageId) {
							kses_remove_filters();
							$id = wp_insert_post(
								array(
									'post_title'=>$data->title . ' - Punnel', 
									'post_name'=>$data->url, 
									'post_type'=>'page', 
									'post_content'=> trim($data->html), 
									'post_status' => 'publish',
									'filter' => true ,
									'page_template'  => 'null-template.php'
								)
							);
							if ($id) {
								wp_send_json( array(
									'code'    => 200,
									'message' => __( "Publish successfully! Page URL: " . site_url() . '/' . $data->url)
								) ); exit;
							}
						} else {
							$id = $this->get_id_by_slug($url);
							$post = array(
					            'ID' => $pageId,
					            'post_title'=>$data->title . ' - Punnel', 
					            'post_content' => trim($data->html), 
					        );
					        kses_remove_filters();
					        $result = wp_update_post($post, true);
					        wp_send_json( array(
									'code'    => 200,
									'message' => __( "Publish successfully! Page URL: " . site_url() . '/' . $data->url)
								) ); exit;
						}
					} else {
						wp_send_json( array(
							'code'    => 500,
							'message' => __( $response->message )
						) ); exit;
					}
				}

				wp_send_json( array(
					'code'    => 500,
					'message' => __( 'Request is invalid!' )
				) ); exit;
			}
		}

		public function generateRandomString($length = 10) {
		    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		    $charactersLength = strlen($characters);
		    $randomString = '';
		    for ($i = 0; $i < $length; $i++) {
		        $randomString .= $characters[rand(0, $charactersLength - 1)];
		    }
		    return $randomString;
		}

	}

	function Punnel() {
		return Punnel::instance();
	}

	Punnel();
}

function get_web_page($request, $post = 0) {
	$data = array('message' => '', 'content' => '');

	if (function_exists('curl_exec')) {
		$ch = curl_init();
		if ($post == 1) {
			curl_setopt($ch, CURLOPT_POST,1);
		}
		curl_setopt($ch, CURLOPT_USERAGENT, cp_useragent());
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 5);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_URL, $request);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		$response = curl_exec($ch);
		if (!$response) {
			$data['message'] = 'cURL Error Number ' . curl_errno($ch) . ' : ' . curl_error($ch);
		} else {
			$data['content'] = $response;
		}
		curl_close($ch);
	}

	return $response;
}

function cp_useragent() {
	$blist[] = "Mozilla/5.0 (compatible; Konqueror/4.0; Microsoft Windows) KHTML/4.0.80 (like Gecko)";
	$blist[] = "Mozilla/5.0 (compatible; Konqueror/3.92; Microsoft Windows) KHTML/3.92.0 (like Gecko)";
	$blist[] = "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0; WOW64; SLCC1; .NET CLR 2.0.50727; .NET CLR 3.0.04506; Media Center PC 5.0; .NET CLR 1.1.4322; Windows-Media-Player/10.00.00.3990; InfoPath.2)";
	$blist[] = "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.1.4322; InfoPath.1; .NET CLR 2.0.50727; .NET CLR 3.0.04506.30; Dealio Deskball 3.0)";
	$blist[] = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; NeosBrowser; .NET CLR 1.1.4322; .NET CLR 2.0.50727)";

	return $blist[array_rand($blist)];
}
?>