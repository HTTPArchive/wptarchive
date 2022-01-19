<?php
include './archive_common.php.inc';

$baseDir = '/var/www/httparchive.dev/downloads';
if (is_file("$baseDir/archived.json")) {
  $downloads = json_decode(file_get_contents("$baseDir/archived.json"), true);
}
if (!isset($downloads) || !is_array($downloads)) {
  exit(0);
}

// Go through the existing dumps and move them to gcs
foreach($downloads as $filename => &$download) {
    $parts = parse_url($download['url']);
    $host = $parts['host'];
    $path = '/var/www/wptarchive/' . $filename;
    if( $host != "storage.googleapis.com") {
        if (download($download['url'], $path)) {
            if (is_file($path)) {
                if (gsUpload($path)) {
                    $downloads[$filename] = 
                        array('url' => "https://storage.googleapis.com/httparchive/downloads/$filename", 
                            'size' => $size,
                            'modified' => $modified,
                            'verified' => true,
                            'bucket' => 'httparchive');
                    file_put_contents("$baseDir/archived.json", json_encode($downloads));
                }
            }
        }
    }
}

function download($url, $path) {
    $ret = false;
    echo("Downloading $url to $path\n");
    $command = "wget \"$url\" -O \"$path\"";
    echo($command . "\n");
    exec($command, $output, $result);
    if ($result == 0) {
      $ret = true;
      echo("Download Complete\n");
    } else {
      echo("Download Failed\n");
    }
    return $ret;
  }

function gsUpload($file) {
  $ret = false;
  echo("Uploading $file to gs://httparchive/$crawlName\n");
  exec("gsutil -m mv \"$file\" gs://httparchive/downloads/", $output, $result);
  if ($result == 0) {
    $ret = true;
    echo("Upload Complete\n");
  } else {
    echo("Upload Failed\n");
  }
  return $ret;
}

?>
