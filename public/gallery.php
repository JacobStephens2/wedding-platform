<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
$page_title = "Gallery - Jacob & Melissa";
include __DIR__ . '/includes/header.php';

$photos = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT path, alt, photo_date, position FROM gallery_photos ORDER BY photo_date ASC, id ASC");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Gallery error: " . $e->getMessage());
}
?>

<main class="page-container">
    <h1 class="page-title">Gallery</h1>

    <section class="featured-galleries" aria-label="Featured galleries">
        <a class="featured-card" href="https://baronephoto.pic-time.com/client/jasonmelissa/gallery?inviteToken=AAAAAMwAAAAHmY4IMZZirXjlVnb1WMV4Lw,,&amp;inviteptoken2=AAAAAJcAAAAJmicE83IkZpTwK9b6o7Cspw,,&amp;s=%7B%22blockId%22%3A%22gb_103483103%22%2C%22itemId%22%3A11902344563%2C%22fullScreen%22%3Afalse%7D"
           target="_blank" rel="noopener">
            <div class="featured-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7h4l2-3h6l2 3h4v13H3z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
            </div>
            <h2>Wedding Photo Gallery</h2>
            <p>The full set of photos from our wedding day, hosted by Barone Photo.</p>
            <span class="featured-cta">View the full gallery →</span>
        </a>

        <a class="featured-card" href="https://baronephoto.pic-time.com/client/bwportraitsjacobmelissa/gallery?ptat=AAAAAAIBAABa2GXe-gp4DBjRNYJ3qh8D9Yh0ygjq10fR_tKNeQ,,&amp;inviteptoken2=AAAAABUBAAB6MSBxUrIu5TH4WE_efiRjIA,,"
           target="_blank" rel="noopener">
            <div class="featured-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 3v18"/>
                    <path d="M12 3a9 9 0 010 18z" fill="currentColor" stroke="none"/>
                </svg>
            </div>
            <h2>Black &amp; White Portraits</h2>
            <p>A curated set of black-and-white portraits from our wedding.</p>
            <span class="featured-cta">View the B&amp;W set →</span>
        </a>

        <a class="featured-card" href="https://vimeo.com/1190695875/e325e0040b" target="_blank" rel="noopener">
            <div class="featured-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2.5" y="5" width="19" height="14" rx="2"/>
                    <path d="M10 9l5 3-5 3z" fill="currentColor" stroke="none"/>
                </svg>
            </div>
            <h2>Wedding Video</h2>
            <p>Watch the film of our ceremony and reception.</p>
            <span class="featured-cta">Watch on Vimeo →</span>
        </a>
    </section>

    <h2 class="gallery-section-heading">Highlights</h2>
    <div class="gallery-grid">
        <?php foreach ($photos as $i => $photo): ?>
        <div class="gallery-item">
            <img
                src="/assets.php?type=photo&path=<?php echo urlencode($photo['path']); ?>"
                alt="<?php echo htmlspecialchars($photo['alt']); ?>"
                class="gallery-image"
                loading="lazy"
                data-gallery-index="<?php echo $i; ?>"
                <?php if (!empty($photo['position'])): ?>style="object-position: <?php echo htmlspecialchars($photo['position']); ?>;"<?php endif; ?>
            >
            <div class="gallery-caption">
                <?php if (!empty($photo['alt'])): ?><span class="gallery-caption-desc"><?php echo htmlspecialchars($photo['alt']); ?></span><?php endif; ?>
                <span class="gallery-caption-date"><?php echo date('F j, Y', strtotime($photo['photo_date'])); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-image" src="" alt="">
</div>

