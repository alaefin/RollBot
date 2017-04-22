<?php
if ( PHP_SAPI !== 'cli' ) {
    echo "This script takes long to process, it should run on the command line, exiting...\n";
    return;
}
$config = \json_decode( \file_get_contents( __DIR__ . '/rollbotconfig.json' ), true );
define( 'ROLLBOT_COUNT_EVERY_X_PAGES', 1 );

echo "Offender : {$config['offender']}
Start timestamp : {$config['starttimestamp']}\n";
if ( !empty( $config['endtimestamp'] ) ) {
    echo "End timestamp : {$config['endtimestamp']}\n";
}
else {
   $config['endtimestamp'] = ( new DateTime( '@' . time(), new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\\TH:i:s\\Z' );
}
echo "Wiki : {$config['wiki']}
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

/*****************************
* Get a list of pages edited *
*****************************/
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
if ( !empty( $config['endtimestamp'] ) ) {
    $baseContribsListOptions['ucend'] = $config['endtimestamp'];
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
    if ( isset( $contribsRequest['continue']['uccontinue'] ) ) {
        $continueContribsListOptions = [ 'uccontinue' => $contribsRequest['continue']['uccontinue'] ];
    }
    else {
        break;
    }
}

echo "\n", $pagesCount = \count( $pagesList );
if ( !empty( $config['endtimestamp'] ) ) {
    " pages edited by {$config['offender']} between {$config['starttimestamp']} and {$config['endtimestamp']}\n\n";
}
else {
    " pages edited by {$config['offender']} since {$config['starttimestamp']}\n\n";
}

/********************************
* Getting things back to normal *
********************************/

// Truncating/creating log pages for the current instance of the bot.
if ( !empty( $config['endtimestamp'] ) ) {
    \file_put_contents( __DIR__ . '/rollbotpagesfordeletion', "\n== Pages for deletion ==\nThe following pages should be flagged for deletion (most likely because they were created by {$config['offender']} between {$config['starttimestamp']} and {$config['endtimestamp']}, and no other user edited them since) :\n" );
    \file_put_contents( __DIR__ . '/rollboteditlog', "\n== Pages successfully reverted ==\nThe following pages were edited by {$config['offender']} between {$config['starttimestamp']} and {$config['endtimestamp']}, they were reverted (by any user, not necessarily RollBot) to a sane version :\n" );
}
else {
    \file_put_contents( __DIR__ . '/rollbotpagesfordeletion', "\n== Pages for deletion ==\nThe following pages should be flagged for deletion (most likely because they were created by {$config['offender']} since {$config['starttimestamp']} and no other user edited them) :\n" );
    \file_put_contents( __DIR__ . '/rollboteditlog', "\n== Pages successfully reverted ==\nThe following pages were edited by {$config['offender']} since {$config['starttimestamp']}, they were reverted (by any user, not necessarily RollBot) to a sane version :\n" );
}
\file_put_contents( __DIR__ . '/rollbotpagesforcheck', "\n== Pages requiring human check ==\nThe following pages should be checked by a human user (most likely because someone else than {$config['offender']} edited them since {$config['starttimestamp']}) :\n" );
if ( \file_exists( __DIR__ . '/rollbotreport' ) ) {
    \unlink( __DIR__ . '/rollbotreport' );
}
if ( \file_exists( __DIR__ . '/rollboterrorlog' ) ) {
    \unlink( __DIR__ . '/rollboterrorlog' );
}
$pageCounter = 0;

// For each page, get the metadata of the first "bad revision" by the offender.
// This will be the base timestamp for that page.
foreach ( $pagesList as $pageId => $page ) {
    $goodRevision = [];
    $badRevisions = [];
    $otherUsers = [];
    $otherUsersMessage = "";

    // Progress report for the human operator
    $pageCounter++;
    if ( $pageCounter % ROLLBOT_COUNT_EVERY_X_PAGES === 0 ) {
        echo \sprintf( '%' . \strlen( $pagesCount ) . 'd', $pageCounter ), ' / ', $pagesCount, "\n";
    }

    // First, let's establish the first edit by the offender on that page since
    // the starttimestamp, optionally before the endtimestamp
    $firstBadRevisionRequestFormParams = [
            'action' => 'query',
            'prop' => 'revisions',
            'rvprop' => 'ids|timestamp',
            'rvstart' => $config['starttimestamp'],
            'rvdir' => 'newer', //from the start timestamp to now
            'pageids' => $pageId,
            'rvlimit' => 1,
            'rvuser' => $config['offender']
    ];
    if ( !empty ($config['endtimestamp'] ) ) {
        $firstBadRevisionRequestFormParams['rvend'] = $config['endtimestamp'];
    }
    $firstBadRevisionRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
        'form_params' => $firstBadRevisionRequestFormParams + $format
    ] )->getBody(), true );
    if ( !isset( $firstBadRevisionRequest['query']['pages'][0]['revisions'][0] ) ) {
        // If no such revision was found, the page or all revisions of interest
        // have been deleted. Skipping to the next page.
        if ( !empty( $config['endtimestamp'] ) ) {
            $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} between {$config['starttimestamp']} and {$config['endtimestamp']}";
        }
        else {
            $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} since {$config['starttimestamp']}";
        }
        \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
        continue;
    }
    // If such a revision was found, it's the one we're looking for.
    $firstBadRevision = $firstBadRevisionRequest['query']['pages'][0]['revisions'][0];

    // Now that we have our first bad revision, getting the previous revision,
    // which is guaranteed sane regardless of user.
    // This involves getting two revisions including the first bad one.
    // This doesn't involve the endtimestamp
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
    // This includes any user who has edited after the endtimestamp, even the offender :
    // for most intents and purposes he is considered another user after the endtimestamp
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
            if ( !empty( $config['endtimestamp'] ) ) {
                $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} between {$config['starttimestamp']} and {$config['endtimestamp']}";
            }
            else {
                $messageForCheck = "* [[{$page['title']}]] : could not find a \"bad revision\" by {$config['offender']} since {$config['starttimestamp']}";
            }
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
        foreach ( $badRevisionsRequest['query']['pages'][0]['revisions'] as $badRevisionId => $badRevision ) {
            // The offender will be considered an different user after endtimestamp
            if ( ( $badRevision['user'] !== $config['offender'] )
                    || ( \date_create_from_format( 'Y-m-d\\TH:i:sT', $badRevision['timestamp'] )->format( 'U' ) > \date_create_from_format( 'Y-m-d\\TH:i:sT', $config['endtimestamp'] )->format( 'U' ) ) ) {
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
        // Other users include the offender after an optional endtimestamp
        $otherUsersMessage = "[[Special:Diff/{$firstBadRevision['revid']}|created {$firstBadRevision['timestamp']}]] by {$config['offender']}, ";
        if ( \array_keys( $otherUsers )[0] === $config['offender'] ) {
            $otherUsersMessage.= 'then edited by [[Special:Contributions/' . \array_keys( $otherUsers )[0] . '|' . \array_keys( $otherUsers )[0] . ']] at ' . \array_values( $otherUsers )[0]['timestamp'] . ' (after ' . $config['endtimestamp'] . ')';
        }
        else {
            $otherUsersMessage.= 'then edited by [[Special:Contributions/' . \array_keys( $otherUsers )[0] . '|' . \array_keys( $otherUsers )[0] . ']] at ' . \array_values( $otherUsers )[0]['timestamp'];
        }
        if ( \count( $otherUsers ) > 1 ) {
            $otherUsersMessage .= ', and by ' . \implode( ', ',
                \array_map(
                    function( $x ) use ( $config ) {
                        if ( $x === $config['offender'] ) {
                            return "[[Special:Contributions/$x|$x]] (after {$config['endtimestamp']})";
                        }
                        return "[[Special:Contributions/$x|$x]]";
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
            \file_put_contents( __DIR__ . '/rollbotpagesfordeletion', "* [[{$page['title']}]] : $otherUsersMessage\n", \FILE_APPEND );
            continue;
        }
        \file_put_contents( __DIR__ . '/rollbotpagesforcheck', "* [[{$page['title']}]] : $otherUsersMessage\n", \FILE_APPEND );
        continue;
    }

    if ( !empty( $otherUsers ) ) {
        // If the offender didn't create the page while offending, and if
        // other users edited that page since the offender started editing it,
        // we let humans decide what should be done if the bot isn't set to nuke.
        // If it is set to nuke, we delegate the reverting to the normal editing
        // process, and log the list of other users so they can be warned
        $otherUsersMessage = "[[Special:Diff/{$firstBadRevision['revid']}|edited {$firstBadRevision['timestamp']}]] by {$config['offender']}, ";
        if ( \array_keys( $otherUsers )[0] === $config['offender'] ) {
            $otherUsersMessage.= 'then by [[Special:Contributions/' . \array_keys( $otherUsers )[0] . '|' . \array_keys( $otherUsers )[0] . ']] at ' . \array_values( $otherUsers )[0]['timestamp'] . ' (after ' . $config['endtimestamp'] . ')';
        }
        else {
            $otherUsersMessage.= 'then by [[Special:Contributions/' . \array_keys( $otherUsers )[0] . '|' . \array_keys( $otherUsers )[0] . ']] at ' . \array_values( $otherUsers )[0]['timestamp'];
        }
        if ( \count( $otherUsers ) > 1 ) {
            $otherUsersMessage .= ', and by ' . \implode( ', ',
                \array_map(
                    function( $x ) use ( $config ) {
                        if ( $x === $config['offender'] ) {
                            return "[[Special:Contributions/$x|$x]] (after {$config['endtimestamp']})";
                        }
                        return "[[Special:Contributions/$x|$x]]";
                    },
                    \array_slice( \array_keys( $otherUsers ), 1, 5 )
                )
            );
            if ( \count( $otherUsers ) > 6 ) {
                $otherUsersMessage .= ' and ' . ( \count( $otherUsers ) - 6 ) . ' other(s)';
            }
        }
        if ( empty( $config['nuke'] ) ) {
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', "* [[{$page['title']}]] : $otherUsersMessage\n", \FILE_APPEND );
            continue;
        }
    }

    // Now for the actual editing.
    // Setting the edit summary
    if ( ( !empty( $config['nuke'] ) ) && ( !empty( $otherUsers ) ) ) {
        $editSummary = "Overwriting all revs since the first edit by [[Special:Contributions/{$config['offender']}]] after {$config['starttimestamp']}, to [[Special:Permalink/{$goodRevision['revid']}]] per [[Special:Diff/{$config['botrequestrevid']}]]";
    }
    else {
        $editSummary = "Rv edits by [[Special:Contributions/{$config['offender']}]] since {$config['starttimestamp']}, to [[Special:Permalink/{$goodRevision['revid']}]] by {$goodRevision['user']}, per [[Special:Diff/{$config['botrequestrevid']}]]";
    }

    // Finally, editing and checking for success.
    if ( ( isset( $localNamespaces [ $page['ns'] ]['defaultcontentmodel'] ) ) && ( $localNamespaces [ $page['ns'] ]['defaultcontentmodel'] === 'wikibase-item' ) ) {
        // In the event we're editing a Wikibase entity on a Wikibase wiki (NS0)
        $edit = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => $form_params = [
                'action' => 'wbeditentity',
                'id' => $page['title'],
                'clear' => '1',
                'data' => $goodRevision['content'],
                'summary' => $editSummary,
                'nocreate' => 1,
                'bot' => 1,
                'watchlist' => 'nochange',
                'token' => $editToken,
            ] + $format//includes assert=user
        ] )->getBody(), true );
        if ( !empty( $edit['success'] ) ) {
            $editLogMessage = "* [[{$page['title']}]] (entity) : [[Special:Diff/{$edit['entity']['lastrevid']}|reverting]] to [[Special:Permalink/{$goodRevision['revid']}|rev {$goodRevision['revid']}]] ({$goodRevision['timestamp']}) by {$goodRevision['user']}";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n** " . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollboteditlog', $editLogMessage . "\n", \FILE_APPEND );
        }
        else {
            echo "Difficulty editing [[{$page['title']}]], skipping...\n";
            $messageForCheck = "* [[{$page['title']}]] : could not edit the entity.";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $messageForCheck .= "\n** " . $otherUsersMessage . "\n**" . $edit['error']['info'];
            }
            \file_put_contents( __DIR__ . '/rollboterrorlog', \json_encode( $form_params, \JSON_PRETTY_PRINT ) . "\n" . \json_encode( $edit, \JSON_PRETTY_PRINT ) . "\n", \FILE_APPEND );
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
    }
    else {
        // Editing any other page than a Wikibase entity
        $edit = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => $form_params = [
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
            $editLogMessage = "* [[{$page['title']}]] : [[Special:Diff/{$edit['edit']['newrevid']}|reverting]] to [[Special:Permalink/{$goodRevision['revid']}|rev {$goodRevision['revid']}]] ({$goodRevision['timestamp']}) by {$goodRevision['user']}";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $editLogMessage .= "\n** " . $otherUsersMessage;
            }
            \file_put_contents( __DIR__ . '/rollboteditlog', $editLogMessage . "\n", \FILE_APPEND );
        }
        else {
            echo "Difficulty editing [[{$page['title']}]], skipping...\n";
            $messageForCheck = "* [[{$page['title']}]] : could not edit the page.";
            if ( ( !empty( $otherUsersMessage ) && !empty( $config['nuke'] ) ) ) {
                $messageForCheck .= "\n** " . $otherUsersMessage . "\n**" . $edit['error']['info'];
            }
            \file_put_contents( __DIR__ . '/rollboterrorlog', \json_encode( $form_params, \JSON_PRETTY_PRINT ) . \json_encode( $edit, \JSON_PRETTY_PRINT ) );
            \file_put_contents( __DIR__ . '/rollbotpagesforcheck', $messageForCheck . "\n", \FILE_APPEND );
            continue;
        }
    }
    // Per https://en.wikipedia.org/wiki/Wikipedia:Bot_policy#Bot_requirements
    // "bots doing more urgent tasks may edit approximately once every five seconds".
    // This will probably mean morea time than 5 seconds (pages for check, etc...)
    // but better safe than sorry
    sleep(10);
}

