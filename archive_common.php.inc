<?php
require_once(__DIR__ . '/settings.inc.php');
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
          $info['month'] = $month;
          $info['monthDir'] = $monthDir;
          if ($every_dir)
            $callback(array('dir' => $monthDir));
          $days = scandir($monthDir);
          foreach( $days as $day ) {
            $dayDir = "$monthDir/$day";
            if( is_dir($dayDir) && $day != '.' && $day != '..') {
              $info['day'] = $day;
              $info['dayDir'] = $dayDir;
              if ($every_dir)
                $callback(array('dir' => $dayDir));
              $groups = scandir($dayDir);
              foreach ($groups as $group) {
                $groupDir = "$dayDir/$group";
                if( is_dir($groupDir) && $group != '.' && $group != '..') {
                  $info['group'] = $group;
                  $info['groupDir'] = $groupDir;
                  $info['dir'] = $groupDir;
                  $info['id'] = "{$year}{$month}{$day}_{$group}";
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

function logMessage($msg) {
  global $logFile;
  $out = gmdate('Y/m/d H:i:s - ') . $msg . "\n";
  echo $out;
  if (isset($logFile))
    error_log($out, 3, $logFile);
}
?>
