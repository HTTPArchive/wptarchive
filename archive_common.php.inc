<?php
include './settings.inc.php';
if (!isset($wpt_dir)) {
  echo "$wpt_dir missing from settings.inc.php";
  exit(1);
}

chdir($wpt_dir);
$debug = true;
include 'common.inc';
error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(86000);

$script = basename($_SERVER['PHP_SELF'], '.php');
$lock = Lock("Archive $script", false, 86400);
if (!isset($lock)) {
  echo "Archive process for $script is already running\n";
  exit(0);
}

// List of files that are used during processing (to clean up when complete)
$processing_files = array(
    'testing.complete',
    'archive.dat.valid',
    'har.complete',
    'tests.json');

register_shutdown_function(function() {
  global $lock;
  if (isset($lock))
    Unlock($lock);
});

function archive_scan_dirs($callback, $every_dir = false) {
  $info = array();
  $endDate = (int)gmdate('ymd');
  $years = scandir('./results');
  foreach( $years as $year ) {
    $yearDir = realpath("./results/$year");
    if (is_numeric($year) && is_dir($yearDir) && $year != '.' && $year != '..') {
      $info['year'] = $year;
      $info['yearDir'] = $yearDir;
      if ($every_dir)
        $callback(array('dir' => $yearDir));
      $months = scandir($yearDir);
      foreach( $months as $month ) {
        $monthDir = "$yearDir/$month";
        if (is_dir($monthDir) && $month != '.' && $month != '..') {
          if (is_file("$monthDir/archive.dat"))
            file_put_contents("$monthDir/processing.done", '');
          $info['month'] = $month;
          $info['monthDir'] = $monthDir;
          if ($every_dir)
            $callback(array('dir' => $monthDir));
          if (!is_file("$monthDir/processing.done")) {
            $days = scandir($monthDir);
            foreach( $days as $day ) {
              $dayDir = "$monthDir/$day";
              if( is_dir($dayDir) && $day != '.' && $day != '..') {
                $info['day'] = $day;
                $info['dayDir'] = $dayDir;
                if ($every_dir)
                  $callback(array('dir' => $dayDir));
                if (!is_file("$dayDir/processing.done")) {
                  $groups = scandir($dayDir);
                  foreach ($groups as $group) {
                    $groupDir = "$dayDir/$group";
                    if( is_dir($groupDir) && $group != '.' && $group != '..') {
                      $info['group'] = $group;
                      $info['groupDir'] = $groupDir;
                      $info['dir'] = $groupDir;
                      $info['id'] = "{$year}{$month}{$day}_{$group}";
                      if ($every_dir || !is_file("$groupDir/processing.done")) {
                        $callback($info);
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}

function ArchiveExists($archiveFile) {
  $exists = false;
  
  if (is_file("$archiveFile.valid")) {
    $exists = true;
  } else {
    echo "Checking archive for $archiveFile\n";
    $url = file_get_contents($archiveFile);
    echo "  URL: $url\n";
    if ($url !== FALSE && strlen($url)) {
      $exists = URLExists($url);
      if ($exists) {
        file_put_contents("$archiveFile.valid", '');
        echo "  Archive exists, creating $archiveFile.valid\n";
      } else {
        echo "  Archive missing\n";
      }
    }
  }

  return $exists;
}

function URLExists($url) {
  $exists = false;
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_URL, $url);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
  curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  $result = curl_exec($curl);
  if ($result !== false) {
    $split = strpos($result, '200 OK');
    if ($split !== false) {
      $result = substr($result, $split);
      if (preg_match('/Content-Length:([ 0-9]*)/i', $result, $matches)) {
        $size = (int)trim($matches[1]);
        if ($size > 4096) {
          $exists = true;
        }
      }
    }
  }
  curl_close($curl);
  return $exists;
}

function ArchiveFile($file, $bucket) {
  global $archive_headers, $upload_path;
  $ret = true;
  if (is_file($file)) {
    $size = filesize($file);
    if ($size) {
      $url = "$upload_path$bucket/" . basename($file);
      echo "Uploading $file\n";
      echo " Size: $size\n";
      echo " to $url\n";
      echo " Headers: " . json_encode($archive_headers) . "\n";
      $c = curl_init();
      curl_setopt($c, CURLOPT_URL, $url);
      curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($c, CURLOPT_NOPROGRESS, false);
      curl_setopt($c, CURLOPT_HTTPHEADER, $archive_headers);
      curl_setopt($c, CURLOPT_PUT, true);
      curl_setopt($c, CURLOPT_INFILESIZE, $size);
      $fp = fopen($file, "r");
      curl_setopt($c, CURLOPT_INFILE, $fp);
      if (curl_exec($c) === false) {
        $ret = false;
      }
      curl_close($c);
      fclose($fp); 
    }
  }
  return $ret;
}

/**
* Delete an archived item in case it exists already
*/
function DeleteArchive($file, $bucket) {
  global $archive_headers, $upload_path;
  $c = curl_init();
  $url = "$upload_path$bucket/$file";
  curl_setopt($c, CURLOPT_URL, $url);
  curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($c, CURLOPT_NOPROGRESS, false);
  curl_setopt($c, CURLOPT_HTTPHEADER, $archive_headers);
  curl_setopt($c, CURLOPT_CUSTOMREQUEST, "DELETE");
  curl_exec($c);
  curl_close($c);
}

function ZipDirectory($dir, $zipFile, $store_only = false) {
  $ret = false;
  if (is_dir($dir)) {
    $cwd = getcwd();
    chdir($dir);
    $options = $store_only ? '-r0' : '-r7';
    exec("zip $options \"$zipFile\" *");
    chdir($cwd);
  }
  return $ret;
}

/**
* Mark a given directory as done (cleaning up any left over files)
* 
* @param mixed $dir
*/
function MarkDone($dir) {
  file_put_contents("$dir/processing.done", '');
  CleanupProcessing($dir);
}

function CleanupProcessing($dir) {
  global $processing_files;
  if (is_file("$dir/processing.done")) {
    foreach ($processing_files as $file) {
      if (is_file("$dir/$file")) {
        unlink("$dir/$file");
      }
    }
  }
}

function logMessage($msg) {
  global $logFile;
  $out = gmdate('Y/m/d H:i:s - ') . $msg . "\n";
  echo $out;
  if (isset($logFile))
    error_log($out, 3, $logFile);
}
?>
