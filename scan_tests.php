<?php
include './archive_common.php.inc';

$UTC = new DateTimeZone('UTC');
$now = time();

// State of the various crawls
$crawls = array();
$pendingTests = array();
$finishedTests = array();
$checkedCrawl = false;

/**
* Loop through all of the test directories checking on the status of the
* individual tests and marking directories that are ready for further
* processing.
*/
archive_scan_dirs(function ($info) {
  global $UTC, $now, $checkedCrawl, $pendingTests, $finishedTests, $crawls;
  $dir = $info['dir'];
  $id = $info['id'];
  
  // Only look at tests that were started at least 1 day ago (eliminates any possible race conditions)
  $date = DateTime::createFromFormat('ymd', "{$info['year']}{$info['month']}{$info['day']}", $UTC);
  $daytime = $date->getTimestamp();
  $elapsed = max($now - $daytime, 0) / 86400;
  
  if ($elapsed >= 1 && !is_file("$dir/testing.complete")) {
    if (!$checkedCrawl) {
      $checkedCrawl = UpdateCrawlState();
    }
    $pending = 0;
    $complete = 0;
    if (isset($pendingTests[$id]))
      $pending = $pendingTests[$id];
    if (isset($finishedTests[$id]))
      $complete = count($finishedTests[$id]);
    $total = $pending + $complete;
    echo "$id : $complete of $total complete ($pending pending)\n";
    if ($complete > 0 && !$pending) {
      // Write out the test and crawl information
      $tests = array('crawls' => $crawls, 'tests' => $finishedTests[$id]);
      file_put_contents("$dir/tests.json", json_encode($tests));
      // mark it as complete
      file_put_contents("$dir/testing.complete", '');
    } elseif (!$total) {
      // flag any groups that are not part of a crawl as complete
      file_put_contents("$dir/testing.complete", '');
    }
  }
  
  // See if all of the individual processing steps are done.  If so, mark mrocessing as complete.
  echo "Checking $dir\n";
  if (is_file("$dir/testing.complete") &&
      is_file("$dir/har.complete") &&
      is_file("$dir/archive.dat") &&
      ArchiveExists("$dir/archive.dat")) {
    MarkDone($dir);
  } else {
    if (!is_file("$dir/testing.complete"))
      echo "  Missing $dir/testing.complete\n";
    if (!is_file("$dir/har.complete"))
      echo "  Missing $dir/har.complete\n";
    if (!is_file("$dir/archive.dat"))
      echo "  Missing $dir/archive.dat\n";
    else if (!ArchiveExists("$dir/archive.dat"))
      echo "  Archive not valid\n";
  }
});

/**
* Update the state of the current crawl
* 
*/
function UpdateCrawlState() {
  $ret = false;
  global $gMysqlServer;
  global $gMysqlDb;
  global $gMysqlUsername;
  global $gMysqlPassword;
  global $statusTables;
  global $pendingTests;
  global $finishedTests;
  global $crawls;

  // get counts for test groups that are in some form of processing
  $pendingTests = array();
  $finishedTests = array();
  $crawls = array();

  echo "Updating crawl status...\n";
  
  $db = mysql_connect($gMysqlServer, $gMysqlUsername, $gMysqlPassword);
  if (mysql_select_db($gMysqlDb)) {
    // Get the status of all of the tests in all of the crawls
    foreach ($statusTables as $table) {
      $result = mysql_query("SELECT wptid,status,crawlid FROM $table;", $db);
      if ($result !== false) {
        $ret = true;
        while ($row = mysql_fetch_assoc($result)) {
          $id = $row['wptid'];
          $status = $row['status'];
          $crawl = $row['crawlid'];
          if (preg_match('/^([A-Z0-9]+_[A-Z0-9]+)_[A-Z0-9]+$/', $id, $matches)) {
            $group = $matches[1];
            if ($status < 4) {
              if (!isset($pendingTests[$group]))
                $pendingTests[$group] = 0;
              $pendingTests[$group]++;
            }
            // Keep track of "completed" tests (with no processing error)
            if ($status == 4) {
              if (!isset($finishedTests[$group]))
                $finishedTests[$group] = array();
              $finishedTests[$group][] = array('id' => $id, 'crawl' => $crawl);
            }
            if (!isset($crawls[$crawl]))
              $crawls[$crawl] = array('id' => $crawl);
            if (!isset($crawls[$crawl]['groups']))
              $crawls[$crawl]['groups'] = array();
            $crawls[$crawl]['groups'][$group] = array('id' => $group);
          }
        }        
      }
    }
    
    // Get the descriptions for all of the crawls
    if (count($crawls)) {
      foreach($crawls as $crawl => &$crawl_data) {
        $result = mysql_query("SELECT label,location,finishedDateTime FROM crawls WHERE crawlid=$crawl;", $db);
        if ($result !== false) {
          if ($row = mysql_fetch_assoc($result)) {
            $type = $row['location'];
            if ($type == 'IE8')
              $type = 'IE';
            elseif ($type == 'iphone4')
              $type = 'iOS';
            elseif ($type == 'California:Chrome')
              $type = 'chrome';
            elseif ($type == 'California2:Chrome.3G')
              $type = 'android';
            $crawl_data['name'] = str_replace(' ', '_', $type) . '-' . str_replace(' ', '_', $row['label']);
            $crawl_data['finished'] = isset($row['finishedDateTime']) ? true : false;
          }
        }
        echo "\n";
      }
    }
    mysql_close($db);
  }
  ksort($pendingTests);

  echo "Current crawls:\n";  
  if (count($crawls)) {
    foreach($crawls as $crawl => &$crawl_data) {
      echo "  {$crawl_data['id']}: {$crawl_data['name']} - ";
      echo $crawl_data['finished'] ? "FINISHED\n" : "Running\n";
      
      // Track the status of all of the groups that have tests for each crawl
      if (!is_dir('./results/crawl/')) {
        mkdir('./results/crawl/', 0777, true);
      }
      $crawlFile = "./results/crawl/{$crawl_data['id']}.json";
      if ($crawl_data['finished'] && !is_file($crawlFile)) {
        file_put_contents($crawlFile, json_encode($crawl_data));
      }
    }
  } else {
    echo "  NONE\n";  
  }
  
  if ($ret) {
    echo "\nPending Tests in each group:\n";
    foreach($pendingTests as $group => $count) {
      echo "  $group: $count\n";
    }
    echo "\nFinished Tests in each group:\n";
    foreach($finishedTests as $group => $tests) {
      $count = count($tests);
      echo "  $group: $count\n";
    }
  } else {
    echo "\nFailed to query pending tests\n";
    exit(1);
  }
  
  
  return $ret;
}

?>
