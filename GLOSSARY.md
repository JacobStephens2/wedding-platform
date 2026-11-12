# Gift tracking

**Gift overview**: A read-only snapshot combining purchased registry items,
manually recorded gifts including cash, and House/Honeymoon fund contributions
into display rows and summary values for gift tracking. Implemented by
`Wedding\GiftOverview` in `src/GiftOverview.php`; rendered by `public/admin-gifts.php`.

**Display source**: The gift's table classification: registry, offregistry, cash,
housefund, or honeymoonfund. It determines the badge, search aliases, sort group,
and mutually exclusive value subtotal.

**Mutation source**: The existing identifier used by editing and thank-you
actions: registry, offregistry, housefund, or honeymoonfund. Cash's display source
is cash, while its mutation source is offregistry and its fallback toggles use
the gift parameter prefix.
