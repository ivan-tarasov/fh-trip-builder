<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airside post votes DB table
    |--------------------------------------------------------------------------
    |
    | One row per reader per post: was this worth reading, yes or no. The
    | sibling of `article_votes`, written by the same thumbs and read for the
    | same two purposes -- a tally on the page, and an order for a block.
    |
    | Separate from `article_votes` rather than a `kind` column on it, for the
    | reason A8 gives about `posts` and `articles`: the two families are joined
    | to different tables and read in different orders, and one table would mean
    | every query saying which half it meant.
    |
    | `slug` and `voter` together, which is the whole design: one person, one
    | vote per post, and changing your mind updates the row rather than adding
    | a second.
    |
    | `voter` is the `tb_voter` cookie, minted only when somebody actually votes
    | -- see src/Voter.php, including what it does not defend against. There are
    | no accounts here to hang a vote on, and the alternative identifier, an IP
    | address, is one this site would then have to say it keeps.
    |
    | `helpful` is 1 or 0 rather than a sign, so the yeses are SUM(helpful) and
    | the votes are COUNT(*) and neither reading needs explaining. The word is
    | inherited from the article table; on a post it means "worth reading"
    | rather than "this solved my problem".
    |
    | No seeder, deliberately, and this is the one that matters for what reads
    | it. A "most liked" block over posts nobody has voted on would be a
    | heading above an arbitrary list, so the block does not render until a vote
    | exists -- an honest absence rather than a confident tiebreaker. That is
    | the trap FooterRenderTest documents for the help column, avoided by not
    | drawing the thing at all.
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
            'comment' => 'A key into posts.slug, and the post URL',
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