/*************************
*     Posting reports    *
*************************/
// Establishing the content of the report
// Link to the bot request
$reportContent = "[[Special:Diff/{$config['botrequestrevid']}|Request]]\n\n";
// Start timestamp is shown in the name of the report, but if we show
// the optional end-timestamp, might as well show the start timestamp
if ( !empty( $config['endtimestamp'] ) ) {
    $reportContent .= "Pages edited between {$config['starttimestamp']} and {$config['endtimestamp']}\n\n";
}
else {
    $reportContent .= "Pages edited after {$config['starttimestamp']}\n\n";
}
// List of namespaces edited by the bot
$reportContent .= "Namespaces : ";
$reportContentNamespaces = [];
foreach ( $config['namespaces'] as $namespaceId ) {
    $reportContentNamespaces[] = trim( $localNamespaces[ $namespaceId ]['name'] . ' (' . $namespaceId . ')' );
}
$reportContent .= \implode( ', ', $reportContentNamespaces )."\n\n";
// Whether or not the bot is set to nuke
if ( !empty( $config['nuke'] ) ) {
    $reportContent .= "'''Overwriting''' (and reporting) subsequent edits ''by other users''.\n\n";
}
else {
    $reportContent .= "Not editing (but reporting) pages subsequently edited ''by other users''.\n\n";
}
// List of pages verified by the bot
$reportContent .= \file_get_contents( __DIR__ . '/rollboteditlog' ) . "\n" . \file_get_contents( __DIR__ . '/rollbotpagesfordeletion' ) . "\n" . \file_get_contents( __DIR__ . '/rollbotpagesforcheck' );

