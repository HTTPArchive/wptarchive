<?php
include './archive_common.php.inc';
require_once('./include/TestInfo.php');
require_once('./include/TestResults.php');
require_once('./har/HttpArchiveGenerator.php');

// Allow for multiple concurrent scans to run
$max_concurrent = 2;
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

/**
* Loop through all of the test directories checking on the status of the
* individual tests and marking directories that are ready for further
* processing.
*/
archive_scan_dirs(function ($info) {
  global $now, $bucket_base, $download_path;
  $dir = $info['dir'];
  $id = $info['id'];
  $dir_lock = Lock("har-$id", false, 86400);
  if (isset($dir_lock)) {
    if (is_file("$dir/testing.complete") && !is_file("$dir/har.complete")) {
      if (is_file("$dir/tests.json")) {
        $tests = json_decode(file_get_contents("$dir/tests.json"), true);
      }
      if (isset($tests) &&
          is_array($tests) &&
          isset($tests['tests']) &&
          count($tests['tests']) &&
          isset($tests['crawls']) &&
          count($tests['crawls'])) {
        $ok = true;
        logMessage("$id - Processing");
        foreach ($tests['crawls'] as $crawlId => $crawl) {
          if (!is_file("$dir/$crawlId.har")) {
            $name = $crawl['name'];
            $type = array_shift(explode('-', $name));
            $harDir = "$dir/$name";
            if (is_dir($harDir))
              delTree($harDir);
            $traceDir = "$dir/traces-$name";
            if (is_dir($traceDir))
              delTree($traceDir);
            $testIDs = array();
            foreach($tests['tests'] as $test) {
              if (isset($test['crawl']) && isset($test['id']) && $test['crawl'] == $crawlId) {
                $testIDs[] = $test['id'];
              }
            }
            $count = count($testIDs);
            if ($count > 0) {
              logMessage("$id - $count $type tests: $harDir");
              mkdir($harDir, 0777, true);
              if (CollectHARs($testIDs, $harDir)) {
                logMessage("Uploading to Google storage...");
                if (gsUpload($harDir, $name)) {
                  logMessage("Upload complete...");
                } else {
                  $ok = false;
                }
              } else {
                $ok = false;
              }
              delTree($harDir);
              logMessage("$id - $count $type tests (traces): $traceDir");
              mkdir($traceDir, 0777, true);
              if (CollectTraces($testIDs, $traceDir))
                gsUpload($traceDir, "traces-$name");
              delTree($traceDir);
            }
          }
        }
        if ($ok) {
          file_put_contents("$dir/har.complete", '');
        }
      } else {
        // Bogus test data, mark it as done
        file_put_contents("$dir/har.complete", '');
        logMessage("$id - No tests available");
      }
    } else {
      if (!is_file("$dir/testing.complete"))
        logMessage("$id - Tests still running");
      if (is_file("$dir/har.complete"))
        logMessage("$id - HAR already processed");
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
  // Load the completed har uploads
  if (is_file('./results/crawl/har.uploads'))
    $uploads = json_decode(file_get_contents('./results/crawl/har.uploads'), true);
  if (!isset($uploads) || !is_array($uploads))
    $uploads = array();
  $dirty = false;
    
  // get the list of crawls
  $files = glob('./results/crawl/*.json');  
  if ($files !== FALSE && is_array($files)) {
    foreach ($files as $file) {
      $crawl_id = basename($file, '.json');
      if (!isset($uploads[$crawl_id])) {
        $crawl = json_decode(file_get_contents($file), true);
        if (isset($crawl) && is_array($crawl) && isset($crawl['groups']) && isset($crawl['name'])) {
          $upload_done = true;
          logMessage("$crawl_id: ({$crawl['name']}) Checking crawl status...");
          foreach($crawl['groups'] as $group) {
            if (preg_match('/^([0-9][0-9])([0-9][0-9])([0-9][0-9])_([0-9a-zA-Z]+)$/', $group['id'], $matches)) {
              $group_dir = "./results/{$matches[1]}/{$matches[2]}/{$matches[3]}/{$matches[4]}";
              if (is_dir($group_dir)) {
                if (!is_file("$group_dir/processing.done")) {
                  logMessage("$crawl_id: Group {$group['id']} is still being processed/running...");
                  $upload_done = false;
                }
              } else {
                $upload_done = false;
              }
            }
          }
          if ($upload_done) {
            logMessage("$crawl_id: Upload complete, marking done...");
            if (gsMarkDone($crawl['name'])) {
              $dirty = true;
              $uploads[$crawl_id] = $crawl['name'];
            } else {
              logMessage("$crawl_id: Error marking crawl as done");
            }
          }
        }
      }
    }
  }
  
  if ($dirty) {
    file_put_contents('./results/crawl/har.uploads', json_encode($uploads));
  }
}

function CollectHARs($tests, $outDir) {
  $count = 0;
  $total = count($tests);
  $index = 0;
  foreach($tests as $id) {
    $index++;
    logMessage("[$index/$total] Collecting HAR ($id)...");
    $testPath = './' . GetTestPath($id);
    $testInfo = TestInfo::fromFiles($testPath, false);
    $testResults = TestResults::fromFiles($testInfo);
    $run = $testResults->getMedianRunNumber("SpeedIndex", 0);
    $options = array('bodies' => 1, 'run' => $run, 'cached' => 0);
    $archiveGenerator = new HttpArchiveGenerator($testInfo, $options);
    $har = $archiveGenerator->generate();
    if (isset($har) && strlen($har) > 10) {
      $harFile = "$outDir/$id.har";
      file_put_contents($harFile, $har);
      $size = filesize($harFile);
      logMessage("[$index/$total] Compressing $harFile ($size bytes)");
      exec("gzip -7 \"$harFile\"");
      $harFile .= '.gz';
      if (is_file($harFile)) {
        $size = filesize($harFile);
        logMessage("[$index/$total] Validating $harFile ($size bytes)");
        exec("gzip -t \"$harFile\"", $out, $return);
        if (!$return) {
          $count++;
        } else {
          logMessage("Invalid gzip file: $return");
          @unlink($harFile);
        }
      }
    }
  }
  logMessage("Collected $count of $total HAR files to $outDir");
  return $count;
}

function CollectTraces($tests, $outDir) {
  $count = 0;
  $test_count = 0;
  $total = count($tests);
  $index = 0;
  foreach($tests as $id) {
    $index++;
    logMessage("Collecting traces from test $index of $total ($id)...");
    $testPath = './' . GetTestPath($id);
    $files = scandir($testPath);
    $found = false;
    foreach ($files as $file) {
      $pos = strpos($file, '_trace.json.gz');
      if ($pos > 0) {
        $count++;
        $found = true;
        $src = "$testPath/$file";
        $dst = "$outDir/$id.json.gz";
        copy($src, $dst);
      }
    }
    if ($found)
      $test_count++;
  }
  logMessage("Collected $count traces from $test_count of $total tests in $outDir");
  return $count;
}

function gsUpload($dir, $crawlName) {
  $ret = false;
  logMessage("Uploading $dir to gs://httparchive/$crawlName");
  exec("/home/httparchive/gsutil/gsutil -m cp -r -n \"$dir\" gs://httparchive/", $output, $result);
  if ($result == 0) {
    $ret = true;
    logMessage("Upload Complete");
  } else {
    logMessage("Upload Failed");
  }
  return $ret;
}

function gsMarkDone($crawlName) {
  $ret = false;
  $doneFile = realpath(__DIR__ . '/done.txt');
  exec("/home/httparchive/gsutil/gsutil -q cp \"$doneFile\" gs://httparchive/$crawlName/done", $output, $result);
  if ($result == 0)
    $ret = true;
  return $ret;
}
?>
