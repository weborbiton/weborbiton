<?php
/**
 * Main status page for Uptime Monitor
 * 
 * Features:
 * - Loads configuration and monitored sites
 * - Loads site status history from JSON
 * - Calculates per-site metrics: uptime %, downtime, incidents, last status
 * - Determines overall status: Operational, Degraded, or Outage
 * - Generates HTML with live charts (Chart.js)
 * - Adds SEO meta tags dynamically based on current status
 * - Includes structured data (JSON-LD) for search engines
 * - Auto-refreshes every 60 seconds
 */

require_once 'config.php';      // Load site and SEO configuration
$sites_data = include 'sites.php';      // Load monitored sites

// ==============================
// Developer Info Card Loader
// ==============================
// Loads the developer info card from based-info.php only if the URL contains ?using-info=true
// NOTE: This info is optional and can be completely disabled by removing or commenting out this line.
if (isset($_GET['using-info']) && $_GET['using-info'] === 'true') {
    include 'based-info.php';
}

header('Content-Type: text/html; charset=utf-8');

// ==============================
// Canonical URL for SEO
// ==============================
$canonical = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
    . "://" . $_SERVER['HTTP_HOST']
    . strtok($_SERVER['REQUEST_URI'], '?');

// ==============================
// Load status history
// ==============================
$statusData = file_exists('status.json') ? json_decode(file_get_contents('status.json'), true) : [];

// ==============================
// Detect latest status per site (for display or 503 detection)
// ==============================
foreach ($sites_data as $site) {
    $websiteName = $site['name'];
    $websiteUrl = $site['url'];
    
    $entries = isset($statusData[$websiteName]) ? $statusData[$websiteName] : [];
    ksort($entries); // sort by timestamp

    $latest = end($entries) ?: 'unknown'; // last known status
}

// ==============================
// Calculate overall metrics and per-site metrics
// ==============================
$totalServices = count($statusData);
$hasOutage = false;
$hasDegraded = false;
$overallStatus = 'Operational';
$overallStatusColor = '#065f46';
$servicesHtml = '';
$totalUptime = 0;
$uptimeCount = 0;

