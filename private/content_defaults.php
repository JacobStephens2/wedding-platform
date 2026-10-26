<?php
/**
 * Sample couple content for a fresh install of this wedding-site template.
 *
 * Every name, place, and link below is fictional. The PHP pages under public/
 * are couple-agnostic templates that read everything through the content() /
 * contentBlocks() helpers in private/content.php.
 *
 * How it is used:
 *   - On a seeded install, the editable copy lives in the `site_content` and
 *     `content_blocks` MySQL tables and is managed from the admin Content editor.
 *   - A deployment's real content goes in private/content_defaults.local.php
 *     (git-ignored, same shape as this file). When that file exists it replaces
 *     this one entirely, so a real couple's details never need to be committed.
 *   - Whichever file is active is the runtime fallback (used verbatim when a key
 *     has no DB row, or when the database is unavailable) AND the seed source
 *     for `private/seed_content.php`.
 *
 * Setting up a new couple's site: copy this file to content_defaults.local.php
 * and replace the values (or seed this sample and edit it in the admin Content
 * page). No couple's name, date, venue, or story prose should live anywhere
 * else in the codebase.
 *
 * `scalars` are short single values reused across pages (names, date, venues,
 * emails). `blocks` are the long-form prose sections of each page, keyed by
 * page then section. Story bodies may embed photo placeholders:
 *   {{carousel:KEY}}     -> swipeable carousel of gallery_photos WHERE story_section = KEY
 *   {{blockimages:KEY}}  -> static image grid for that section
 * KEY matches the `story_section` value set on photos in the gallery admin;
 * the gallery admin offers exactly the KEYs placed in the story blocks.
 */