// Title for RollBot's report subpage
$reportTitle = "User:{$config['lg']['lgname']}/Reports/{$config['offender']}/{$config['starttimestamp']}";
if ( !empty( $config['endtimestamp'] ) ) {
    $reportTitle .= ' - ' . $config['endtimestamp'];
}

// Publising the report
$reporting = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => $form_params = [
        'action' => 'edit',
        'title' => $reportTitle,
        'watchlist' => 'nochange',
        'bot' => 1,
        'token' => $editToken,
        'text' => $reportContent,
    ] + $format //includes assert=user
] )->getBody(), true );

if ( ( isset( $reporting['edit']['result'] ) ) && ( $reporting['edit']['result'] === 'Success' ) ) {
    echo "Report posted successfully : {$config['wiki']}/wiki/$reportTitle\nEntire process successful, exiting...\n";
    return;
}
else {
    echo "Difficulty posting my report '{$config['wiki']}/wiki/$reportTitle' , exiting...\n";
    \file_put_contents( __DIR__ . '/rollboterrorlog', \json_encode( $form_params, \JSON_PRETTY_PRINT ) . \json_encode( $reporting, \JSON_PRETTY_PRINT ) );
    \file_put_contents( __DIR__ . '/rollbotreport' , \json_encode( $reporting, \JSON_PRETTY_PRINT ) . "\n" . $reportContent );
    return;
}
