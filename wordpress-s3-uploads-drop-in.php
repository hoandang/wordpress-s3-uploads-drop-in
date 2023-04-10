<?php
/*
  Plugin Name: S3 Uploads DropIn
  Description: Upload file from media gallery to S3
  Version: 1
  Author: Hoan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;

add_action('plugins_loaded', [S3Uploads::get_instance(), 'init']);

// A little singleton
class S3Uploads 
{
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
    $this->s3Client = S3Client::factory([
      'region' => $_ENV['AWS_DEFAULT_REGION'],
      'version' => 'latest',
    ]);
  }

  public function filterUploadDir($dirs)
  {
    $dirs['url'] = $_ENV['AWS_S3_BUCKET_URL'] . '/' . $dirs['subdir'];
    $dirs['baseurl'] = $_ENV['AWS_S3_BUCKET_URL'];
    return $dirs;
  }

  public function onUpdatedAttachment($attachmentData, $attachmentId)
  {
    $attachmentMainPath = $this->attachmentMainPath($attachmentId);
    $sizes = $attachmentData['sizes'] ?? []; 
    $paths = [];
    foreach($sizes as $size)
    {
      if ($path = $this->attachmentPath($size, $attachmentMainPath))
      {
        $paths[] = $path;
      }
    }

    $attachments = array_values(array_merge(
      [$attachmentMainPath],
      $paths
    ));

    foreach($attachments as $attachment)
    {
      $this->uploadToS3($attachment);
    }

    return $attachmentData;
  }

  public function onBeforeDeleted($attachmentId)
  {
    $attachments = array_map(function($attachment) 
    {
      $seperators = explode('uploads', $attachment);
      return $_ENV['AWS_S3_PATH'] . end($seperators);
    }, array_merge(
      [$this->attachmentMainPath($attachmentId)],
      $this->attachmentOtherPaths($attachmentId)
    ));

    $this->s3Client->deleteObjects([
      'Bucket' => $_ENV['AWS_S3_BUCKET'],
      'Delete' => [
        'Objects' => array_map(function($key) 
        {
          return ['Key' => $key];
        }, $attachments)
      ]
    ]);
  }

  private function uploadToS3($path)
  {
    $source = fopen($path, 'rb');
    $filename = basename($path);
    $time = $this->attachmentTime($path);
    $key =  $_ENV['AWS_S3_PATH'] . "/$time/$filename";
    $uploader = new ObjectUploader(
      $this->s3Client,
      $_ENV['AWS_S3_BUCKET'],
      $key,
      $source
    );
    $uploader->upload();
  }

  private function attachmentTime($path)
  {
    $pathInfo = pathinfo($path);
    $dirname = explode('/', $pathInfo['dirname']);
    $year = $dirname[count($dirname) - 2];
    $month = $dirname[count($dirname) - 1];
    return "$year/$month";
  }

  private function attachmentLocation($attachmentId)
  {
    $attachmentMainPath = $this->attachmentMainPath($attachmentId);
    $time = $this->attachmentTime($attachmentMainPath);
    return "uploads/$time";
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
      $attachmentLocation = $this->attachmentLocation($attachmentId);
      return isset($sizeInfo['file']) ? $attachmentLocation . '/' . $sizeInfo['file'] : null;
    }, $this->sizes($attachmentId)));
  }

  private function attachmentPath($sizeInfo, $mainPath)
  {
    $basedir = wp_get_upload_dir()['basedir'];
    $time = $this->attachmentTime($mainPath);
    return isset($sizeInfo['file']) ? "$basedir/$time/" . $sizeInfo['file'] : null;
  }

  private function sizes($attachmentId)
  {
    return array_keys(wp_get_attachment_metadata($attachmentId)['sizes'] ?? []); 
  }
}
