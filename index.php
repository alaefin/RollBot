<?php
if ( PHP_SAPI !== 'cli' ) {
    echo "This script takes long to process, it should run on the command line, exiting...\n";
    return;
}
$config = \json_decode( \file_get_contents( __DIR__ . '/rollbotconfig.json' ), true );

echo "Offender : {$config['offender']}
Start timestamp : {$config['starttimestamp']}
Wiki : {$config['wiki']}
Namespaces : " . implode( ', ', $config['namespaces'] ) . "\n";
if ( !empty( $config['nuke'] ) ) {
    echo "NUKE : yep\n";
}
echo "\n";
// This should be enough time to kill the bot
// in case the config isn't what's expected
sleep(10);

require __DIR__ . '/vendor/autoload.php';
$api = new \GuzzleHttp\Client( [ 'base_uri' => $config['wiki'], 'cookies' => true, 'headers' => [ 'User-Agent' => 'RollBot v0.1, by [[:fr:User:Alphos]]' ] ] );

// Easy API response formatting, user-account edit assertion
$format = [ 'format' => 'json', 'formatversion' => 2 ];

/********************
*       Login       *
********************/
// First, get the login token by attempting to log in
$lgtokenRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => $config['lg'] + [ 'action' => 'login' ] + $format
] )->getBody(), true );
if ( !isset( $lgtokenRequest['login']['token'] ) ) {
    echo "Invalid format for the first login attempt, exiting...\n";
    return;
}
$lgtoken = $lgtokenRequest['login']['token'];

// Then send the actual login request with the login token
$api->request( 'POST', 'w/api.php', [
    'form_params' => $config['lg'] + [ 'lgtoken' => $lgtoken ] + [ 'action' => 'login' ] + $format
] )->getBody();

// Finally check the login was indeed successful by matching the username the server
// acknowleges with the username that was provided for login
$loginCheckRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => [ 'action' => 'query', 'meta' => 'userinfo' ] + $format
] )->getBody(), true );
if ( !isset( $loginCheckRequest['query']['userinfo']['name'] ) || ( $loginCheckRequest['query']['userinfo']['name'] !== $config['lg']['lgname'] ) ) {
    echo "Login unsuccessful, exiting...\n";
    return;
}
echo "Logged in as {$config['lg']['lgname']}\n";
// Setting an additional bit so requests from now on will be assumed as performed while logged in
$format['assert'] = 'user';

/**************************
* Acquiring an edit token *
**************************/
$editTokenRequest = \json_decode( $x = $api->request( 'POST', 'w/api.php', [
    'form_params' => [
        'action' => 'query',
        'meta' => 'tokens'
    ] + $format
] )->getBody(), true );
if ( !isset( $editTokenRequest['query']['tokens']['csrftoken'] ) ) {
    echo "Couldn't acquire an edit token, exiting...\n";
    return;
}
$editToken = $editTokenRequest['query']['tokens']['csrftoken'];
echo "Edit token acquired : $editToken\n";

/************************************************
*  Checking config namespaces against the wiki  *
************************************************/
$localNamespacesRequest = \json_decode( $api->request( 'POST', $config['wiki'] . '/w/api.php',  [
    'form_params' => [
        'action' => 'query',
        'meta' => 'siteinfo',
        'siprop' => 'namespaces',
        'format' => 'json',
        'formatversion' => 2
    ]
] )->getBody(), true );
if ( !isset( $localNamespacesRequest['query']['namespaces'] ) ) {
    echo "List of namespaces unavailable for {$config['wiki']}, exiting...\n";
    return;
}
$localNamespaces = $localNamespacesRequest['query']['namespaces'];
if ( \array_diff( $config['namespaces'], \array_keys( $localNamespaces ) ) ) {
    echo "Some namespaces in the configuration do not exist on {$config['wiki']}, exiting...\n";
    return;
}

/* * ***************************
 * Get a list of pages edited *
 * *************************** */
// Setting params that need to be passed identically with every request
$baseContribsListOptions = [
    'action' => 'query',
    'list' => 'usercontribs',
    'uclimit' => 'max',
    'ucuser' => $config['offender'],
    'ucprop' => 'ids|timestamp|title',
    'ucdir' => 'newer'
];
if ( !empty( $config['namespaces'] ) ) {
    $baseContribsListOptions['ucnamespace'] = \implode( '|', $config['namespaces'] );
}

// And params that change with every request
$continueContribsListOptions = [ 'ucstart' => $config['starttimestamp'] ];

// Initializing the list of pages edited by the offender
$pagesList = [];

