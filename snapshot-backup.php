<?php
/**
 * snapshot-backup.php
 * CLI script used for automating backup of DigitalOcean volumes.
 *
 * Usage instructions:
 *  1. Change configuration values to match your setup.
 *  2. Use 'crontab -e' to configure script to run:
 *     # Backup our data volume using a snapshot each day at 6am
 *     0 6 * * * /usr/bin/php /root/snapshot-backup.php
 *
 * Note that the script will clean up(delete) any snapshots(with the given
 * prefix) that does not fit within any of the specified backup slots.
 * The standard configuration will create 4 different backups if triggered at
 * the same time each day:
 *   One that is less than 24 hours old
 *   One that is 1 - 2 days old
 *   One that is 2 - 7 days old
 *   One that is 8 - 14 days old
 *
 * Create by Frode Petterson in 2016. MIT licensed.
 */

// Configuration
$token = 'abc123abc123abc123abc123';
$volumeName = 'volume-fra1-01';
$backupPrefix = 'auto-';
$backupSlots = array(
  3600 * 18,      // < 18 hours
  3600 * 24 * 2,  // 18 - 2 days
  3600 * 24 * 7,  // 2 - 7 days
  3600 * 24 * 14  // 8 - 14 days
);

/**
 * Class for communicating with the Digitalocean API
 */
class DigitalOceanAPI {
  private $url, $token;

  /**
   * Constructor. Checks and prepares the required dependencies.
   *
   * @param string $url
   * @param string $token
   */
  public function __construct($url, $token) {

    // Check dependencies
    if (!function_exists('curl_version')) {
      throw new Exception('PHP requires the cURL extension to communicate with the DigitalOcean API');
    }

    $this->url = $url;
    $this->token = $token;
  }

  /**
   * Does the communication part.
   *
   * @param string $resource
   * @param array $postdata
   * @return stdClass Response.
   */
  private function request($resource, $postdata = NULL, $customtype = NULL) {
    // Create cURL resource
    $ch = curl_init();

    // Set application options
    curl_setopt($ch, CURLOPT_URL, $this->url . $resource);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->token
    ));
    if ($postdata !== NULL) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
    }
    if ($customtype !== NULL) {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customtype);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);

    // Fire the request and clean up
    $response = curl_exec($ch);
    curl_close($ch);

    // Parse and return result
    return json_decode($response);
  }

  /**
   * Retrieve volume by name.
   *
   * @param string $name
   * @return stdClass Volume data
   */
  public function getVolume($name) {
    $response = $this->request("volumes?name={$name}&per_page=1&page=1");

    return (empty($response) || empty($response->volumes) ? NULL : $response->volumes[0]);
  }

  /**
   * Triggers the creation of a new snapshot
   *
   * @param string $id Volume to create snapshot of.
   */
  public function createVolumeSnapshot($id, $name) {
    $this->request("volumes/{$id}/snapshots", array('name' => $name));
  }

  /**
   * Creates a list of all the avilable snapshots
   *
   * @param string $id Volume to retrieve snapshots for.
   * @return array
   */
  public function getVolumeSnapshots($id) {
    $response = $this->request("volumes/{$id}/snapshots");

    return (empty($response) || empty($response->snapshots) ? array() : $response->snapshots);
  }

  /**
   * Triggers deletion of the specifed snapshot.
   *
   * @param string $id Snapshot to delete.
   */
  public function deleteSnapshot($id) {
    $this->request("snapshots/{$id}", NULL, 'DELETE');
  }
}

/**
 * Class for filtering snapshots list based on specifed slots.
 */
class BackupSlots {

  private $thresholds, $numslots;

  /**
   * Constructor. Set thresholds for backup slots.
   *
   * @param array $thresholds In seconds.
   */
  public function __construct($thresholds) {
    $this->thresholds = $thresholds;
    $this->numslots = count($thresholds);
  }

  /**
   * Sort snapshots into backup slots and then return a list of the excess
   * snapshots that may be deleted.
   *
   * @param $snapshots List of snapshots to process.
   * @param $filterprefix Optional. Only work on snapshots with the given prefix in their name.
   */
  public function getExcess($snapshots, $filterprefix = '') {
    $excess = array();

    if (count($snapshots) < $this->numslots) {
      return $excess;
    }

    $now = time();
    $slots = array();
    foreach ($snapshots as $snapshot) {

      // Only use given volume name
      if (!empty($filterprefix) && substr($snapshot->name, 0, strlen($filterprefix)) !== $filterprefix) {
        continue; // Invalid prefix, skipping…
      }

      // Convert to unixtime
      $created_at = strtotime($snapshot->created_at);

      // Check which slot the snapshot belongs in
      $assigned = FALSE;
      foreach ($this->thresholds as $index => $threshold) {

        // Check if backup fits within threshold
        if ($created_at <= ($now - $threshold)) {
          continue; // Too old
        }

        if (!isset($slots[$index])) {
          // Slot available
          $slots[$index] = $snapshot;
          $assigned = TRUE;
          break;
        }
        else if ($index === 0) {
          // For slot 0 we use the newest backup
          // (it doesn't make sense to delete the backup we just did)
          if ($created_at > strtotime($slots[$index]->created_at)) {

            // Mark as excess
            $excess[] = $slots[$index];

            // Add new snapshot to slot
            $slots[$index] = $snapshot;
            $assigned = TRUE;
            break;
          }
        }
        else if ($created_at < strtotime($slots[$index]->created_at)) {
          // We use the oldes backup

          // Mark as excess
          $excess[] = $slots[$index];

          // Add new snapshot to slot
          $slots[$index] = $snapshot;
          $assigned = TRUE;
          break;
        }
      }

      if (!$assigned) {
        // There is no suitable slot for the backup.

        // Mark as excess
        $excess[] = $snapshot;
      }
    }

    return $excess;
  }
}

// Prepare api
$api = new DigitalOceanAPI('https://api.digitalocean.com/v2/', $token);

// Get details about volume
$volume = $api->getVolume($volumeName);
if ($volume === NULL) {
  throw new Exception('Unable to retrieve data about volume.');
}

// Trigger the creation of a new snapshot
$api->createVolumeSnapshot($volume->id, $backupPrefix . $volumeName . date('-Ymd-His'));
// Alternative DO style for timestamp: round(microtime(TRUE) * 1000);

// Filter backups into 'slots' and clean up the old and excess backups
$backupSlots = new BackupSlots($backupSlots);

$excessSnapshots = $backupSlots->getExcess($api->getVolumeSnapshots($volume->id), $backupPrefix);
foreach ($excessSnapshots as $snapshot) {
  $api->deleteSnapshot($snapshot->id);
}
