<?php
// ==============================
// CONFIGURATION FILE FOR STATUS MONITOR
// ==============================

// General site information
$siteName = "MevaSearch";       // Name of the website/project
$siteStatus = "Status Monitor"; // Title used in status monitoring pages
$siteLogoText = "M";            // Short text/logo to display in header or favicon

// ==============================
// SEO TEMPLATES
// ==============================

// Title template for search engines or page title
// %s = current overall status (Operational, Degraded, Outage)
$seoTitleTemplate = "{$siteName} Status Monitor - %s";

// Description template for SEO and social sharing
// Placeholders:
// %s  → current overall status
// %d  → total number of monitored services
// %.1f → average uptime percentage
$seoDescriptionTemplate = "{$siteName} real-time status monitoring: current overall status is %s. 
We monitor %d services continuously, tracking uptime, downtime, and maintenance incidents. 
Average uptime over the monitored period is %.1f%%. Stay updated with live performance metrics, 
incident alerts, and historical trends to ensure full operational awareness.";

// ==============================
// MONITORING SETTINGS
// ==============================

// Period used to calculate uptime, downtime, and incidents.
// Can be used to determine which entries to consider for historical metrics.
$monitoringPeriod = "3 hours"; 
?>