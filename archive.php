<?php
include './archive_common.php.inc';

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
  
  if (is_file("$dir/testing.complete")) {
    $suffix = '';

    // Re-archive anything that was archived > 30 days ago but still hasn't shown up as a valid archive
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
        echo "Archiving $id ($count tests)...\n";
        mkdir($archiveDir, 0777, true);
        $index = 0;
        foreach($children as $child) {
          $index++;
          echo "\rCompressing $index of $count...";
          ZipDirectory("$dir/$child", "$archiveDir/{$id}_{$child}.zip");
        }
        $zipFile = "$dir/$id.zip";
        if (is_file($zipFile))
          unlink($zipFile);
        echo "\rCombining tests into $zipFile...\n";
        ZipDirectory($archiveDir, $zipFile, true);
        delTree($archiveDir);
        if (is_file($zipFile)) {
          $bucket = "$bucket_base{$info['year']}_{$info['month']}_{$info['day']}_{$info['group']}$suffix";
          if (ArchiveFile($zipFile, $bucket)) {
            echo "Archiving $id Complete\n";
            file_put_contents("$dir/archive.dat", "$download_path$bucket/$id.zip");
          } else {
            echo "Archiving $id FAILED\n";
          }
          unlink($zipFile);
        }
      } else {
        // Nothing to process, mark the group as done
        file_put_contents("$dir/archive.dat", "");
        file_put_contents("$dir/archive.dat.valid", "");
      }
    }
  }
});
?>
