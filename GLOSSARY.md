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
is cash, while its mutation source is offregistry.

**Gift status**: The independently tracked received, thank-you written, and
thank-you sent flags and their corresponding stage timestamps. Received applies
only to purchased registry items. Written and sent also apply to manually
recorded gifts, including cash, and House/Honeymoon fund contributions.
Implemented by `Wedding\GiftStatus` in `src/GiftStatus.php`; shared by the JSON
toggle action and the Gift Manager's fallback `POST` forms.

# Announcements

**Announcement audience selection**: The guest choices and custom recipients for
an Announcement. An unrestricted selection includes all matching guests, while
an explicit selection includes only checked guests and can include zero guests.

**Saved audience**: Reusable custom recipient text that an administrator can
prefill and edit in the Announcement composer.
