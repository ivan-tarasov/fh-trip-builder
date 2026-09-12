<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limit counters DB table
    |--------------------------------------------------------------------------
    |
    | How many times one client has done one thing in one hour. Three endpoints
    | take writes from anybody with `curl` and no account -- the fare-alert
    | subscribe, the two vote endpoints and checkout -- and before this there
    | was nothing between a script and as many rows as it cared to insert.
    |
    | A table and not Redis. The app has four production dependencies and a
    | database it is already talking to on every one of these requests; a fifth
    | service to install, run and monitor is a larger cost than the counting.
    |
    | Keyed by the hour it belongs to rather than by a sliding window. A row per
    | (scope, client, hour) is one upsert and one read, where a sliding window
    | means keeping every event and counting them. The cost is that the limit
    | resets on the hour instead of rolling -- so the worst case is twice the
    | limit across a boundary, which for ten subscribes is twenty and still a
    | wall for the thing this stops.
    |
    | `client` is the IP and nothing else. The session id was considered and
    | left out: it is a cookie, a script drops it and gets a fresh bucket, so
    | putting it in the key builds the bypass in. If a shared address ever
    | collides for real people, the answer is a higher limit and not a weaker
    | key.
    |
    | 45 characters because that is the longest IPv6 form -- an IPv4-mapped
    | address like `::ffff:255.255.255.255` with a zone id.
    |
    | Nothing prunes this yet. A row is dead the hour after it is written and is
    | never read again, so the table grows by the number of distinct clients per
    | hour and is harmless at this size; `RateLimitRepository::prune()` is the
    | sweep, and E9 (#146) is where it gets called from.
    |
    */

    'primary' => 'scope, client, window_start',
    'engine' => 'InnoDB',
    'charset' => 'ascii',

    'columns' => [
        [
            'name' => 'scope',
            'type' => 'varchar',
            'length' => 16,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Which limit this counts -- a RateLimit enum value',
        ],
        [
            'name' => 'client',
            'type' => 'varchar',
            'length' => 45,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The caller, as an IP address',
        ],
        [
            'name' => 'window_start',
            'type' => 'datetime',
            'length' => null,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'The hour this row counts, truncated to :00:00',
        ],
        [
            'name' => 'hits',
            'type' => 'int',
            'length' => 9,
            'default' => false,
            'nullable' => false,
            'auto_inc' => false,
            'comment' => 'Requests seen in that hour',
        ],
    ],
];