<style>
    .featured-galleries {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1.25rem;
        max-width: 1100px;
        margin: 0 auto 3rem;
        padding: 0 1rem;
    }
    .featured-card {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.65rem;
        padding: 1.5rem 1.5rem 1.75rem;
        background: var(--color-surface);
        border: 1px solid var(--color-border);
        border-radius: 10px;
        box-shadow: 0 2px 10px var(--color-shadow);
        text-decoration: none;
        color: var(--color-dark);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
    }
    .featured-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px var(--color-shadow-hover);
        border-color: var(--color-green);
    }
    .featured-icon {
        color: var(--color-green);
        background: rgba(46, 80, 22, 0.08);
        border-radius: 50%;
        width: 56px;
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .featured-card h2 {
        font-family: 'Cinzel', serif;
        font-size: 1.2rem;
        margin: 0.25rem 0 0;
        color: var(--color-green);
        letter-spacing: 0.04em;
    }
    .featured-card p {
        margin: 0;
        font-family: 'Crimson Text', serif;
        font-size: 1rem;
        line-height: 1.45;
        color: var(--color-text-secondary, #555);
    }
    .featured-cta {
        margin-top: auto;
        font-family: 'Cinzel', serif;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
        color: var(--color-green);
    }
    .featured-card:hover .featured-cta { color: var(--color-gold); }

    .gallery-section-heading {
        font-family: 'Cinzel', serif;
        font-size: 1.35rem;
        text-align: center;
        color: var(--color-green);
        letter-spacing: 0.08em;
        margin: 0 0 1.25rem;
    }

    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
        max-width: 1100px;
        margin: 0 auto 3rem;
        padding: 0 1rem;
    }

    .gallery-item {
        border-radius: 8px;
        overflow: hidden;
        background: var(--color-surface);
        box-shadow: 0 2px 10px var(--color-shadow);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .gallery-item:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px var(--color-shadow-hover);
    }

    .gallery-image {
        width: 100%;
        height: 260px;
        object-fit: cover;
        display: block;
        cursor: pointer;
    }

    .gallery-caption {
        padding: 0.65rem 0.85rem;
        font-family: 'Crimson Text', serif;
        text-align: center;
    }
    .gallery-caption-desc {
        display: block;
        font-size: 0.95rem;
        color: #444;
    }
    .gallery-caption-date {
        display: block;
        font-size: 0.82rem;
        color: #999;
    }

    @media (max-width: 600px) {
        .gallery-grid {
            grid-template-columns: 1fr;
        }
        .gallery-image {
            height: 220px;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var galleryImages = document.querySelectorAll('.gallery-image');
    var allImages = Array.from(galleryImages);
    var lightbox = document.getElementById('lightbox');
    var lightboxImg = document.getElementById('lightbox-image');
    var lightboxClose = document.querySelector('.lightbox-close');
    var currentIndex = 0;

    function openLightbox(index) {
        currentIndex = index;
        lightboxImg.src = allImages[currentIndex].src;
        lightboxImg.alt = allImages[currentIndex].alt;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }

    function navigate(direction) {
        currentIndex += direction;
        if (currentIndex < 0) currentIndex = allImages.length - 1;
        if (currentIndex >= allImages.length) currentIndex = 0;
        lightboxImg.src = allImages[currentIndex].src;
        lightboxImg.alt = allImages[currentIndex].alt;
    }

    galleryImages.forEach(function(img, i) {
        img.addEventListener('click', function(e) {
            e.stopPropagation();
            openLightbox(i);
        });
    });

    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) closeLightbox();
    });

    document.addEventListener('keydown', function(e) {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'Escape') closeLightbox();
        else if (e.key === 'ArrowRight') navigate(1);
        else if (e.key === 'ArrowLeft') navigate(-1);
    });

    var touchStartX = 0;
    var swipeThreshold = 50;
    lightbox.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    lightbox.addEventListener('touchend', function(e) {
        var endX = e.changedTouches[0].screenX;
        var diff = endX - touchStartX;
        if (Math.abs(diff) > swipeThreshold) {
            navigate(diff < 0 ? 1 : -1);
        } else if (e.target === lightboxImg) {
            var rect = lightboxImg.getBoundingClientRect();
            var tapX = endX - rect.left;
            navigate(tapX < rect.width / 2 ? -1 : 1);
        }
    }, { passive: true });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
