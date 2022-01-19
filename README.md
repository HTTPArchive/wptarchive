# wptarchive
This is the (new) archiving logic for the main HTTP Archive instance that:
* Zips the individual tests and stores them in the Google cloud storage bucket
* Extracts HAR files (with bodies) for all of the tests
  * Uploads the extracted HARs to Google storage for data flow processing/Big Query access
* Uploads the SQL dump files to Google cloud storage
* Deletes local test data that has already been processed and archived and have not been accessed recently


It is comprised of several php scripts that get scheduled in cron and run concurrently (serialized operation can not keep up with the testing).


All account information and settings is configured in a settings.inc.php file (based on the included settings.inc.php.sample).