// Getting the list of contribs, building the list of pages
while ( true ) {
    // Getting the list of contribs
    $contribsRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
        'form_params' => $baseContribsListOptions + $continueContribsListOptions + $format
    ] )->getBody(), true );

    if ( !isset( $contribsRequest['query']['usercontribs'] ) ) {
        echo "Invalid format for the list of contribs, exiting...\n";
        return;
    }
    $contribs = $contribsRequest['query']['usercontribs'];

    // Building the list of pages
    foreach ( $contribs as $contrib ) {
        if ( !\array_key_exists( $contrib['pageid'], $pagesList ) ) {
            $pagesList[ $contrib['pageid'] ] = [ 'title' => $contrib['title'], 'ns' => $contrib['ns'] ];
        }
    }

    // Preparing to get more contribs at the next loop iteration
    if ( isset( $contribs['continue']['uccontinue'] ) ) {
        $continueContribsListOptions = [ 'uccontinue' => $contribs['continue']['uccontinue'] ];
    }
    else {
        break;
    }
}

echo "\n", $pagesCount = \count( $pagesList ), " pages edited by {$config['offender']} since {$config['starttimestamp']}\n\n";

/* * ******************************
 * Getting things back to normal *
 * ****************************** */

// Truncating/creating log pages for the current instance of the bot.
\file_put_contents( __DIR__ . '/rollbotpagesfordeletion', "\n== Pages for deletion ==\nThe following pages should be flagged for deletion (most likely because they were created by {$config['offender']} since {$config['starttimestamp']} and no other user edited them) :\n" );
\file_put_contents( __DIR__ . '/rollbotpagesforcheck', "\n== Pages requiring human check ==\nThe following pages should be checked by a human user (most likely because someone else than {$config['offender']} edited them since {$config['starttimestamp']}) :\n" );
\file_put_contents( __DIR__ . '/rollboteditlog', "\n== Pages successfully edited ==\nThe following pages were edited by {$config['offender']} since {$config['starttimestamp']}, they were reverted to a sane version :\n" );
if ( \file_exists( __DIR__ . '/rollbotreport' ) ) {
    \unlink( __DIR__ . '/rollbotreport' );
}
$pageCounter = 0;

