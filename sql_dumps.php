<?php
include './archive_common.php.inc';

$now = time();

$baseDir = '/var/www/httparchive.dev/downloads';
if (is_file("$baseDir/archived.json")) {
  $downloads = json_decode(file_get_contents("$baseDir/archived.json"), true);
}
if (!isset($downloads) || !is_array($downloads)) {
  $downloads = array();
  file_put_contents("$baseDir/archived.json", json_encode($downloads));
}
foreach( scandir($baseDir) as $filename ){
  $matches = array();
  if (is_file("$baseDir/$filename") && 
    preg_match('/(?:httparchive_)(?:mobile_)?(?:android_)?(?:chrome_)?(.*)_[a-z\.]+/i', $filename, $matches) &&
    count($matches) > 1) {
    $modified = filemtime("$baseDir/$filename");
    $size = filesize("$baseDir/$filename");
    if (!$modified || ($modified < $now && $now - $modified > 3600)) {
      echo "$filename - Uploading to $bucket...\n";
      if (gsUpload("$baseDir/$filename")) {
        $downloads[$filename] = 
            array('url' => "https://storage.googleapis.com/httparchive/downloads/$filename", 
            'size' => $size,
            'modified' => $modified,
            'verified' => true,
            'bucket' => 'httparchive');
        file_put_contents("$baseDir/archived.json", json_encode($downloads));
        unlink("$baseDir/$filename");
      }
    }
  }
}

function gsUpload($file) {
  $ret = false;
  echo("Uploading $file to gs://httparchive/$crawlName");
  exec("gsutil -m cp \"$file\" gs://httparchive/downloads/", $output, $result);
  if ($result == 0) {
    $ret = true;
    echo("Upload Complete");
  } else {
    echo("Upload Failed");
  }
  return $ret;
}

?>
