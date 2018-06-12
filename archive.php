<?php
require_once(__DIR__ . '/archive_common.php.inc');

// Allow for multiple concurrent scans to run
$max_concurrent = 3;
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

$now = time();

/**
* Loop through all of the test directories checking on the status of the
* individual tests and marking directories that are ready for further
* processing.
*/
archive_scan_dirs(function ($info) {
  global $now, $bucket_base, $download_path;
  $dir = $info['dir'];
  $id = $info['id'];
  
  $dir_lock = Lock("archive-$id", false, 86400);
  if (isset($dir_lock)) {
    if (is_file("$dir/testing.complete") && is_file("$dir/har.complete")) {
      $suffix = '';

      // Re-archive anything that was archived > 2 days ago but still hasn't shown up as a valid archive
      if (is_file("$dir/archive.dat") && !ArchiveExists("$dir/archive.dat")) {
        $modified = filemtime("$dir/archive.dat");
        if ($modified > 0 && $modified < $now && $now - $modified > 2592000) {
          $suffix = '_' . date('ymd');
          unlink("$dir/archive.dat");
        }
      }

      $archiveDir = "$dir/archive";
      if (is_dir($archiveDir))
        delTree($archiveDir);
        
      if (!is_file("$dir/archive.dat")) {
        $children = array();
        $files = scandir($dir);
        if ($files !== FALSE) {
          foreach( $files as $file) {
            if ($file != '.' &&
                $file != '..' &&
                strpos($file, 'archive') === false &&
                is_dir("$dir/$file")) {
              $children[] = $file;
            }
          }
        }
        
        $count = count($children);
        if ($count > 0) {
          logMessage("Archiving $id ($count tests)...");
          mkdir($archiveDir, 0777, true);
          $index = 0;
          foreach($children as $child) {
            $index++;
            logMessage("Compressing $index of $count - $id/$child...");
            ZipDirectory("$dir/$child", "$archiveDir/{$id}_{$child}.zip", true);
          }
          $zipFile = "$dir/$id.zip";
          if (is_file($zipFile))
            unlink($zipFile);
          logMessage("Combining tests into $zipFile...");
          ZipDirectory($archiveDir, $zipFile, true);
          delTree($archiveDir);
          if (is_file($zipFile)) {
            $bucket = "$bucket_base{$info['year']}_{$info['month']}_{$info['day']}_{$info['group']}$suffix";
            logMessage("Uploading $zipFile to $bucket");
            if (ArchiveFile($zipFile, $bucket)) {
              logMessage("Archiving $id Complete");
              file_put_contents("$dir/archive.dat", "$download_path$bucket/$id.zip");
            } else {
              logMessage("Archiving $id FAILED");
            }
            unlink($zipFile);
          } else {
            logMessage("Failed to combine files");
          }
        } else {
          // Nothing to process, mark the group as done
          file_put_contents("$dir/archive.dat", "");
          file_put_contents("$dir/archive.dat.valid", "");
          logMessage("$id - No tests to Archive");
        }
      } else {
        logMessage("$id - Already Archived");
      }
    } else {
      logMessage("$id - Tests still running");
    }
    Unlock($dir_lock);
  } else {
    logMessage("$id is already being processed");
  }
});
?>