// Loop through each monitored site
foreach ($statusData as $site => $entries) {
    ksort($entries); // Ensure chronological order
    $last7days = array_slice($entries, -180); // Last 180 entries (6 hours of 2-min checks)
    
    if (empty($last7days)) continue;
    
    // Count statuses
    $upCount = count(array_filter($last7days, fn($v) => $v === 'up' || $v === 'maintenance'));
    $downCount = count(array_filter($last7days, fn($v) => $v === 'down'));
    
    // Check last 3 measurements for recent outage
    $lastThreeMeasurements = array_slice($last7days, -3);
    $hasRecentOutage = count(array_filter($lastThreeMeasurements, fn($v) => $v === 'down')) > 0;
    
    // Calculate uptime %
    $uptime = round(($upCount / (count($last7days) - count(array_filter($last7days, fn($v) => $v === 'maintenance')) + count(array_filter($last7days, fn($v) => $v === 'maintenance')))) * 100, 2);
    $currentStatus = end($last7days);
    
    $totalUptime += $uptime;
    $uptimeCount++;
    
    // Downtime % and maintenance
    $totalEntries = count($last7days);
    $maintenanceCount = count(array_filter($last7days, fn($v) => $v === 'maintenance'));
    $downtimePercentage = $totalEntries > 0 ? round(($downCount / $totalEntries) * 100, 2) : 0;
    
    // Check incidents in last hour (30 entries = ~1 hour)
    $lastHourEntries = array_slice($last7days, -30);
    $hasIncidentLastHour = count(array_filter($lastHourEntries, fn($v) => $v === 'down')) > 0;
    
    // ==============================
    // Determine per-site badge/status
    // ==============================
    if ($hasRecentOutage) {
        // If any down in last 3 measurements = Outage (highest priority)
        $statusBadge = 'Outage';
        $statusClass = 'badge-outage';
        $dotClass = 'dot-down';
        $statusImage = '/images/down.png';
        $hasOutage = true;
    } elseif ($uptime >= 99.5) {
        // Fully operational
        $statusBadge = 'Operational';
        $statusClass = 'badge-operational';
        $dotClass = 'dot-up';
        $statusImage = '/images/up.png';
    } elseif ($uptime >= 95) {
        // 95-99.5% uptime - check for recent or frequent incidents
        if ($downtimePercentage > 2 || $hasIncidentLastHour) {
            // High incident rate or recent incident = Degraded
            $statusBadge = 'Degraded';
            $statusClass = 'badge-degraded';
            $dotClass = 'dot-degraded';
            $statusImage = '/images/degraded.png';
            $hasDegraded = true;
        } else {
            // Low incident rate and no recent incidents = Operational
            $statusBadge = 'Operational';
            $statusClass = 'badge-operational';
            $dotClass = 'dot-up';
            $statusImage = '/images/up.png';
        }
    } else {
        // Below 95% uptime = Outage
        $statusBadge = 'Outage';
        $statusClass = 'badge-outage';
        $dotClass = 'dot-down';
        $statusImage = '/images/down.png';
        $hasOutage = true;
    }
    
    // ==============================
    // Prepare chart data for this site
    // ==============================
    $chartDataPoints = [];
    $chartLabels = [];
    foreach ($last7days as $timestamp => $status) {
        $chartLabels[] = date('H:i', strtotime($timestamp));

        if ($status === 'up') {
            $chartDataPoints[] = 1;
        } elseif ($status === 'maintenance') {
            $chartDataPoints[] = 0.5;
        } else {
            $chartDataPoints[] = 0;
        }
    }
    $chartDataJson = json_encode($chartDataPoints);
    $chartLabelsJson = json_encode($chartLabels);
    
    // Sanitize site name for unique ID
    $safeId = base64_encode($site);
    $safeId = str_replace(['=', '+', '/'], '', $safeId);
    
    // ==============================
    // Generate HTML per site
    // ==============================
    $servicesHtml .= <<<HTML
    <article class="service" aria-label="$site status">
        <div class="service-info">
            <h3>
                <span class="status-dot $dotClass"></span>
                {$site}
            </h3>
            <div class="service-stats">
                <div class="stat-row">
                    <span class="stat-label">Status</span>
                    <span class="status-badge $statusClass">$statusBadge</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Site operational checks (every $checkRate)</span>
                    <span class="stat-value">$upCount</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Incidents</span>
                    <span class="stat-value">$downCount</span>
                </div>
            </div>
        </div>
        <div class="uptime-display">
            <div class="uptime-percentage">$uptime%</div>
            <div class="uptime-label">Uptime</div>
            <div class="chart-container" style="margin-top: 20px;">
                <canvas id="chart-$safeId" data-points='$chartDataJson' data-labels='$chartLabelsJson' data-uptime="$uptime"></canvas>
            </div>
        </div>
    </article>
    HTML;
}

// Set overall status
if ($hasOutage) {
    $overallStatus = 'Outage';
    $overallStatusColor = '#991b1b';
} elseif ($hasDegraded) {
    $overallStatus = 'Degraded';
    $overallStatusColor = '#92400e';
}

// ==============================
// Determine overall status and SEO info
// ==============================
$currentDateTime = date('d M Y, H:i:s', time()) . ' UTC';
$averageUptime = $uptimeCount > 0 ? round($totalUptime / $uptimeCount, 1) : 0;
$seoDescription = sprintf($seoDescriptionTemplate, $overallStatus, $totalServices, $averageUptime);
$seoTitle = sprintf($seoTitleTemplate, $overallStatus);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <!-- ==============================
         Page Title for SEO & Browser Tab
         Dynamic: reflects current overall site status
    ============================== -->
    <title>
        <?php echo htmlspecialchars($seoTitle); ?>
    </title>

    <!-- ==============================
         Meta Description for SEO
         Dynamic: includes uptime %, monitored services, and current status
    ============================== -->
    <meta name="description" content="<?php echo htmlspecialchars($seoDescription); ?>">

    <!-- Canonical URL to avoid duplicate content penalties -->
    <meta name="robots" content="index, follow">

    <!-- ==============================
         Open Graph (OG) tags for social sharing
         Dynamic: title, description, URL, and image reflect real-time status
    ============================== -->
    <link rel="canonical" href="<?php echo $canonical; ?>">

    <!-- Open Graph tags for social sharing with real status -->
    <meta property="og:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url"
        content="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http"
        ) . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>">
    <meta property="og:image"
        content="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http"
        ) . "://" . $_SERVER['HTTP_HOST'] . $statusImage); ?>">
    <meta property="og:image:alt" content="<?= $siteName . ' is ' . strtolower($overallStatus) ?>">

    <!-- ==============================
         Twitter Card for improved social sharing
         Dynamic: title, description, and image
    ============================== -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($seoTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta name="twitter:image"
        content="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http"
        ) . "://" . $_SERVER['HTTP_HOST'] . $statusImage); ?>">

    <!-- ==============================
         Structured Data (JSON-LD) for SEO
         Helps search engines understand page content
         Dynamic: uses current status, publisher, last modified date
    ============================== -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "<?php echo htmlspecialchars($seoTitle); ?>",
        "description": "<?php echo htmlspecialchars($seoDescription); ?>",
        "url": "<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>",
        "publisher": {
        "@type": "Organization",
        "name": "<?php echo htmlspecialchars($siteName); ?>"
        },
        "dateModified": "<?php echo date('c'); ?>"
    }
    </script>

    <!-- ==============================
         Chart.js library for uptime graphs
         Will be used to render dynamic line charts for each monitored site
    ============================== -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js?v=<?php echo date('YW'); ?>"></script>

    <!-- ==============================
         Stylesheet for page styling
         Basic CSS for layout, colors, fonts, and responsiveness
    ============================== -->
    <link rel="stylesheet" href="styles.css?v=<?php echo date('Ymd'); ?>"/>