return [

    // ---------------------------------------------------------------------
    // Scalars: short, reused identity / event values.
    // ---------------------------------------------------------------------
    'scalars' => [
        // Identity
        'couple_names'        => 'Alex & Jordan',
        'partner1_name'       => 'Alex',
        'partner2_name'       => 'Jordan',
        'partner1_full_name'  => 'Alex Rivera',
        'partner2_full_name'  => 'Jordan Park',

        // Credit line in the footer
        'site_author_name'    => 'Alex Rivera',
        'site_author_url'     => 'https://example.com/',

        // Event
        'wedding_date'        => '2027-06-12', // ISO 8601; drives the countdown
        'wedding_city'        => 'Springfield',

        'ceremony_label'      => 'Ceremony',
        'ceremony_venue'      => 'Maple Grove Chapel',
        'ceremony_address'    => '100 Chapel Lane, Springfield, ST 00000',
        'ceremony_time'       => '2:00 p.m.',

        'reception_venue'     => 'Lakeside Pavilion',
        'reception_address'   => '200 Shore Drive, Springfield, ST 00000',
        'reception_time'      => '5:00 p.m.',

        'rsvp_deadline'       => 'May 1, 2027',

        // Contact (display only; see notification recipients below)
        'contact_email'       => 'hello@example.com',

        // Notification recipients, editable from the admin Site Content page so
        // no .env edit is needed to change who is alerted. Comma-separated for
        // multiple. When blank, the code falls back to the RSVP_EMAIL /
        // CONTACT_EMAIL env vars.
        'rsvp_notify_emails'  => '', // who is emailed on a new RSVP
        'contact_notify_email'=> '', // who is emailed on a contact-form message

        // Branding
        'theme_color'         => '#7f8f65',
        'analytics_id'        => '', // GA4 measurement ID; '' to disable

        // Home page hero media (paths under the private photo/video stores; '' hides)
        'home_video'          => '',
        'home_poster'         => '',

        // Gallery page external links, card images, and video embeds
        // ('' hides the related element)
        'gallery_full_url'        => '',
        'gallery_bw_url'          => '',
        'gallery_full_image'      => '',
        'gallery_bw_image'        => '',
        'wedding_video_url'       => '',
        'wedding_video_url_2'     => '',

        // Contact page card ('' hides a line)
        'contact_phone_label'     => "Alex's Cell",
        'contact_phone'           => '',

        // Payment / mailing details, shared by the contact card and registry funds
        'payment_venmo'           => '',
        'check_payee_name'        => 'Alex Rivera',
        'mailing_address_line1'   => '123 Example Street',
        'mailing_address_line2'   => 'Springfield, ST 00000',

        // Registry funds ('' image hides it)
        'honeymoon_destination'   => 'the coast',
        'house_fund_image'        => '',
        'honeymoon_fund_image'    => '',
    ],

    // ---------------------------------------------------------------------
    // Blocks: long-form page prose. Ordered by `sort`. `heading` may be ''.
    // ---------------------------------------------------------------------
    'blocks' => [

        'story' => [
            [
                'section_key' => 'how_we_met',
                'heading'     => 'How We Met',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p>Alex and Jordan met at a friend&rsquo;s birthday dinner, seated next to each other by chance. A conversation about favorite books ran long after dessert, and neither of them wanted the evening to&nbsp;end.</p>
<p class="story-accent">Replace this section with your own story.</p>
{{carousel:how_we_met}}
HTML,
            ],
            [
                'section_key' => 'first_date',
                'heading'     => 'The First Date',
                'sort'        => 20,
                'body'        => <<<'HTML'
<p>A week later they met for coffee, which turned into a walk through the park, which turned into dinner. Each section of the story can hold its own photos: assign gallery photos to this section in Manage Gallery and they appear in the carousel&nbsp;below.</p>
{{carousel:first_date}}
HTML,
            ],
            [
                'section_key' => 'proposal',
                'heading'     => 'The Proposal',
                'sort'        => 30,
                'body'        => <<<'HTML'
<p>On a quiet morning by the lake where they had their first picnic, Alex asked Jordan to marry them.</p>
<p class="story-accent">Jordan said yes.</p>
{{carousel:proposal}}
HTML,
            ],
            [
                'section_key' => 'the_wedding',
                'heading'     => 'The Wedding',
                'sort'        => 40,
                'body'        => <<<'HTML'
<p>The ceremony will be on June 12, 2027 at 2:00&nbsp;p.m. at Maple Grove Chapel, with the reception following at 5:00&nbsp;p.m. at Lakeside Pavilion. We can&rsquo;t wait to celebrate with&nbsp;you.</p>
{{blockimages:the_wedding}}
HTML,
            ],
        ],

        'about' => [
            [
                'section_key' => 'schedule',
                'heading'     => 'Schedule',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p><strong>Ceremony</strong> &mdash; 2:00&nbsp;p.m. at Maple Grove Chapel, 100 Chapel Lane, Springfield, ST&nbsp;00000</p>
<p><strong>Reception</strong> &mdash; 5:00&nbsp;p.m. at Lakeside Pavilion, 200 Shore Drive, Springfield, ST&nbsp;00000</p>
HTML,
            ],
            [
                'section_key' => 'children_welcome',
                'heading'     => 'Children Welcome',
                'sort'        => 20,
                'body'        => <<<'HTML'
<p>We are delighted to have children join us in celebrating our wedding! Little ones are welcome at both the ceremony and reception.</p>
HTML,
            ],
            [
                'section_key' => 'attire',
                'heading'     => 'Attire',
                'sort'        => 30,
                'body'        => <<<'HTML'
<p>Semi-formal. The reception is partly outdoors, so bring a layer for the evening breeze off the lake.</p>
HTML,
            ],
        ],

        'travel' => [
            [
                'section_key' => 'parking',
                'heading'     => 'Parking',
                'sort'        => 10,
                'body'        => <<<'HTML'
<div class="location-parking">
    <h3>Maple Grove Chapel</h3>
    <p class="address">100 Chapel Lane, Springfield, ST 00000</p>
    <p>Free parking is available in the chapel lot.</p>
</div>

<div class="location-parking">
    <h3>Lakeside Pavilion</h3>
    <p class="address">200 Shore Drive, Springfield, ST 00000</p>
    <p>The venue offers complimentary valet parking.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'transportation',
                'heading'     => 'Transportation',
                'sort'        => 20,
                'body'        => <<<'HTML'
<div class="transportation-option">
    <h3>Rideshare</h3>
    <p>Rideshare services are available throughout Springfield and are a convenient way to travel between the ceremony and reception.</p>
</div>

<div class="transportation-option">
    <h3>Driving</h3>
    <p>If you're driving, please see the parking information above for each location.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'accommodations',
                'heading'     => 'Accommodations',
                'sort'        => 30,
                'body'        => <<<'HTML'
<div class="accommodation-option">
    <h3>Springfield Inn — Room Block</h3>
    <p class="address">300 Main Street, Springfield, ST 00000</p>
    <p>We've reserved a block of rooms for our guests at a group rate. Mention our names when booking. The rooms are reserved through May&nbsp;1.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'out_of_town',
                'heading'     => 'Coordinating Out-of-Town Guests',
                'sort'        => 40,
                'body'        => <<<'HTML'
<p>If you're traveling from out of town and would like help with travel planning, please reach out through the <a href="/contact">contact form</a>.</p>
HTML,
            ],
        ],

        'blessing' => [
            [
                'section_key' => 'blessing',
                'heading'     => '',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p>A few weeks after the proposal, Alex and Jordan celebrated their engagement with family and close friends. This page is unlinked from the main navigation; use it for any extra occasion you want to share.</p>
HTML,
            ],
        ],
    ],
];
