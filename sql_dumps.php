<?php
include './archive_common.php.inc';
require_once('har.inc.php');

$now = time();

$updated = false;
$baseDir = '/var/www/httparchive.dev/downloads';
if (is_file("$baseDir/archived.json")) {
  $downloads = json_decode(file_get_contents("$baseDir/archived.json"), true);
}
if (!isset($downloads) || !is_array($downloads)) {
  $downloads = array();
  $updated = true;
}
foreach( scandir($baseDir) as $filename ){
  $matches = array();
  if (is_file("$baseDir/$filename") && 
    preg_match('/(?:httparchive_)(?:mobile_)?(?:android_)?(?:chrome_)?(.*)_[a-z\.]+/i', $filename, $matches) &&
    count($matches) > 1) {
    $modified = filemtime("$baseDir/$filename");
    $size = filesize("$baseDir/$filename");
    $bucket = "httparchive_downloads_" . $matches[1];
    if (array_key_exists($filename, $downloads) &&
      $downloads[$filename]['size'] != $size) {
      if (array_key_exists('bucket', $downloads[$filename]))
        DeleteArchive($filename, $downloads[$filename]['bucket']);
      else
        DeleteArchive($filename, 'httparchive_downloads');
      unset($downloads[$filename]);
    }
    if (!array_key_exists($filename, $downloads) &&
        (!$modified || ($modified < $now && $now - $modified > 86400))) {
      echo "$filename - Uploading to $bucket...\n";
      if (ArchiveFile("$baseDir/$filename", $bucket)) {
        $downloads[$filename] = 
            array('url' => "{$download_path}$bucket/$filename", 
            'size' => $size,
            'modified' => $modified,
            'verified' => false,
            'bucket' => $bucket);
        $updated = true;
      }
    }
  }
}
if ($updated) {
  file_put_contents("$baseDir/archived.json", json_encode($downloads));
}

$updated = false;
foreach($downloads as $filename => &$download){
  if (!$download['verified']) {
    echo "$filename - Checking {$download['url']}...";
    if (URLExists($download['url'])) {
      echo "exists\n";
      $download['verified'] = true;
      $updated = true;
    } else {
      echo "missing\n";
    }
  }
  if ($download['verified'] && is_file("$baseDir/$filename")) {
    // only delete the local copy after 1 month
    $modified = filemtime("$baseDir/$filename");
    if (!$modified || ($modified < $now && $now - $modified > 2592000)) {
      echo "$filename is in archive, deleting...\n";
      unlink("$baseDir/$filename");
    }
  }
}
if ($updated) {
  file_put_contents("$baseDir/archived.json", json_encode($downloads));
}

?>