</head>


<!-- ==============================
     BODY CONTENT
     Main structure of the status page:
     Header, Main content (hero + services), Footer
============================== -->
<body>
    <!-- ==============================
         HEADER
         Displays logo, site name, current status, and last update time
    ============================== -->
    <header>
        <div class="header-content">
            <div class="header-left">

                <!-- Logo block -->
                <div class="logo">
                    <?= htmlspecialchars($siteLogoText) ?>
                </div>

                <!-- Site name -->
                <div>
                    <div class="company-name">
                        <?= htmlspecialchars($siteName) ?>
                    </div>
                    
                    <!-- Status type label -->
                    <div class="status-type">Status</div>
                </div>
            </div>

            <!-- Last updated timestamp and page refresh info -->
            <div class="header-right">
                <span>Last updated: <span>
                        <?php echo $currentDateTime; ?>
                    </span></span>
                <span>•</span>
                <span>Page refreshes every 60 seconds</span>
            </div>
        </div>
    </header>

    <!-- ==============================
         MAIN CONTENT
         Hero section and services grid
    ============================== -->
    <main>

        <!-- ==============================
             HERO SECTION
             Summary of uptime stats and monitoring overview
        ============================== -->
        <section class="hero">
            <h1>
                <?= htmlspecialchars($siteName) ?> Uptime
            </h1>
            <p>Real-time monitoring of all
                <?= htmlspecialchars($siteName) ?> services and their current performance metrics.
            </p>

            <!-- Hero meta info: total services, overall status, monitoring period -->
            <div class="hero-meta">
                <div class="meta-item">
                    <span class="meta-label">Services</span>
                    <span class="meta-value">
                        <?php echo $totalServices; ?>
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Overall Status</span>
                    <span class="meta-value" style="color: <?php echo $overallStatusColor; ?>">
                        <?php echo $overallStatus; ?>
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Monitoring Period</span>
                    <span class="meta-value">
                        <?= htmlspecialchars($monitoringPeriod) ?>
                    </span>
                </div>
            </div>
        </section>

        <!-- ==============================
             SERVICES GRID
             List of monitored services with individual stats, uptime %, and charts
        ============================== -->
        <section class="status-section">
            <h2 class="section-title">Services</h2>
            <div class="services-grid">
                <?php echo $servicesHtml; ?>
            </div>
        </section>
    </main>

    <!-- ==============================
         FOOTER
         Last update timestamp and credit
    ============================== -->
    <footer>
        <div class="footer-left">
            <span>Last updated:
                <?php echo $currentDateTime; ?>.
                <!-- Info below can be removed by developers if they don't want to show it -->
                Powered by <a href="https://weborbiton.click">WebOrbiton</a>.
            </span>
        </div>
    </footer>

    <!-- ==============================
         JAVASCRIPT
         Chart.js initialization and auto-refresh
    ============================== -->
    <script>
        document.querySelectorAll('canvas[data-points]').forEach(canvas => {
            const points = JSON.parse(canvas.dataset.points);
            const labels = JSON.parse(canvas.dataset.labels);

            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Status',
                        data: points,
                        borderWidth: 1.5,
                        pointRadius: 0, // Hide data points
                        fill: true,
                        backgroundColor: ctx => {
                            const y = ctx.dataset.data[ctx.dataIndex];
                            return y === 1 ? '#10b98130' : y === 0.5 ? '#3b82f630' : '#ef444430';
                        },
                        segment: {
                            borderColor: ctx => {
                                const prev = ctx.p0.parsed.y;
                                const next = ctx.p1.parsed.y;

                                if (prev === next) {
                                    return prev === 1 ? '#10b981' : prev === 0.5 ? '#3b82f6' : '#ef4444';
                                } else {
                                    if (next === 1) return '#10b981';       // Up
                                    if (next === 0.5) return '#3b82f6';     // Maintenance
                                    return '#ef4444';                       // Down
                                }
                            },
                            backgroundColor: ctx => {
                                const prev = ctx.p0.parsed.y;
                                const next = ctx.p1.parsed.y;

                                if (prev === next) {
                                    return prev === 1 ? '#10b98130' : prev === 0.5 ? '#3b82f630' : '#ef444430';
                                } else {
                                    if (next === 1) return '#10b98130';
                                    if (next === 0.5) return '#3b82f630';
                                    return '#ef444430';
                                }
                            }
                        },
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        y: {
                            min: 0,
                            max: 1,
                            ticks: {
                                stepSize: 0.5,
                                callback: v => v === 1 ? 'Up' : v === 0.5 ? 'Maintenance' : 'Down',
                                color: '#999',
                                font: { size: 11, weight: '400' }
                            },
                            grid: { color: '#f0f0f0', drawBorder: false }
                        },
                        x: {
                            ticks: {
                                color: '#999',
                                font: { size: 10 },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 6
                            },
                            grid: { display: false, drawBorder: false }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1a1a1a',
                            titleColor: '#fff',
                            bodyColor: '#ddd',
                            borderColor: '#444',
                            borderWidth: 1,
                            padding: 10,
                            displayColors: false,
                            callbacks: {
                                label: ctx => {
                                    const v = ctx.parsed.y;
                                    return v === 1 ? 'Status: Up' : v === 0.5 ? 'Status: Maintenance' : 'Status: Down';
                                }
                            }
                        }
                    }
                }
            });
        });

        // Auto-refresh page every 60 seconds
        setTimeout(() => location.reload(), 60000);
    </script>

