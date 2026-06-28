<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/content.php';

$coupleNames = content('couple_names', 'Our Wedding');
$weddingDateIso = content('wedding_date', '2026-04-11');
$weddingCity = content('wedding_city', '');
$weddingDateLong = (date_create($weddingDateIso) ?: date_create('2026-04-11'))->format('F j, Y');
$homeVideo = content('home_video', '');
$homePoster = content('home_poster', '');

$page_title = $coupleNames . ' - ' . $weddingDateLong;
include __DIR__ . '/includes/header.php';

// Days-since / days-to-go relative to the wedding date.
$weddingDay = new DateTimeImmutable($weddingDateIso, new DateTimeZone('America/New_York'));
$today      = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
$dayDelta   = (int)$today->diff($weddingDay)->format('%r%a'); // positive = future, negative = past

if ($dayDelta > 0) {
    $countdownText = $dayDelta . ' day' . ($dayDelta === 1 ? '' : 's') . ' to go!';
} elseif ($dayDelta === 0) {
    $countdownText = 'Just married today!';
} else {
    $daysMarried = -$dayDelta;
    $countdownText = $daysMarried . ' day' . ($daysMarried === 1 ? '' : 's') . ' married';
}
?>

<main class="home-page">
    <div class="background-overlay"></div>
    <div class="background-media">
        <video autoplay muted loop playsinline>
            <?php if ($homeVideo !== ''): ?>
            <source src="/assets.php?type=video&path=<?php echo urlencode($homeVideo); ?>" type="video/mp4">
            <?php endif; ?>
            <?php if ($homePoster !== ''): ?>
            <img src="/assets.php?type=photo&path=<?php echo urlencode($homePoster); ?>" alt="<?php echo htmlspecialchars($coupleNames); ?>">
            <?php endif; ?>
        </video>
    </div>

    <div class="home-content">
        <h1 class="couple-names"><?php echo htmlspecialchars($coupleNames); ?></h1>
        <p class="wedding-date"><?php echo htmlspecialchars($weddingDateLong . ($weddingCity !== '' ? ' | ' . $weddingCity : '')); ?></p>
        <p class="countdown" id="countdown-text"><?php echo htmlspecialchars($countdownText); ?></p>
    </div>
</main>

<script>
// Keep the countdown copy fresh as the day rolls over.
function updateCountdown() {
    const wedding = new Date('<?php echo htmlspecialchars($weddingDateIso); ?>T00:00:00');
    const startOfToday = new Date();
    startOfToday.setHours(0, 0, 0, 0);
    const msPerDay = 1000 * 60 * 60 * 24;
    const delta = Math.round((wedding - startOfToday) / msPerDay);
    const el = document.getElementById('countdown-text');
    if (!el) return;

    if (delta > 0) {
        el.textContent = delta + (delta === 1 ? ' day' : ' days') + ' to go!';
    } else if (delta === 0) {
        el.textContent = 'Just married today!';
    } else {
        const married = -delta;
        el.textContent = married + (married === 1 ? ' day' : ' days') + ' married';
    }
}

updateCountdown();
setInterval(updateCountdown, 1000 * 60 * 60); // Refresh hourly so the day change rolls over.
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

