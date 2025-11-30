<?php
/**
 * Website Status Monitoring Script
 * 
 * Features:
 * 1. Checks the HTTP status of configured websites (returns "up", "down", or "maintenance").
 * 2. Fills in missing measurements to handle gaps in monitoring.
 * 3. Keeps only the last X hours of data to avoid storing excessive history.
 * 4. Saves the monitoring history in a JSON file.
 * 
 * Requirements:
 * - PHP with cURL enabled
 * - 'sites.php' returning an array of websites to monitor:
 *     [
 *         ['name' => 'Example', 'url' => 'https://example.com'],
 *         ...
 *     ]
 * 
 * Configuration:
 * - $maxHours: how many hours of history to keep
 * - $minutes: interval between measurements in minutes
 */

// === CONFIG ===
$sites_data = include 'sites.php'; // array of sites with 'name' and 'url'

$file = __DIR__ . "/status.json";  // file to store monitoring history
$maxHours = 3;       // keep only last X hours of minute data
$minutes = 2;        // measurement interval in minutes

// ==============================
// FUNCTION: CHECK SITE STATUS
// ==============================
function check_site($url){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);       // only fetch headers
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);        // timeout in seconds
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 200–399 → Up
    if ($httpCode >= 200 && $httpCode < 400) {
        return "up";
    }

    // 503 → Maintenance mode detected
    if ($httpCode == 503) {
        return "maintenance";
    }

    // Anything else → Down
    return "down";
}

// ==============================
// FUNCTION: FILL MISSING MEASUREMENTS
// ==============================
function fill_missing_measurements($measurements, $interval = 2) {
    if (empty($measurements)) return $measurements;
    
    $timestamps = array_keys($measurements);
    sort($timestamps);
    
    $filled = [];
    for ($i = 0; $i < count($timestamps); $i++) {
        $current_time = strtotime($timestamps[$i]);
        $filled[$timestamps[$i]] = $measurements[$timestamps[$i]];
        
        if ($i < count($timestamps) - 1) {
            $next_time = strtotime($timestamps[$i + 1]);
            $gap = ($next_time - $current_time) / 60; // gap in minutes
            
            // Fill gaps with "down" status
            if ($gap > $interval) {
                $missing_count = floor($gap / $interval) - 1;
                for ($j = 1; $j <= $missing_count; $j++) {
                    $missing_time = $current_time + ($j * $interval * 60);
                    $missing_timestamp = gmdate("Y-m-d\TH:i:s\Z", $missing_time);
                    $filled[$missing_timestamp] = "down";
                }
            }
        }
    }
    
    ksort($filled);
    return $filled;
}

// ==============================
// MAIN LOOP: CHECK ALL SITES
// ==============================
$history = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$now = gmdate("Y-m-d\TH:i:s\Z"); // current UTC time

foreach ($sites_data as $site) {
    $name = $site['name'];
    $url = $site['url'];

    $status = check_site($url);

    if (!isset($history[$name])) {
        $history[$name] = [];
    }

    // Fill missing gaps before adding new entry
    $history[$name] = fill_missing_measurements($history[$name], $minutes);
    
    // Add new measurement
    $history[$name][$now] = $status;

    // Remove old data beyond $maxHours
    $cutoff = time() - ($maxHours * 60 * 60);
    foreach ($history[$name] as $timestamp => $val) {
        if (strtotime($timestamp) < $cutoff) {
            unset($history[$name][$timestamp]);
        }
    }

    ksort($history[$name]);
}

// Save updated history to JSON file
file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
?>