</body>
</html>

<!-- ==============================
     FOOTER
     Last update: 2025-12-02
     Credits: Powered by WebOrbiton.click
     Description: This status page provides real-time uptime and performance monitoring
                  of services with historical insights.
     Note: Code written in Poland
============================== -->

<!--
 __      __      ___.    ________       ___.   .__  __                           .__  .__        __    
/  \    /  \ ____\_ |__  \_____  \______\_ |__ |__|/  |_  ____   ____       ____ |  | |__| ____ |  | __
\   \/\/   // __ \| __ \  /   |   \_  __ \ __ \|  \   __\/  _ \ /    \    _/ ___\|  | |  |/ ___\|  |/ /
 \        /\  ___/| \_\ \/    |    \  | \/ \_\ \  ||  | (  <_> )   |  \   \  \___|  |_|  \  \___|    < 
  \__/\  /  \___  >___  /\_______  /__|  |___  /__||__|  \____/|___|  / /\ \___  >____/__|\___  >__|_ \
       \/       \/    \/         \/          \/                     \/  \/     \/             \/     \/

                    .___       .__         __________      .__                     .___
  _____ _____     __| _/____   |__| ____   \______   \____ |  | _____    ____    __| _/
 /     \\__  \   / __ |/ __ \  |  |/    \   |     ___/  _ \|  | \__  \  /    \  / __ | 
|  Y Y  \/ __ \_/ /_/ \  ___/  |  |   |  \  |    |  (  <_> )  |__/ __ \|   |  \/ /_/ | 
|__|_|  (____  /\____ |\___  > |__|___|  /  |____|   \____/|____(____  /___|  /\____ | 
      \/     \/      \/    \/          \/                            \/     \/      \/                                                      
                                                             
      

                                          ................                                          
                                      ........................                                                                                              
                               ......................................                                                                    
  .........                  ..........................................                    
  :.......                ...............................................:                          
    ........:             ................................................                          
       ::........        ..................................................                         
           :.........    ..................................................                         
                 ..........................................................                         
                      ......................................................                        
                           :.:.............................................                         
                        ......   ..........................................                         
                         ..........     :.::...............................           .:.           
                         ................      :...........................               ..:       
                          .......................        ::...............:               :....:    
                          .................................       :.:.............................  
                           .............................................    ......................  
                            ............................................                            
                             .........................................                                                         
                                ...................................                                 
                                   ..............................                                   
                                     :........................                                      
                                         :................                                          
                                            
                                         
                                         
-->