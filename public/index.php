<?php
require_once __DIR__ . '/../private/config.php';
$page_title = "Jacob & Melissa - April 11, 2026";
include __DIR__ . '/includes/header.php';

// Days-since / days-to-go relative to the wedding date.
$weddingDay = new DateTimeImmutable('2026-04-11', new DateTimeZone('America/New_York'));
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
            <source src="/assets.php?type=video&path=Jacob_and_Melissa_proposal_mobile.mp4" type="video/mp4">
            <img src="/assets.php?type=photo&path=proposal/PeytoLakeBanff_Proposal_One_Knee_wide.jpg" alt="Jacob and Melissa">
        </video>
    </div>

    <div class="home-content">
        <h1 class="couple-names">Jacob & Melissa</h1>
        <p class="wedding-date">April 11, 2026 | Philadelphia</p>
        <p class="countdown" id="countdown-text"><?php echo htmlspecialchars($countdownText); ?></p>
    </div>
</main>

<script>
// Keep the countdown copy fresh as the day rolls over.
function updateCountdown() {
    const wedding = new Date('2026-04-11T00:00:00');
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

