<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legal documents
    |--------------------------------------------------------------------------
    |
    | The three pages every site that stores anything is expected to have, and
    | that this one has been missing while it loaded two analytics vendors and
    | wrote passenger names to a table.
    |
    | The shape the help articles had before they became rows, and for the
    | same reasons: the slug,
    | the title and the one-line summary are needed by the page head, the
    | footer row, the breadcrumb and the sibling links, so they are written once
    | here, and the prose lives in a template per document because prose belongs
    | in a template.
    |
    | The keys are the addresses. `/privacy` is the whole URL -- these sit at
    | the root rather than under a `/legal/` hub, because that is where every
    | site puts them and where anybody looking will look first. Each one needs
    | its own line in Routes::ENABLED_ROUTES, which is what puts it in the
    | sitemap and lets it be indexed.
    |
    | On the wording: these describe what this code actually does, checked
    | against it line by line rather than adapted from a template. Where the
    | usual boilerplate would claim a process this app does not have -- a
    | retention schedule, a way to ask for your data back, a named controller --
    | the page says there is none instead of inventing one. That makes them
    | honest, not sufficient: a real site needs a lawyer, and these say so.
    |
    */

    'documents' => [
        'privacy' => [
            'title' => 'Privacy',
            'icon' => 'fa-user-shield',
            'summary' => 'What this site records about you, who else gets to see it,'
                . ' and why a demonstration still collects real data.',
        ],
        'terms' => [
            'title' => 'Terms',
            'icon' => 'fa-file-contract',
            'summary' => 'What you are agreeing to on a site that sells nothing: no'
                . ' ticket is issued, no money moves, and the flights are invented.',
        ],
        'cookies' => [
            'title' => 'Cookies',
            'icon' => 'fa-cookie-bite',
            'summary' => 'The three cookies this site sets, the two analytics vendors'
                . ' it loads on every page, and what each one is for.',
        ],
    ],
];