// For each page, get the metadata of the first "bad revision" by the offender.
// This will be the base timestamp for that page.
foreach ( $pagesList as $pageId => $page ) {
    $goodRevision = [];
    $badRevisions = [];
    $otherUsers = [];
    $otherUsersMessage = "";

    if ( $pageCounter % 1 === 0 ) {
        echo \sprintf( '%' . \strlen( $pagesCount ) . 'd', ++$pageCounter ), ' /', $pagesCount, "\n";
    }

    // First, let's establish the first edit by the offender on that page since
    // the starttimestamp
    $firstBadRevisionRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
        'form_params' => [
            'action' => 'query',
            'prop' => 'revisions',
            'rvprop' => 'ids|timestamp',
            'rvstart' => $config['starttimestamp'],
            'rvdir' => 'newer', //from the start timestamp to now
            'pageids' => $pageId,
            'rvlimit' => 1,
            'rvuser' => $config['offender']
        ] + $format
    ] )->getBody(), true );
    if ( !isset( $firstBadRevisionRequest['query']['pages'][0]['revisions'][0] ) ) {
        // If no such revision was found, the page or all revisions of interest
        // have been deleted. Skipping to the next page.
        $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} since {$config['starttimestamp']}";
        \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
        continue;
    }
    // If such a revision was found, it's the one we're looking for.
    $firstBadRevision = $firstBadRevisionRequest['query']['pages'][0]['revisions'][0];

    // Now that we have our first bad revision, getting the previous revision,
    // which is guaranteed sane regardless of user.
    // This involves getting two revisions including the first bad one.
    $previousRevisionsRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
        'form_params' => [
            'action' => 'query',
            'prop' => 'revisions',
            'rvprop' => 'ids|timestamp|user|content',
            'rvstartid' => $firstBadRevision['revid'],
            'rvdir' => 'older', //from the last rev
            'pageids' => $pageId,
            'rvlimit' => 2
        ] + $format
    ] )->getBody(), true );
    if ( !isset( $previousRevisionsRequest['query']['pages'][0]['revisions'] ) ) {
        // A race condition likely occurred, with either the page or the revs
        // being probably deleted. Skipping to the next page.
        $messageForCheck = "* [[{$page['title']}]] : Could not find a good revision on that page. The revs or the page might have been deleted.";
        \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
        continue;
    }

    $previousRevisions = $previousRevisionsRequest['query']['pages'][0]['revisions'];
    if ( \count( $previousRevisions ) === 2 ) {
        // [1] is the second revision : the first one [0] is the first bad revision.
        $goodRevision = $previousRevisions[1];
    }
    
    // Then let's get a list of all other users who have edited the page since 
    // the first bad revision (included).
    $badRevisionsFormParams = [
        'action' => 'query',
        'prop' => 'revisions',
        'rvprop' => 'ids|timestamp|user',
        'rvstart' => $firstBadRevision['timestamp'],
        'rvdir' => 'newer', //from the last rev
        'pageids' => $pageId,
        'rvlimit' => 'max'
    ];
    $continueBadRevisionsFormParams = [];

    // Keep fetching revs from the API until there are no more to fetch.
    do {
        $badRevisionsRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => $badRevisionsFormParams + $continueBadRevisionsFormParams + $format
        ] )->getBody(), true );
        if ( !isset( $badRevisionsRequest['query']['pages'][0]['revisions'] ) ) {
            // A race condition likely occurred, with either the page or the revs
            // being probably deleted. Skipping to the next page.
            $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} since {$config['starttimestamp']}";
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
        foreach ( $badRevisionsRequest['query']['pages'][0]['revisions'] as $badRevisionId => $badRevision ) {
            if ( $badRevision['user'] !== $config['offender'] ) {
                if ( !\array_key_exists( $badRevision['user'], $otherUsers ) ) {
                    $otherUsers[ $badRevision['user'] ] = $badRevision;
                }
            }
        }
        // When there are no more edits to fetch, no need to fetch more edits
        if ( !isset( $badRevisionsRequest['continue']['rvcontinue'] ) ) {
            break;
        }
    } while ( $continueBadRevisionsFormParams = [ 'rvcontinue' => $badRevisions['continue']['rvcontinue'] ] );

    if ( empty( $goodRevision ) ) {
        // If we couldn't find a revision before the first bad one,
        // the offender created the page while offending.
        // If he is the only editor, or if the bot is in nuke mode,
        // the page is to be deleted.
        if ( empty( $otherUsers ) ) {
            // If there is no revision before the first bad one,
            // and no other user edited the page since then,
            // then the offender created that page while offending,
            // and that page should be deleted.
            $messageForDeletion = "* [[{$page['title']}]] : {$config['offender']} created it and is the [[Special:History/{$page['title']}|only editor]] since {$config['starttimestamp']}";
            \file_put_contents( __DIR__ . '/rollbotpagesfordeletion', $messageForDeletion . "\n", \FILE_APPEND );
            continue;
        }
        // If other users edited the page since the offender created it,
        // they should be listed in the message.
        $otherUsersMessage = "* [[{$page['title']}]] : [[Special:Diff/{$firstBadRevision['id']}|created {$firstbadRevision['timestamp']}]]] by {$config['offender']}, ";
        $otherUsersMessage.= 'then edited by [[Special:Contributions/' . \array_keys( $otherUsers )[0]['user'] . '|]] at ' . \array_values( $otherUsers )[0]['timestamp'];
        if ( \count( $otherUsers ) > 1 ) {
            $otherUsersMessage .= ', and by ' . \implode( ', ',
                \array_map(
                    function( $x ) {
                        return "[[Special:Contributions/{$x['user']}]]";
                    },
                    \array_slice( \array_keys( $otherUsers ), 1, 5 )
                )
            );
            if ( \count( $otherUsers ) > 6 ) {
                $otherUsersMessage .= ', and ' . ( \count( $otherUsers ) - 6 ) . ' other(s)';
            }
        }
        if ( !empty( $config['nuke'] ) ) {
            // If the bot is set to nuke, the page is to be deleted.
            // Otherwise, to be checked by humans.
            \file_put_contents( __DIR__ . '/rollbotpagesfordeletion', $otherUsersMessage . "\n", \FILE_APPEND );
            continue;
        }
        \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $otherUsersMessage . "\n", \FILE_APPEND );
        continue;
    }

    if ( !empty( $otherUsers ) ) {
        // If the offender didn't create the page while offending, and if
        // other users edited that page since the offender started editing it,
        // we let humans decide what should be done if the bot isn't set to nuke.
        // If it is set to nuke, we delegate the reverting to the normal editing
        // process, and log the list of other users so they can be warned
        $otherUsersMessage = "* [[{$page['title']}]] ([[Special:Diff/{$firstBadRevision['id']}|edited {$firstbadRevision['timestamp']}]]] by {$config['offender']}, ";
        $otherUsersMessage.= 'then by [[Special:Contributions/' . \array_keys( $otherUsers )[0]['user'] . '|]] at ' . \array_values( $otherUsers )[0]['timestamp'];
        if ( \count( $otherUsers ) > 1 ) {
            $otherUsersMessage .= ', and by ' . \implode( ', ',
                \array_map(
                    function( $x ) {
                        return "[[Special:Contributions/{$x['user']}]]";
                    },
                    \array_slice( \array_keys( $otherUsers ), 1, 5 )
                )
            );
            if ( \count( $otherUsers ) > 6 ) {
                $otherUsersMessage .= ' and ' . ( \count( $otherUsers ) - 6 ) . ' other(s)';
            }
        }
        if ( empty( $config['nuke'] ) ) {
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $otherUsersMessage . "\n", \FILE_APPEND );
            continue;
        }
    }

    // Now for the actual editing.
    // Setting the edit summary
    if ( ( !empty( $config['nuke'] ) ) && ( !empty( $otherUsers ) ) ) {
        $editSummary = "Overwriting all revs since the first edit by [[Special:Contributions/{$config['offender']}|]] after {$config['starttimestamp']}, to [[Special:Permalink/{$goodRevision['revid']}]] per {$config['botrequesturl']}";
    }
    else {
        $editSummary = "Rv edits by [[Special:Contributions/{$config['offender']}|]] since {$config['starttimestamp']}, to [[Special:Permalink/{$goodRevision['revid']}|]] by {$goodRevision['user']}, per [[Special:Diff/{$config['botrequestrevid']}]]";
    }

    // Finally, editing and checking for success.
    if ( ( isset( $localNamespaces [ $page['ns'] ]['defaultcontentmodel'] ) ) && ( $localNamespaces [ $page['ns'] ]['defaultcontentmodel'] === 'wikibase-item' ) ) {
        // In the event we're editing a Wikibase entity on a Wikibase wiki (NS0)
        $edit = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => [
                'action' => 'wbeditentity',
                'id' => $page['title'],
                'clear' => '',
                'data' => $goodRevision['content'],
                'summary' => $editSummary,
                'nocreate' => 1,
                'bot' => 1,
                'watchlist' => 'nochange',
                'token' => $editToken,
            ] + $format//includes assert=user
        ] )->getBody(), true );
        if ( !empty( $edit['success'] ) ) {
            $editLogMessage = "* [[{$page['title']}]] : [[Special:Diff/{$edit['entity']['lastrevid']}|reverting]] to [[Special:Permalink/{$goodRevision['revid']}|rev {$goodRevision['revid']}]] ({$goodRevision['timestamp']}) by {$goodRevision['user']}";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n*" . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollboteditlog', $editLogMessage . "\n", \FILE_APPEND );
        }
        else {
            echo "Difficulty editing [[{$page['title']}]], skipping...\n";
            $messageForCheck = "* [[{$page['title']}]] : could not edit the page. It likely has been edit-blocked or deleted.";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n*" . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
    }
    else {
        // Editing any other page than a Wikibase entity
        $edit = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => [
                'action' => 'edit',
                'title' => $page['title'],
                'summary' => $editSummary,
                'nocreate' => 1,
                'text' => $goodRevision['content'],
                'bot' => 1,
                'watchlist' => 'nochange',
                'token' => $editToken
            ] + $format //includes assert=user
        ] )->getBody(), true );

        if ( ( isset( $edit['edit']['result'] ) ) && ( $edit['edit']['result'] === 'Success' ) ) {
            $editLogMessage = "* [[{$page['title']}]] : [[Special:Diff/{$edit['entity']['lastrevid']}|reverting]] to [[Special:Permalink/{$goodRevision['revid']}|rev {$goodRevision['revid']}]] ({$goodRevision['timestamp']}) by {$goodRevision['user']}";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n*" . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollboteditlog', $editLogMessage . "\n", \FILE_APPEND );
        }
        else {
            echo "Difficulty editing [[{$page['title']}]], skipping...\n";
            $messageForCheck = "* [[{$page['title']}]] : could not edit the page. It likely has been edit-blocked or deleted.";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n*" . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
    }
}
/*************************
*     Posting reports    *
*************************/
$reporting = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => [
        'action' => 'edit',
        'title' => "User:{$config['lg']['lgname']}/Reports/{$config['offender']}/{$config['starttimestamp']}",
        'text' => $reportContent = "[{$config['botrequesturl']} Request]\n" . \file_get_contents( __DIR__ . '/rollboteditlog' ) . \file_get_contents( __DIR__ . '/rollbotpagesfordeletion' ) . \file_get_contents( __DIR__ . '/rollbotpagesforcheck' ),
        'watchlist' => 'nochange',
        'bot' => 1,
        'token' => $editToken,
    ] + $format //includes assert=user
] )->getBody(), true );

if ( ( isset( $reporting['edit']['result'] ) ) && ( $reporting['edit']['result'] === 'Success' ) ) {
    echo "Report posted successfully : {$config['wiki']}/wiki/User:{$config['lg']['lgname']}/Reports/{$config['offender']}/{$config['starttimestamp']}\nEntire process successful, exiting...\n";
    return;
}
else {
    echo "Difficulty posting my report {$config['wiki']}/wiki/User:{$config['lg']['lgname']}/Reports/{$config['offender']}/{$config['starttimestamp']} , exiting...\n";
    \file_put_contents( __DIR__ . '/rollbotreport' , \json_encode( $reporting, \JSON_PRETTY_PRINT ) . "\n" . $reportContent );
    return;
}