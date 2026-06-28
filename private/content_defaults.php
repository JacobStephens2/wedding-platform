<?php
/**
 * Couple-specific content for this wedding-site instance.
 *
 * This is the ONE place that knows which couple the site serves. The PHP pages
 * under public/ are couple-agnostic templates that read everything through the
 * content() / content_blocks() helpers in private/content.php.
 *
 * How it is used:
 *   - On a seeded install, the editable copy lives in the `site_content` and
 *     `content_blocks` MySQL tables and is managed from the admin Content editor.
 *   - The values here are the runtime fallback (used verbatim when a key has no
 *     DB row, or when the database is unavailable) AND the seed source for
 *     `private/seed_content.php`.
 *
 * Setting up a new couple's site: replace the values below (or run the seed and
 * edit them in the admin Content page). No couple's name, date, venue, or story
 * prose should live anywhere else in the codebase.
 *
 * `scalars` are short single values reused across pages (names, date, venues,
 * emails). `blocks` are the long-form prose sections of each page, keyed by
 * page then section. Story bodies may embed photo placeholders:
 *   {{carousel:KEY}}     -> swipeable carousel of gallery_photos WHERE story_section = KEY
 *   {{blockimages:KEY}}  -> static image grid for that section
 * KEY matches the `story_section` value set on photos in the gallery admin.
 */

