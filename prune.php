<?php
include './archive_common.php.inc';

/**
* Loop through all of the test directories cleaning out any tests that have
* finished processing and have been archived successfully.
*/
archive_scan_dirs(function ($info) {
  if (isset($info['dir']) &&
      strlen($info['dir']) &&
      is_file("{$info['dir']}/processing.done")) {
    $dir = $info['dir'];
    $files = scandir($dir);
    if ($files !== FALSE) {
      $children = array();
      foreach( $files as $file) {
        if ($file != '.' && $file != '..' && is_dir("$dir/$file")) {
          $children[] = $file;
        }
      }
      if (count($children)) {
        echo "Pruning $dir\n";
        foreach( $children as $child ) {
          delTree("$dir/$child");
        }
      }
    }
    CleanupProcessing($dir);
  }
}, true);
?>
