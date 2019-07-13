<?php
/*
  Plugin Name: S3 Uploads DropIn
  Version: 1.5
*/

if ( ! defined( 'ABSPATH' ) ) exit;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;

add_action('plugins_loaded', [S3Uploads::get_instance(), 'init']);

// A little singleton
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

  private $s3Client;

  public function init()
  {
    $this->initS3Client();
    add_filter('upload_dir', [$this, 'filterUploadDir']);
    add_filter('wp_update_attachment_metadata', [$this, 'onUpdatedAttachment'] , 10, 2);
    add_action('delete_attachment', [$this, 'onBeforeDeleted']);
  }

  private function initS3Client()
  {
    $config = [
      'region' => 'ap-southeast-2',
      'version' => 'latest'
    ];

    if (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY'))
    {
      $config = array_merge($config, [
        'credentials' => [
          'key' => getenv('AWS_ACCESS_KEY_ID'),
          'secret' => getenv('AWS_SECRET_ACCESS_KEY')
        ]
      ]);
    }
    $this->s3Client = S3Client::factory($config);
  }

  public function filterUploadDir($dirs)
  {
    $dirs['url'] = getenv('AWS_S3_BUCKET_URL') . '/' . $dirs['subdir'];
    $dirs['baseurl'] = getenv('AWS_S3_BUCKET_URL');
    return $dirs;
  }

  public function onUpdatedAttachment($attachmentData, $attachmentId)
  {
    $sizes = $attachmentData['sizes'] ?? []; 
    $paths = array_filter(array_map([$this, 'attachmentPath'], $sizes));

    $attachments = array_values(array_merge(
      [$this->attachmentMainPath($attachmentId)],
      $paths
    ));

    foreach($attachments as $attachment)
    {
      $this->uploadToS3($attachment);
      @unlink($attachment);
    }

    return $attachmentData;
  }

  public function onBeforeDeleted($attachmentId)
  {
    $attachments = array_map(function($attachment) 
    {
      $seperators = explode('uploads', $attachment);
      return getenv('SLAVE_ID') . end($seperators);
    }, array_merge(
      [$this->attachmentMainPath($attachmentId)],
      $this->attachmentOtherPaths($attachmentId)
    ));

    $this->s3Client->deleteObjects([
      'Bucket' => getenv('AWS_S3_BUCKET'),
      'Delete' => [
        'Objects' => array_map(function($key) 
        {
            return ['Key' => $key];
        }, $attachments)
      ]
    ]);
  }

  public function uploadToS3($path)
  {
    $source = fopen($path, 'rb');
    $filename = $this->filename($path);
    $key = getenv('SLAVE_ID') . wp_get_upload_dir()['subdir'] . "/$filename";
    $uploader = new ObjectUploader(
      $this->s3Client,
      getenv('AWS_S3_BUCKET'),
      $key,
      $source
    );
    $uploader->upload();
  }

  private function attachmentMainPath($attachmentId)
  {
    return get_attached_file($attachmentId);
  }

  private function attachmentOtherPaths($attachmentId)
  {
    return array_filter(array_map(function($size) use($attachmentId) 
    {
      $sizeInfo = image_get_intermediate_size($attachmentId, $size);
      return $this->attachmentPath($sizeInfo);
    }, $this->sizes($attachmentId)));
  }

  private function attachmentPath($sizeInfo)
  {
    return isset($sizeInfo['file']) ? (wp_get_upload_dir()['path'] . '/' . $sizeInfo['file']) : null;
  }

  private function sizes($attachmentId)
  {
    return array_keys(wp_get_attachment_metadata($attachmentId)['sizes'] ?? []); 
  }

  private function filename($path)
  {
    return basename($path);
  }
}

