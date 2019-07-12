<?php
/*
  Plugin Name: S3 Uploads DropIn
  Version: 1.3
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('plugins_loaded', [S3Uploads::get_instance(), 'plugin_setup']);

class S3Uploads 
{
  protected function __construct() { }
  protected function __wakeup() { }
  protected function __clone() { }

  public static function get_instance()
  {
    static $instance = null;
    if ($instance === null) {
      $instance = new static();
    }
    return $instance;
  }

  public function plugin_setup()
  {
    add_filter('wp_handle_upload_prefilter', function($file) {
    });
  }
}
