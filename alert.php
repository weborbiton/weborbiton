<!-- ==============================
    NOTE: Change the filename to a harder-to-guess one using (a-z, A-Z, 1-9). Not required, but recommended by WebOrbiton.
============================== -->

<?php
// ==============================
// ALERT CONFIGURATION FOR STATUS MONITOR
// ==============================

// General settings
$alertEnabled = false; // Set to true to enable email alerts
$alertEmailTo = 'info@example.com';
$alertEmailFrom = 'noreply@example.com';

// Alert triggers
$alertOnUp = true;
$alertOnMaintenance = true;
$alertOnDown = true;

// Maximum number of recent states to keep in alert.json
$maxAlertHistory = 5;

// Rate limiting
$maxAlertsPerHour = 3; // Maximum number of alerts per site per 60 minutes

// Files
$statusFile = __DIR__ . '/status.json';
$alertFile = __DIR__ . '/alert.json';
$alertLogFile = __DIR__ . '/alert.log'; // log of alert sending

// ==============================
// FUNCTION: SEND ALERT
// ==============================
function sendAlert($siteName, $status, $to, $from, $logFile) {
    $dateTime = gmdate('Y-m-d H:i:s') . ' UTC';
    $subject = "[$siteName] Status Alert: $status";

    // Extended message
    $message = "Hello,\n\n"
             . "Your website \"$siteName\" is currently \"$status\" as of $dateTime.\n\n"
             . "This alert was sent automatically by WebOrbiton. Please do not reply to this message.\n\n"
             . "Thank you for using WebOrbiton monitoring services.";

    $headers = "From: $from";

    $success = mail($to, $subject, $message, $headers);

    // Log alert sending
    $logMessage = gmdate('Y-m-d H:i:s') . " UTC | $siteName | $status | " . ($success ? "Sent" : "Failed") . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);

    return $success;
}

// ==============================
// FUNCTION: UPDATE ALERT HISTORY
// ==============================
function updateAlertHistory($siteName, $status, $alertFile, $maxAlertHistory) {
    $alerts = file_exists($alertFile) ? json_decode(file_get_contents($alertFile), true) : [];

    if (!isset($alerts[$siteName])) $alerts[$siteName] = [];

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $alerts[$siteName][] = ['time' => $now, 'status' => $status];

    if (count($alerts[$siteName]) > $maxAlertHistory) {
        $alerts[$siteName] = array_slice($alerts[$siteName], -$maxAlertHistory);
    }

    file_put_contents($alertFile, json_encode($alerts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ==============================
// FUNCTION: COUNT ALERTS IN LAST 60 MINUTES
// ==============================
function countRecentAlerts($siteName, $alertFile, $minutes = 60) {
    if (!file_exists($alertFile)) return 0;
    $alerts = json_decode(file_get_contents($alertFile), true);
    if (!isset($alerts[$siteName])) return 0;

    $count = 0;
    $now = time();
    foreach ($alerts[$siteName] as $entry) {
        if (($now - strtotime($entry['time'])) <= $minutes * 60) {
            $count++;
        }
    }
    return $count;
}

// ==============================
// PROCESS STATUS AND TRIGGER ALERTS ONLY ON STATUS CHANGE WITH RATE LIMIT
// ==============================
if ($alertEnabled && file_exists($statusFile)) {
    $statusData = json_decode(file_get_contents($statusFile), true);
    $alertHistory = file_exists($alertFile) ? json_decode(file_get_contents($alertFile), true) : [];

    foreach ($statusData as $siteName => $history) {
        $lastStatus = end($history);
        $previousStatus = isset($alertHistory[$siteName]) ? end($alertHistory[$siteName])['status'] : null;

        $shouldAlert = false;
        if ($lastStatus !== $previousStatus) {
            if ($lastStatus === 'up' && $alertOnUp) $shouldAlert = true;
            if ($lastStatus === 'maintenance' && $alertOnMaintenance) $shouldAlert = true;
            if ($lastStatus === 'down' && $alertOnDown) $shouldAlert = true;
        }

        // Check rate limit
        $recentAlerts = countRecentAlerts($siteName, $alertFile);
        if ($shouldAlert && $recentAlerts < $maxAlertsPerHour) {
            sendAlert($siteName, $lastStatus, $alertEmailTo, $alertEmailFrom, $alertLogFile);
        }

        updateAlertHistory($siteName, $lastStatus, $alertFile, $maxAlertHistory);
    }
}