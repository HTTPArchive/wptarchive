<?php
include './archive_common.php.inc';
require_once('./include/TestInfo.php');
require_once('./include/TestResults.php');
require_once('./har/HttpArchiveGenerator.php');

// Allow for multiple concurrent scans to run
$max_concurrent = 10;
$client_id = null;
$client_lock = null;
for ($client = 1; $client <= $max_concurrent && !isset($client_lock); $client++) {
  $client_lock = Lock("process-har-$client", false, 86400);
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
$logFile = __DIR__ . "/har_{$client_id}.log";
if (is_file($logFile))
  unlink($logFile);
logMessage("Starting processing as client $client_id, logging to $logFile");

$now = time();
$upload_count = 0;

/**
* Loop through all of the test directories checking on the status of the
* individual tests and marking directories that are ready for further
* processing.
*/
archive_scan_dirs(function ($info) {
  global $now, $bucket_base, $download_path, $upload_count;
  $dir = $info['dir'];
  $id = $info['id'];
  $dir_lock = Lock("har-$id", false, 86400);
  if (isset($dir_lock)) {
    // build a list of test ID's that need to be collected
    $tests = array();
    $files = scandir($dir);
    if ($files !== FALSE) {
      foreach( $files as $file) {
        if ($file != '.' &&
            $file != '..' &&
            is_dir("$dir/$file") &&
            file_exists("$dir/$file/.ha_success") &&
            file_exists("$dir/$file/.ha_crawl") &&
            !file_exists("$dir/$file/.ha_har")) {
          $crawl_name = file_get_contents("$dir/$file/.ha_crawl");
          if (!isset($tests[$crawl_name])) {
            $tests[$crawl_name] = array();
          }
          $tests[$crawl_name][] = "{$id}_{$file}";
          $upload_count++;
        }
      }
    }

    if (count($tests)) {
      logMessage("$id - Processing");
      $harDir = "$dir/har";
      foreach ($tests as $crawl => $testIDs) {
        // Update the list of crawls that need to be marked as done
        $crawls = null;
        $crawls_file = __DIR__ . '/crawls.json';
        if (file_exists($crawls_file)) {
          $crawls = json_decode(file_get_contents($crawls_file), true);
        }
        if (!isset($crawls) || !is_array($crawls))
          $crawls = array();
        if (!isset($crawls[$crawl])) {
          $crawls[$crawl] = $crawl;
          file_put_contents($crawls_file, json_encode($crawls));
        }
        if (is_dir($harDir))
          delTree($harDir);
        mkdir($harDir, 0777, true);
        if (CollectHARs($testIDs, $harDir)) {
          logMessage("Uploading to Google storage...");
          if (gsUpload($harDir, $crawl)) {
            logMessage("Upload complete...");
            foreach ($testIDs as $test) {
              $testPath = './' . GetTestPath($test);
              file_put_contents("$testPath/.ha_har", '');
            }
          }
        }
      }
      if (is_dir($harDir))
        delTree($harDir);
    }
    Unlock($dir_lock);
  } else {
    logMessage("$id is already being processed");
  }
});

// Check the list of crawls that have completed to see if all of the uploads have also completed
$upload_lock = Lock("har-uploads", false, 86400);
if (isset($upload_lock)) {
  CheckUploads();
  Unlock($upload_lock);
}

function CheckUploads() {
  global $upload_count;
  if ($upload_count == 0) {
    $crawls_file = __DIR__ . '/crawls.json';
    if (file_exists($crawls_file)) {
      $crawls = json_decode(file_get_contents($crawls_file), true);
      if (isset($crawls) && is_array($crawls) && isset($crawls['done']) && $crawls['done']) {
        $ok = true;
        logMessage("Marking crawls as done.");
        foreach ($crawls as $crawl) {
          if (is_string($crawl)) {
            logMessage("Marking $crawl as done.");
            if (!gsMarkDone($crawl)) {
              $ok = false;
              logMessage("error.");
            }
          }
        }
        if ($ok) {
          unlink($crawls_file);
        }
      }
    }
  }
}

function CollectHARs($tests, $outDir) {
  $count = 0;
  $total = count($tests);
  $index = 0;
  foreach($tests as $id) {
    $index++;
    $ok = false;
    try {
      //logMessage("[$index/$total] Collecting HAR ($id)...");
      $start = microtime(true);
      $testPath = './' . GetTestPath($id);
      $harFile = "$outDir/$id.har.gz";
      if (is_file("$testPath/1_har.json.gz")) {
        if (copy("$testPath/1_har.json.gz", $harFile)) {
          $size = filesize($harFile);
          $elapsed = microtime(true) - $start;
          logMessage("[$index/$total] $id ($size bytes) - $elapsed sec");
          if (is_file($harFile)) {
            $ok = true;
            $count++;
          }
        }
      } else {
        $testInfo = TestInfo::fromFiles($testPath, false);
        if (isset($testInfo)) {
          $options = array('bodies' => 1, 'run' => 1, 'cached' => 0, 'lighthouse' => 1);
          $archiveGenerator = new HttpArchiveGenerator($testInfo, $options);
          $har = $archiveGenerator->generate();
          if (isset($har) && strlen($har) > 10) {
            $gz = gzopen($harFile, 'w1');
            if ($gz) {
              gzwrite($gz, $har);
              gzclose($gz);
              $size = filesize($harFile);
              $elapsed = microtime(true) - $start;
              logMessage("[$index/$total] $id ($size bytes) - $elapsed sec");
              if (is_file($harFile)) {
                $ok = true;
                $count++;
              }
            }
          }
        }
      }
    } catch (Exception $e) {
    }
    if (!$ok) {
      $testPath = './' . GetTestPath($id);
      file_put_contents("$testPath/.ha_har", '');
    }
  }
  logMessage("Collected $count of $total HAR files to $outDir");
  return $count;
}

function gsUpload($dir, $crawlName) {
  $ret = false;
  $cwd = getcwd();
  chdir($dir);
  logMessage("Uploading $dir to gs://httparchive/$crawlName");
  exec("gsutil -m cp -r -n . gs://httparchive/$crawlName/", $output, $result);
  if ($result == 0) {
    $ret = true;
    logMessage("Upload Complete");
  } else {
    logMessage("Upload Failed");
  }
  chdir($cwd);
  return $ret;
}

function gsMarkDone($crawlName) {
  $ret = false;
  $doneFile = realpath(__DIR__ . '/done.txt');
  exec("gsutil -q cp \"$doneFile\" gs://httparchive/$crawlName/done", $output, $result);
  if ($result == 0)
    $ret = true;
  return $ret;
}
?>