return [

    // ---------------------------------------------------------------------
    // Scalars: short, reused identity / event values.
    // ---------------------------------------------------------------------
    'scalars' => [
        // Identity
        'couple_names'        => 'Jacob & Melissa',
        'partner1_name'       => 'Jacob',
        'partner2_name'       => 'Melissa',
        'partner1_full_name'  => 'Jacob Stephens',
        'partner2_full_name'  => 'Melissa Longua',

        // Credit line in the footer
        'site_author_name'    => 'Jacob Stephens',
        'site_author_url'     => 'https://stephens.page/',

        // Event
        'wedding_date'        => '2026-04-11', // ISO 8601; drives the countdown
        'wedding_city'        => 'Philadelphia',

        'ceremony_label'      => 'Nuptial Mass',
        'ceremony_venue'      => 'St. Agatha St. James Parish',
        'ceremony_address'    => '3728 Chestnut St, Philadelphia, PA 19104',
        'ceremony_time'       => '1:30 p.m.',

        'reception_venue'     => 'Bala Golf Club',
        'reception_address'   => '2200 Belmont Ave, Philadelphia, PA 19131',
        'reception_time'      => '4:00 p.m.',

        'rsvp_deadline'       => 'March 11, 2026',

        // Contact (display only; see notification recipients below)
        'contact_email'       => 'melissa.longua@gmail.com',

        // Notification recipients, editable from the admin Site Content page so
        // no .env edit is needed to change who is alerted. Comma-separated for
        // multiple. Left blank in this template: when blank, the code falls back
        // to the RSVP_EMAIL / CONTACT_EMAIL env vars. The live values are stored
        // in the database (set in the admin), not committed here.
        'rsvp_notify_emails'  => '', // who is emailed on a new RSVP
        'contact_notify_email'=> '', // who is emailed on a contact-form message

        // Branding
        'theme_color'         => '#7f8f65',
        'analytics_id'        => 'G-DQN0TVHB1Z', // GA4 measurement ID; '' to disable

        // Home page hero media (paths under the private photo/video stores)
        'home_video'          => 'Jacob_and_Melissa_proposal_mobile.mp4',
        'home_poster'         => 'proposal/PeytoLakeBanff_Proposal_One_Knee_wide.jpg',

        // Gallery page external links + video embeds ('' hides the related element)
        'gallery_full_url'        => 'https://drive.google.com/drive/folders/1DrRDH8wAYEs1x7WPUVAFXLeJdOEmcnRN?usp=sharing',
        'gallery_bw_url'          => 'https://baronephoto.pic-time.com/client/bwportraitsjacobmelissa/gallery?ptat=AAAAAAIBAABa2GXe-gp4DBjRNYJ3qh8D9Yh0ygjq10fR_tKNeQ,,&inviteptoken2=AAAAABUBAAB6MSBxUrIu5TH4WE_efiRjIA,,',
        'wedding_video_url'       => 'https://player.vimeo.com/video/1190695875?h=e325e0040b',
        'wedding_video_url_2'     => 'https://www.youtube-nocookie.com/embed/RUJWq4K5kW8',

        // Contact page card ('' hides a line)
        'contact_phone_label'     => "Jacob's Cell",
        'contact_phone'           => '(484) 356-7773',

        // Payment / mailing details, shared by the contact card and registry funds
        'payment_venmo'           => '@Melissa-Longua',
        'check_payee_name'        => 'Jacob Stephens',
        'mailing_address_line1'   => '3815 Haverford Ave, Unit 1',
        'mailing_address_line2'   => 'Philadelphia, PA 19104',

        // Registry honeymoon fund
        'honeymoon_destination'   => 'Puerto Rico',
    ],

    // ---------------------------------------------------------------------
    // Blocks: long-form page prose. Ordered by `sort`. `heading` may be ''.
    // ---------------------------------------------------------------------
    'blocks' => [

        'story' => [
            [
                'section_key' => 'a_prayer_and_dance',
                'heading'     => 'A Prayer and a Dance',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p class="scripture-quote">He came so that we might have life, and have it in abundance.<span class="reference">&mdash; John 10:10</span></p>
<p>In early October of 2024, Jacob moved to Philadelphia, looking for new life and a fresh start. On October 7, he found himself praying using the words of Percy Mayfield, &ldquo;Please send me someone to&nbsp;love.&rdquo;</p>
<p>Two months prior, I had also moved to Philly from the small town of Steubenville, Ohio, feeling called to leave behind the last 7 years of familiar comforts, like weekly tap dancing at a local studio and hosting monthly brunches with friends after church. I felt compelled and spoken to by the Lord through the words of Aly Aleigha, &ldquo;Into the unexplored enclave, little bird, take&nbsp;flight.&rdquo;</p>
<p>I knew that starting a new life in a new city meant finding a new community. And I knew that I could find community through church, and also through my favorite hobby&nbsp;&mdash; dancing. I started visiting different parishes and searching for West Coast Swing, Salsa, and Lindy Hop dance socials, and soon was invited to attend a Blues and Fusion event on October 18th in Old&nbsp;City.</p>
{{carousel:a_prayer_and_dance}}
HTML,
            ],
            [
                'section_key' => 'the_sidewalk',
                'heading'     => 'The Sidewalk',
                'sort'        => 20,
                'body'        => <<<'HTML'
<p>A block away from the Liberty Bell, standing outside the Concierge Ballroom and waiting for the doors to open, Jacob and I met. He was tall, handsome, and vivacious&nbsp;&mdash; with a kind of golden retriever-energy. The night started with a blues lesson, during which we rotated partners frequently. While I was occupied with learning the style&rsquo;s rhythm and the names of my quickly turning-over partners, Jacob has since admitted that he was stealing glances through the&nbsp;mirror.</p>
<p>At the end of the night, as I gathered my belongings, I caught sight of Jacob across the room out of the corner of my eye. I had this sense that he wanted to talk to me, and curious to see where things might go, I paused by the door to fill out an interest form and give him the chance to&nbsp;approach.</p>
<p class="story-accent">Bingo.</p>
<p>He came over, we chatted a bit more, and then said our goodbyes. After I turned and walked out, he made note of my name from the interest form. The next morning, I saw I had a friend request from Jacob Stephens (sent at 2am the night&nbsp;before).</p>
<p>A week later, I ran into Jacob again&nbsp;&mdash; this time at a Lindy Hop social at Cherry St Harbor. We danced several songs over the evening before I ran off to welcome friends from out of town, who had come to celebrate my birthday. Jacob intentionally asked for one last song before I left, so that he could be my last dance on my&nbsp;birthday.</p>
{{carousel:the_sidewalk}}
HTML,
            ],
            [
                'section_key' => 'tacos_and_theology',
                'heading'     => 'Tacos and Theology',
                'sort'        => 30,
                'body'        => <<<'HTML'
<p>Another week later, on November 1, the Feast of All Saints, we both found ourselves back at the Concierge Ballroom for a Balboa dance. As we danced and conversed about our days, I mentioned I had gone to Mass for the feast&nbsp;day.</p>
<p>Jacob&rsquo;s ears pricked&nbsp;&mdash; he had been feeling drawn to explore the Catholic Church and join a formation class to learn more. At this time I still hadn&rsquo;t settled on a parish community for myself, but had been to St. Agatha St. James a couple of times and thought the church was beautiful and the community faithful. I invited him to join me sometime, hoping I could snag him for the Church and maybe also&nbsp;myself&hellip;</p>
<p>The next day, Jacob invited me to join him and a couple other dance friends in seeing live theater&nbsp;&mdash; <em>The Obama Musical</em> (which, if you&rsquo;re wondering, was not very good but <em>was</em> entertaining, if at times a bit of a fever dream). Afterwards we got tacos, and somehow ended up on the topic of Pope John Paul II&rsquo;s Theology of the Body while he drove me&nbsp;home.</p>
<p>It was at this point that I really started wondering, &ldquo;Who is this man, and where did he come from? How do I make sure I sound as interesting to him as he does to&nbsp;me?&rdquo;</p>
{{carousel:tacos_and_theology}}
HTML,
            ],
            [
                'section_key' => 'the_balcony',
                'heading'     => 'The Balcony',
                'sort'        => 40,
                'body'        => <<<'HTML'
<p>Through November, we texted with some regularity, carpooled to dance events, and even cooked in my kitchen together for a dance group&rsquo;s Friendsgiving dinner. I told him I was going to see <em>Hamilton</em> (much better than <em>The Obama Musical</em>) with friends from church, and from my seat sent him a picture of my view of the stage to tease him about not being there. Apparently that grainy photo from my cheap Android and his growing desire to leave an impression on me were enough for him to find me in the balcony at intermission with a ticket he bought last minute. As the lights flickered and Act II started, I frantically texted my future maid-of-honor, asking for confirmation of if I was living in a Hallmark&nbsp;movie.</p>
<p>One Sunday, Jacob joined me for Mass, and I noticed him get emotional before I went up to receive Communion. He was recalling his prayer from October 7, and there in the church next to me felt with certainty that God was answering it. Knowing that he could not yet receive the Eucharist as he was still in formation, I pulled up an Act of Spiritual Communion for him. He read it, and the tears started flowing. He later shared that he was deeply moved by the parts of the prayer mentioning <em>a contrite heart</em> and <em>the poor dwelling my heart offers You</em>. He felt the ache of his old self, and a beckoning towards a newness of life. I myself was grateful for this encounter I could tell he was having with Christ, and wondered how this new relationship in my life would play out.</p>
{{carousel:the_balcony}}
HTML,
            ],
            [
                'section_key' => 'the_first_snow',
                'heading'     => 'The First Snow',
                'sort'        => 50,
                'body'        => <<<'HTML'
<p>We finally went on our first official date, only a month after meeting but it felt long overdue. After witnessing me make a sausage dish for Friendsgiving, a day after having made the same for another group dinner, he was convinced&nbsp;&mdash; &ldquo;This girl <em>LOVES</em> sausage&rdquo;&nbsp;&mdash; and found a local, vibey spot known for their sausage sandwiches, hoping to&nbsp;impress.</p>
<p class="story-footnote"><em>Note: I like sausage. But the back-to-back dishes were more about the convenience than any preoccupation Jacob assumed I had with the ground&nbsp;pork.</em></p>
<p>We went on two more dates, I daringly wrote him half of a poem for his half birthday, and then he went radio silent for three days. So when he finally responded by showing up unannounced on my doorstep with two chicken maroosh sandwiches, a massive tub of hummus, and two kinds of baklava, my brain short-circuited. I apologized for my inability to sit and have dinner with him as I was just about to run out for the evening. But later that night we reconnected, walked through the first snow of the year, and after some prodding&nbsp;&mdash; I drew out his intentions. He envisioned a long journey ahead for&nbsp;us.</p>
<p class="story-accent">With wonder and joy, I accepted his invitation.</p>
{{carousel:the_first_snow}}
HTML,
            ],
            [
                'section_key' => 'pastaio',
                'heading'     => 'Pastaio',
                'sort'        => 60,
                'body'        => <<<'HTML'
<p>At Christmastime, we walked through the LumiNature exhibit at the Philadelphia Zoo. A jazzy <em>Winter Wonderland</em> started playing under an illuminated candy cane archway, and we broke into swing dancing on the path. A passerby mistook us Ginger Rogers and Fred&nbsp;Astaire.</p>
<p>For New Year&rsquo;s, we drove to Boston for Countdown Swing&nbsp;&mdash; a five-day West Coast Swing convention I had almost backed out of before Jacob offered to make the trip with me. At two in the morning on New Year&rsquo;s Day, I asked him for one last dance in the vacant lobby next to the ballroom, just the two of us, before parting ways for&nbsp;bed.</p>
<p>We started the Rosary in a Year podcast together, catching up on the first week of episodes side by side on his couch. He was new to the prayer and I was always bad at praying rosaries, so we embarked on this together, encouraging each other to remain faithful and persist in daily&nbsp;prayer.</p>
<p>In late January, temperatures were frigid, and I asked Jacob if he could give me rides to work so I could escape the long walk to the bus stop while it was so cold out. Daily rides soon became the norm, even after temperatures came back&nbsp;up.</p>
<p>In early March, I brought Jacob to Steubenville&nbsp;&mdash; the town where I had spent my whole college and post-college life up to my move to Philly. After attending a praise and worship event at my alma mater, we went to dinner at Pastaio, the only &ldquo;fancy restaurant&rdquo; in the area (which is actually really good&nbsp;&mdash; they made it onto an episode of &ldquo;America&rsquo;s Best Restaurants&rdquo;&nbsp;&mdash; check it out if you ever find yourself about 45 minutes west of Pittsburgh). With plates of pasta between us, I told him I loved him. He had been waiting to tell me the same.</p>
{{carousel:pastaio}}
HTML,
            ],
            [
                'section_key' => 'the_novena',
                'heading'     => 'The Novena',
                'sort'        => 70,
                'body'        => <<<'HTML'
<p>A week after Easter, on Divine Mercy Sunday, April 27th, 2025, Jacob was received into full communion with the Catholic Church at St. Agatha St. James&nbsp;&mdash; confirmed, and receiving the Eucharist for the first&nbsp;time.</p>
<p>Some of Jacob&rsquo;s early-on sleuthing led to him discovering that, a week before we met at the fusion dance on October 18th, we were in the same room together dancing lindy hop to Chelsea Reed and the Fairweather Band, although our paths never crossed that night. On May 3rd, Chelsea Reed returned to Philadelphia for another show. We made sure to see each other this time.</p>
<p>Over these months, we also faced challenges beyond our control, and during which all we could do was wait and pray for a clear path forward for our relationship. By June, the wait became unbearable, and we decided to pray a very intentional novena for the future of our relationship. On June 13, only the second day of our nine-day novena, we received the answer to our prayers. Assured of God&rsquo;s will for our future, we began to envision a new and richer life together in more detail than we had previously allowed&nbsp;ourselves.</p>
{{carousel:the_novena}}
HTML,
            ],
            [
                'section_key' => 'the_blessing',
                'heading'     => 'The Blessing',
                'sort'        => 80,
                'body'        => <<<'HTML'
<p>In early September, Jacob excused himself from my company one evening, claiming he was &ldquo;busy.&rdquo; He drove an hour out to the suburbs to meet my parents for dinner and ask for my hand. My parents joyfully gave their blessing, and upon leaving the restaurant, they noticed a rainbow in the sky, and thought of the Biblical symbolism of promise and&nbsp;covenant.</p>
{{carousel:the_blessing}}
HTML,
            ],
            [
                'section_key' => 'proposal',
                'heading'     => 'Written in Stone and Sky',
                'sort'        => 90,
                'body'        => <<<'HTML'
<p>Jacob insisted I request off from work on September 26, and that evening as our plane descended, I was quite surprised to finally take out my earbuds and hear the pilot welcome us to Calgary, in the province of Alberta, Canada. We spent the next day, September 27th, hiking in Banff National Park, and after a pitstop at the hostel to shower and &ldquo;put on something nice,&rdquo; we were driving the Icefields&nbsp;Parkway.</p>
<p>We walked to a rocky overlook on Mount Jimmy Simpson, above Peyto Lake. The deep, looming clouds sat heavy in the valley, and we were surrounded by the grandeur of the Canadian Rockies. Below, the lake sat quiet, still, and brilliant blue. We braced ourselves against the chilling mountain winds and falling mist. A guitarist starting playing a sweet, acoustic cover of <em>Can&rsquo;t Help Falling In Love</em>, and Jacob knelt down before&nbsp;me.</p>
<p class="story-accent">I said yes<br>
<small style="font-size: 18px;">...and seven hours later we were back on a plane to&nbsp;Philadelphia.</small></p>
{{carousel:proposal}}
<div class="story-media">
    <iframe src="https://www.youtube.com/embed/iEbqiWzH800" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
</div>
<div style="margin: 2rem 0;">
    <p>The next day, our pastor Fr. Remi Morales blessed our engagement during a small ceremony surrounded by our parents and&nbsp;friends.</p>
</div>
<div class="story-media">
    <iframe src="https://www.youtube.com/embed/dko2cded45E" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
</div>
{{carousel:blessing}}
<p>Afterward, our families shared a meal together, and we celebrated this turning point in our lives&nbsp;&mdash; turning to a new adventure and embracing a new, joined life&nbsp;together.</p>
HTML,
            ],
            [
                'section_key' => 'divine_mercy',
                'heading'     => 'Divine Mercy&rsquo;s Design',
                'sort'        => 100,
                'body'        => <<<'HTML'
<p>Our Nuptial Mass will be on April 11, 2026 at 1:30&nbsp;p.m. at St. Agatha St. James Parish in Philadelphia. The reception will follow at 4:00&nbsp;p.m. at Bala Golf&nbsp;Club.</p>
{{blockimages:divine_mercy}}
<p style="margin-top: 2rem;"><em>It&rsquo;s been quite the journey of faith and hope. God has been present every step of the way. We still love dancing and being very involved in our parish community, and are excited to be preparing for our sacramental wedding. Jacob entered the Catholic Church in fullness on Divine Mercy Sunday, 2025, and our wedding date is set for the eve of Divine Mercy Sunday, 2026. God has made us new and continues to make us new and give us new life and new hearts, and we see His beauty and His hand in our Easter Octave wedding&nbsp;date.</em></p>
HTML,
            ],
            [
                'section_key' => 'wedding_photos',
                'heading'     => 'Wedding Photos &amp; Video',
                'sort'        => 110,
                'body'        => <<<'HTML'
<div class="featured-galleries" aria-label="Featured galleries">
    <a class="featured-card" href="https://baronephoto.pic-time.com/client/jasonmelissa/gallery?inviteToken=AAAAAMwAAAAHmY4IMZZirXjlVnb1WMV4Lw,,&amp;inviteptoken2=AAAAAJcAAAAJmicE83IkZpTwK9b6o7Cspw,,&amp;s=%7B%22blockId%22%3A%22gb_103483103%22%2C%22itemId%22%3A11902344563%2C%22fullScreen%22%3Afalse%7D"
       target="_blank" rel="noopener">
        <img class="featured-photo" src="/images/wedding-color-highlight.jpg" alt="Jacob and Melissa on their wedding day" loading="lazy">
        <div class="featured-body">
            <h3>Wedding Photo Gallery</h3>
            <p>The full set of photos from our wedding day, hosted by Barone Photo.</p>
            <span class="featured-cta">View the full gallery &rarr;</span>
        </div>
    </a>

    <a class="featured-card" href="https://baronephoto.pic-time.com/client/bwportraitsjacobmelissa/gallery?ptat=AAAAAAIBAABa2GXe-gp4DBjRNYJ3qh8D9Yh0ygjq10fR_tKNeQ,,&amp;inviteptoken2=AAAAABUBAAB6MSBxUrIu5TH4WE_efiRjIA,,"
       target="_blank" rel="noopener">
        <img class="featured-photo" src="/images/wedding-bw-highlight.jpg" alt="Black-and-white portrait of Jacob and Melissa" loading="lazy">
        <div class="featured-body">
            <h3>Black &amp; White Portraits</h3>
            <p>A curated set of black-and-white portraits from our wedding.</p>
            <span class="featured-cta">View the B&amp;W set &rarr;</span>
        </div>
    </a>
</div>

<div class="story-video-embed">
    <iframe src="https://player.vimeo.com/video/1190695875?h=e325e0040b"
            title="Jacob and Melissa Wedding Video"
            frameborder="0"
            allow="autoplay; fullscreen; picture-in-picture"
            allowfullscreen
            loading="lazy"></iframe>
</div>
HTML,
            ],
        ],

        'about' => [
            [
                'section_key' => 'schedule',
                'heading'     => 'Schedule',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p><strong>Nuptial Mass</strong> &mdash; 1:30&nbsp;p.m. at St. Agatha St. James Parish, 3728 Chestnut St, Philadelphia, PA&nbsp;19104</p>
<p><strong>Reception</strong> &mdash; 4:00&nbsp;p.m. at Bala Golf Club, 2200 Belmont Ave, Philadelphia, PA&nbsp;19131</p>
HTML,
            ],
            [
                'section_key' => 'children_welcome',
                'heading'     => 'Children Welcome',
                'sort'        => 20,
                'body'        => <<<'HTML'
<p>We are delighted to have children join us in celebrating our wedding! Little ones are welcome at both the ceremony and reception. We understand that children may need to move around or make some noise, and that's perfectly fine with us. We want all our loved ones, including the youngest ones, to be part of this special day.</p>
HTML,
            ],
            [
                'section_key' => 'nuptial_mass',
                'heading'     => 'The Nuptial Mass',
                'sort'        => 30,
                'body'        => <<<'HTML'
<p>Our wedding ceremony will be a Nuptial Mass, which is a full Catholic Mass that includes the celebration of the Sacrament of Matrimony. The Mass will include readings from Scripture, the exchange of vows, and the celebration of the Eucharist (Holy Communion).</p>
HTML,
            ],
            [
                'section_key' => 'holy_communion',
                'heading'     => 'Holy Communion',
                'sort'        => 40,
                'body'        => <<<'HTML'
<p>During the Mass, we will celebrate Holy Communion. In accordance with Catholic teaching, Holy Communion is reserved for Catholics who are in a state of grace and have received their First Holy Communion. If you are not Catholic or are not able to receive Communion, we invite you to remain in your seat during the distribution of Communion, or you may come forward with your arms crossed over your chest to receive a blessing from the priest. We are grateful for your presence and participation in our celebration, regardless of whether you receive Communion.</p>
HTML,
            ],
            [
                'section_key' => 'sunday_mass',
                'heading'     => 'Sunday Mass',
                'sort'        => 50,
                'body'        => <<<'HTML'
<p>For guests who would like to attend Sunday Mass on April&nbsp;12, here are a couple of nearby options:</p>
<p><strong>St. Agatha St. James Parish</strong> &mdash; 9:00&nbsp;a.m. and 11:30&nbsp;a.m. (with our amazing choir) at 3728 Chestnut St, Philadelphia, PA&nbsp;19104</p>
<p><strong>Cathedral Basilica of Saints Peter and Paul</strong> &mdash; 8:00&nbsp;a.m., 9:30&nbsp;a.m., and 11:00&nbsp;a.m. at 1723 Race St, Philadelphia, PA&nbsp;19103</p>
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
    <h3>St. Agatha St. James Parish</h3>
    <p class="address">3728 Chestnut St, Philadelphia, PA 19104</p>
    <p>Nuptial Mass at 1:30&nbsp;p.m.</p>
    <p>Street parking nearby is metered and generally available. Additionally, there is a parking garage on the block at <a href="https://facilities.upenn.edu/maps/locations/parking-garage-119-s-38th-street" target="_blank" rel="noopener noreferrer">Walnut 38 Parking Garage</a> (119 S. 38th Street) for approximately $15-19 for all day parking. There are six free spots after 1p in the Santander Bank parking lot across the street from the&nbsp;church.</p>
</div>

<div class="location-parking">
    <h3>Bala Golf Club</h3>
    <p class="address">2200 Belmont Ave, Philadelphia, PA 19131</p>
    <p>The reception begins at 4:00&nbsp;p.m. The venue is offering complementary valet parking.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'transportation',
                'heading'     => 'Transportation',
                'sort'        => 20,
                'body'        => <<<'HTML'
<p>Both wedding locations are accessible by various transportation methods:</p>

<div class="transportation-option">
    <h3>Public Transit</h3>
    <p>Philadelphia's SEPTA system provides service to both locations. The church is accessible via SEPTA's Market-Frankford Line and various bus routes. The reception venue is accessible by SEPTA Regional Rail and bus routes.</p>
</div>

<div class="transportation-option">
    <h3>Rideshare</h3>
    <p>Uber and Lyft are readily available throughout Philadelphia and provide convenient transportation to both the ceremony and reception.</p>
</div>

<div class="transportation-option">
    <h3>Driving</h3>
    <p>If you're driving, please see the parking information above for each location. We recommend using GPS navigation to find the most convenient route.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'accommodations',
                'heading'     => 'Accommodations',
                'sort'        => 30,
                'body'        => <<<'HTML'
<p class="accommodations-note"><strong>Note:</strong> You may find rooms closer to Bala Golf Club (the reception venue) at a lower rate than the room block by searching independently. We encourage you to compare options to find what works best for&nbsp;you.</p>

<div class="accommodation-option">
    <h3>Residence Inn by Marriott — Room Block</h3>
    <p class="address">615 Righters Ferry Rd, Bala Cynwyd, PA 19004</p>
    <p>We've reserved a block of rooms at the Residence Inn by Marriott for our wedding guests at a group rate of <strong>$300 per night</strong>. <a href="https://app.marriott.com/reslink?id=1769545389844&key=GRP&app=resvlink" target="_blank" rel="noopener noreferrer">Book through our room block here</a>. The rooms are reserved through March&nbsp;11.</p>
</div>

<div class="accommodation-option">
    <h3>Courtyard by Marriott Philadelphia City Avenue — Room Block</h3>
    <p class="address">4100 Presidential Boulevard, Philadelphia, PA 19131</p>
    <p>We've reserved another block of rooms at the Courtyard by Marriott for our wedding guests at a group rate of <strong>$200 per night</strong>. <a href="https://app.marriott.com/reslink?id=1770919186656&key=GRP&app=resvlink" target="_blank" rel="noopener noreferrer">Book through our room block here</a>.
    The rooms are reserved through March&nbsp;10.</p>
</div>
HTML,
            ],
            [
                'section_key' => 'out_of_town',
                'heading'     => 'Coordinating Out-of-Town Guests',
                'sort'        => 40,
                'body'        => <<<'HTML'
<p>We're happy to help coordinate travel arrangements for out-of-town guests. If you're traveling from out of town and need assistance with travel planning, group accommodations, or have questions about getting to Philadelphia, please reach out to us through the <a href="/contact">contact form</a> or email <a href="mailto:melissa.longua@gmail.com">melissa.longua@gmail.com</a>. We can help coordinate shared transportation, group hotel bookings, and provide recommendations for the best ways to get to the wedding venues.</p>
HTML,
            ],
        ],

        'blessing' => [
            [
                'section_key' => 'blessing',
                'heading'     => '',
                'sort'        => 10,
                'body'        => <<<'HTML'
<p>The day after the proposal, September 28, 2025, Fr. Remi Morales of St. Agatha St. James parish blessed Jacob and Melissa's engagement, surrounded by many friends and their parents. Afterwards, Jacob, Melissa, their parents, and Melissa's brother Matt went to dinner in South Philly at Scannichio's.</p>
<div class="story-media">
    <img src="/assets.php?type=photo&path=blessing/Landscape_JM_at_Altar.jpg" alt="Jacob and Melissa at altar" class="clickable-image">
    <img src="/assets.php?type=photo&path=blessing/Portrait_JM_at_Altar.jpg" alt="Jacob and Melissa at altar portrait" class="clickable-image">
    <img src="/assets.php?type=photo&path=blessing/JM_With_Parents_at_Scannichios.jpg" alt="Jacob and Melissa with parents at Scannichio's" class="clickable-image">
    <iframe src="https://www.youtube.com/embed/dko2cded45E" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
</div>
HTML,
            ],
        ],
    ],
];
