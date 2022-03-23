<?php
include './archive_common.php.inc';

$UTC = new DateTimeZone('UTC');
$now = time();

// State of the various crawls
UpdateCrawlState();
checkDone();

// See if the crawls are done
function checkDone() {
  global $gMysqlServer;
  global $gMysqlDb;
  global $gMysqlUsername;
  global $gMysqlPassword;
  $db = mysqli_connect($gMysqlServer, $gMysqlUsername, $gMysqlPassword);
  if (mysqli_select_db($db, $gMysqlDb)) {
    $result = mysqli_query($db, "select * from crawls where finishedDateTime is NULL;");
    if ($result !== false) {
      $rowcount = mysqli_num_rows($result);
      echo "$rowcount crawl(s) still running.\n";
      if ($rowcount == 0) {
        $crawls_file = __DIR__ . '/crawls.json';
        if (file_exists($crawls_file)) {
          $crawls = json_decode(file_get_contents($crawls_file), true);
          if (isset($crawls) && is_array($crawls) && count($crawls) && !isset($crawls['done'])) {
            echo "Marking crawls as done.\n";
            $crawls['done'] = true;
            file_put_contents($crawls_file, json_encode($crawls));
          }
        }
      }
    }
    mysqli_close($db);
  }
}

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
  echo "Updating crawl status...\n";
  
  $db = mysqli_connect($gMysqlServer, $gMysqlUsername, $gMysqlPassword);
  if (mysqli_select_db($db, $gMysqlDb)) {
    $crawl_names = array();
    // Build a list of the crawl names by ID
    $result = mysqli_query($db, "SELECT crawlid,label,location FROM crawls;");
    if ($result !== false) {
      while ($row = mysqli_fetch_assoc($result)) {
        $id = $row['crawlid'];
        $type = $row['location'];
        if ($type == 'IE8')
          $type = 'IE';
        elseif ($type == 'iphone4')
          $type = 'iOS';
        elseif ($type == 'California:Chrome')
          $type = 'chrome';
        elseif ($type == 'California2:Chrome.3G')
          $type = 'android';
        $crawl_names[$id] = str_replace(' ', '_', $type) . '-' . str_replace(' ', '_', $row['label']);
      }
    }

    // Get the status of all of the tests in all of the crawls
    foreach ($statusTables as $table) {
      $result = mysqli_query($db, "SELECT wptid,status,crawlid FROM $table WHERE status >= 4;");
      if ($result !== false) {
        $ret = true;
        while ($row = mysqli_fetch_assoc($result)) {
          $id = $row['wptid'];
          $status = $row['status'];
          $crawl = $row['crawlid'];
          if (isset($id) && strlen($id)) {
            // Mark the test itself directly
            $test_path = './' . GetTestPath($id);
            if ($status >= 4 && is_dir($test_path) && !file_exists("$test_path/.ha_complete")) {
              // delete some test files that we don't need to keep
              $files = array('1_debug.log', '1_devtools.json.gz', '1_trace.json.gz', '1_netlog_requests.json.gz');
              foreach ($files as $file) {
                if (file_exists("$test_path/$file")) {
                  unlink("$test_path/$file");
                }
              }
              $name = 'UNKNOWN';
              if (isset($crawl_names[$crawl])) {
                $name = $crawl_names[$crawl];
                file_put_contents("$test_path/.ha_crawl", $name);
              }
              if ($status == 4) {
                file_put_contents("$test_path/.ha_success", '');
              } else {
                // We won't upload the HAR so make sure it gets marked as done
                file_put_contents("$test_path/.ha_har", '');
              }
              file_put_contents("$test_path/.ha_complete", '');
              echo "Marking test $id for crawl $name as complete\n";
            }
          }
        }        
      }
    }
    
    mysqli_close($db);
  }

  if (!$ret) {
    echo "\nFailed to query pending tests\n";
    exit(1);
  }

  return $ret;
}

?>
