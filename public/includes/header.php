<?php
require_once __DIR__ . '/../../private/content.php';

$coupleNames = content('couple_names', 'Our Wedding');
$weddingDateIso = content('wedding_date');
$weddingDateLong = '';
if ($weddingDateIso) {
    $d = date_create($weddingDateIso);
    if ($d) {
        $weddingDateLong = $d->format('F j, Y');
    }
}
$defaultTitle = $coupleNames . ($weddingDateLong ? ' - ' . $weddingDateLong : '');
$analyticsId = content('analytics_id', '');
$themeColor = content('theme_color', '#7f8f65');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($analyticsId !== ''): ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($analyticsId); ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', '<?php echo htmlspecialchars($analyticsId); ?>');
    </script>
    <?php endif; ?>
    <title><?php echo htmlspecialchars($page_title ?? $defaultTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    <?php include __DIR__ . '/theme_init.php'; ?>
    <link rel="stylesheet" href="/css/style.css?v=<?php 
        $cssPath = __DIR__ . '/../css/style.css';
        echo file_exists($cssPath) ? filemtime($cssPath) : time(); 
    ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400&family=Beloved+Script&family=Crimson+Text:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
</head>
<body>
    <header>
        <div class="header-content">
            <h1 class="site-title"><a href="/"><?php echo htmlspecialchars($coupleNames); ?></a></h1>
            
            <!-- Desktop navigation -->
            <nav class="desktop-nav">
                <a href="/">Home</a>
                <a href="/story">Story</a>
                <a href="/rsvp">RSVP</a>
                <a href="/registry">Registry</a>
                <a href="/gallery">Gallery</a>
                <a href="/about">About</a>
                <a href="/travel">Travel</a>
                <a href="/contact">Contact</a>
                <button class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <span class="icon-moon">&#9789;</span>
                    <span class="icon-sun">&#9788;</span>
                </button>
            </nav>

            <!-- Mobile controls -->
            <div class="mobile-controls">
                <button class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <span class="icon-moon">&#9789;</span>
                    <span class="icon-sun">&#9788;</span>
                </button>
                <button class="mobile-menu-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
        
        <!-- Mobile navigation -->
        <nav class="mobile-nav">
            <a href="/">Home</a>
            <a href="/story">Story</a>
            <a href="/rsvp">RSVP</a>
            <a href="/registry">Registry</a>
            <a href="/gallery">Gallery</a>
            <a href="/about">About</a>
            <a href="/travel">Travel</a>
            <a href="/contact">Contact</a>
        </nav>
    </header>

