<?php
include './archive_common.php.inc';

/**
* Loop through all of the test directories cleaning out any tests that have
* finished processing and have been archived successfully.
*/
archive_scan_dirs(function ($info) {
  if (isset($info['dir']) && strlen($info['dir'])) {
    $dir = $info['dir'];
    $files = scandir($dir);
    if ($files !== FALSE) {
      $children = array();
      foreach( $files as $file) {
        if ($file != '.' && 
            $file != '..' &&
            is_dir("$dir/$file") &&
            file_exists("$dir/$file/.ha_archived") &&
            file_exists("$dir/$file/.ha_har")) {
          $children[] = $file;
        }
      }
      if (count($children)) {
        foreach( $children as $child ) {
          echo "Pruning $dir/$child\n";
          delTree("$dir/$child");
        }
      }
    }
    // delete the directory if it is empty
    @rmdir($dir);
  }
}, true);
?>
