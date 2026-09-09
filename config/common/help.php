<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Help articles
    |--------------------------------------------------------------------------
    |
    | The five questions the footer has been promising answers to since the
    | footer was written, and the last family of pages on this site that
    | answered 404.
    |
    | Only the index is here. The prose lives in a template per article under
    | frontend/template/help/articles/, because prose belongs in a template and
    | not in a PHP array -- but the slug, the title and the one-line summary are
    | needed in four places (the hub, the article's own head, the footer column
    | and the sitemap), so they are written once here.
    |
    | The keys are the addresses. `/help/<key>` is the whole URL: there is no
    | code on the end, unlike every other page family, because an article is not
    | a record and its name is the only thing that identifies it. Adding an
    | article is an entry here and a template with a matching name -- the
    | routing, the hub, the sitemap and the cross-links all read this list.
    |
    | `summary` is one sentence and is used as written: as the meta description,
    | as the card's line on the hub, and as the lead under the article's own
    | heading. Three copies of a sentence that has to say the same thing are
    | three chances for it to stop saying it.
    |
    | The order here is no longer the order the footer lists them in -- that is
    | decided by the votes readers leave, and this is only what breaks a tie.
    | It still puts the two people actually arrive asking about, bags and money,
    | first, which is what an unvoted database shows.
    |
    | `short` is the article's name where a column is too narrow for its title,
    | and is optional: without one the footer uses `title`. It lives here rather
    | than in the footer's own config because it is a fact about the article,
    | and because the footer column is now built from this list.
    |
    */

    'articles' => [
        'baggage' => [
            'title' => 'Baggage',
            'icon' => 'fa-suitcase-rolling',
            'summary' => 'What you can bring is set by the fare you pick, and every'
                . ' fare says so in six lines before you pay.',
        ],
        'refunds' => [
            'title' => 'Refunds and exchanges',
            // "&", not "and": measured in the real face at 15px, the spelled
            // out version is 170px against the 166 a footer column gives it,
            // and the ampersand brings it to 153. The heading above it is
            // "Help & tips", so the column was already written this way.
            'short' => 'Refunds & exchanges',
            'icon' => 'fa-rotate-left',
            'summary' => 'Whether a ticket can be changed or given back is fixed by the'
                . ' fare when it is bought, not decided afterwards.',
        ],
        'ticket-not-received' => [
            'title' => 'Ticket did not arrive',
            'icon' => 'fa-envelope',
            'summary' => 'The booking exists from the moment you see a reference. The'
                . ' email is a copy of it, not the ticket itself.',
        ],
        'passenger-details' => [
            'title' => 'Changing passenger details',
            // Two lines in a column that narrow, spelled in full. The footer
            // has never promised to repeat a page's title -- the Airports
            // column beside it says "London (LHR)" where the page says
            // "Heathrow".
            'short' => 'Passenger details',
            'icon' => 'fa-passport',
            'summary' => 'Names are checked against the document you travel on, so'
                . ' checkout is the moment to get them right.',
        ],
        'flying-with-children' => [
            'title' => 'Flying with children',
            'icon' => 'fa-child',
            'summary' => 'Whether a child gets a seat of their own decides both what'
                . ' they pay and how many of you can travel together.',
        ],
    ],
];
