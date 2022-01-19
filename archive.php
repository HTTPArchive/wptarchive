<?php
require_once(__DIR__ . '/archive_common.php.inc');

// Allow for multiple concurrent scans to run
$max_concurrent = 4;
$client_id = null;
$client_lock = null;
for ($client = 1; $client <= $max_concurrent && !isset($client_lock); $client++) {
  $client_lock = Lock("process-archive-$client", false, 86400);
  if (isset($client_lock)) {
    $client_id = $client;
    break;
  }
}
Unlock($lock);
if (!isset($client_lock)) {
  echo "Max number of clients ($max_concurrent) are already running\n";
  exit(0);
}
$lock = $client_lock;
$logFile = __DIR__ . "/archive_{$client_id}.log";
if (is_file($logFile))
  unlink($logFile);
logMessage("Starting processing as client $client_id, logging to $logFile");

/**
* Loop through all of the test directories checking on the status of the
* individual tests and marking directories that are ready for further
* processing.
*/
archive_scan_dirs(function ($info) {
  $dir = $info['dir'];
  $id = $info['id'];
  
  $dir_lock = Lock("archive-$id", false, 86400);
  if (isset($dir_lock)) {
    $archiveDir = "$dir/archive";
    if (is_dir($archiveDir))
      delTree($archiveDir);
      
    $children = array();
    $files = scandir($dir);
    if ($files !== FALSE) {
      foreach( $files as $file) {
        if ($file != '.' &&
            $file != '..' &&
            strpos($file, 'archive') === false &&
            is_dir("$dir/$file") &&
            !file_exists("$dir/$file/.ha_archived") &&
            file_exists("$dir/$file/.ha_complete")) {
          $children[] = $file;
        }
      }
    }
    
    $count = count($children);
    if ($count > 0) {
      logMessage("Archiving $id ($count tests)...");
      mkdir($archiveDir, 0777, true);
      $index = 0;
      $archived = array();
      foreach($children as $child) {
        $index++;
        logMessage("Compressing $index of $count - $id/$child...");
        ZipDirectory("$dir/$child", "$archiveDir/{$id}_{$child}.zip", true);
        if (file_exists("$archiveDir/{$id}_{$child}.zip")) {
          $archived[] = "$dir/$child";
        } else {
          file_put_contents("$dir/$child/.ha_archived", '');
        }
      }
      // Upload all of the zip files to cloud storage
      if (gsUploadResults($archiveDir)) {
        foreach ($archived as $testdir) {
          file_put_contents("$testdir/.ha_archived", '');
        }
      }
    }
    // clean up the archive zip files
    if (is_dir($archiveDir))
      delTree($archiveDir);

    Unlock($dir_lock);
  } else {
    logMessage("$id is already being processed");
  }
});

function gsUploadResults($dir) {
  $ret = false;
  logMessage("Uploading $dir to gs://httparchive/results");
  exec("gsutil -m cp -r -n \"$dir/**\" gs://httparchive/results/", $output, $result);
  if ($result == 0) {
    $ret = true;
    logMessage("Upload Complete");
  } else {
    logMessage("Upload Failed");
  }
  return $ret;
}

?>
