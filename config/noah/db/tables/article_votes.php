<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Article votes DB table
    |--------------------------------------------------------------------------
    |
    | One row per reader per article: did this help, yes or no. Written by the
    | thumbs on a help page, read to order the footer's Help & tips column and
    | to print a tally once there are enough votes to mean anything.
    |
    | Keyed on `slug` and `voter` together, which is the whole design:
    |
    |   - A reader votes once per article. The key is the guard, so a repeated
    |     post is an update rather than a second row and there is no counter to
    |     inflate. That is also why this table needs no rate limiting, and this
    |     app has none anywhere.
    |   - Changing your mind works. Thumbs up then thumbs down leaves one row
    |     reading down, not one of each cancelling out.
    |
    | Rows rather than two counters on one row, because a counter cannot answer
    | "has this reader already voted" and cannot be corrected. It also leaves
    | `voted_at` on every vote, so a trend over time is a later feature with no
    | schema change -- the reason the rates table is shaped the way it is.
    |
    | There is deliberately no seeder CSV for this table, and the reason is a
    | convention the app already keeps: no demand counter here is ever seeded.
    | `airports.search_count` and `airlines.book_count` both default to 0,
    | appear in no seeder, and only ever move when somebody really searches or
    | really books -- so all five data-driven footer columns start level and
    | earn their order. Seeded votes would make this the one fabricated demand
    | signal in the app.
    |
    | It would also be a fabrication the site states out loud rather than just
    | sorts by. Past a threshold the page prints "N of M readers found this
    | helpful", and on a freshly installed database those readers would not
    | exist. Synthetic flights and a random `flights.rating` are invented stock,
    | which is a different thing from inventing what a reader thought.
    |
    | So an untouched install shows no figures and orders the footer column by
    | its tiebreaker, exactly as the other five columns do before anybody has
    | used the site.
    |
    | `slug` and not an article id. It was chosen when articles were entries in
    | config/common/help.php and had no id to borrow, on the reasoning that a
    | slug is also the URL and would therefore survive them becoming rows. They
    | have, and it did: `articles` is keyed on slug too, so these votes join
    | onto it and nothing had to be migrated. Adding an integer id now would
    | mean rewriting every row here.
    |
    | `voter` is the `tb_voter` cookie, minted only when somebody actually votes
    | -- see src/Voter.php, including what it does not defend against. There are
    | no accounts in this app to hang a vote on, and the alternative identifier,
    | an IP address, is one this site would then have to say it keeps.
    |
    | `helpful` is 1 or 0 rather than a sign, so the yeses are SUM(helpful) and
    | the votes are COUNT(*) and neither reading needs explaining.
    |
    */

    'primary' => 'slug, voter',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'slug',
            'type' => 'varchar',
            'length' => 64,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'A key into articles.slug, and the article URL',
        ],
        [
            'name' => 'voter',
            'type' => 'char',
            'length' => 32,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The tb_voter cookie token, 32 hex characters',
        ],
        [
            'name' => 'helpful',
            'type' => 'tinyint',
            'length' => 1,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => '1 for a thumbs up, 0 for a thumbs down',
        ],
        [
            'name' => 'voted_at',
            'type' => 'datetime',
            'length' => null,
            'default' => ['CURRENT_TIMESTAMP'],
            'nullable' => false,
            'auto_inc' => false,
            'comment' => false,
        ],
    ],
];
