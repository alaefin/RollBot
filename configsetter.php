<?php
\header( 'Content-type: text/html; charset=utf-8' );
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/RollBotfunctions.php';

// Config update only occurs if the request was POSTed
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $oldconfig = \json_decode( \file_get_contents( __DIR__ . '/rollbotconfig.json' ), true );
    $config = [ 'lg' => $oldconfig['lg'] ];
    
    // Attempting to parse the url of the first offending diff
    $diff = \parse_url( $_POST['diff'] );
    if ( $diff === false ) {
        \header( 'Location: configsetter.php?error=nolink' );
        return;
    }
    
    // Checking if the wiki is operated by the Wikimedia Foundation.
    // This should obviously be removed for other wikis.
    $api = new \GuzzleHttp\Client( [ 'base_uri' => 'https://www.wikidata.org/w/api.php', 'headers' => [ 'User-Agent' => 'RollBot v0.1 (human interface), by [[:fr:User:Alphos]]' ] ] );
    $sitematrix = \json_decode( $api->request( 'POST', '', [ 
        'form_params' => [ 'action' => 'sitematrix', 'format' => 'json', 'formatversion' => 2 ]
    ] )->getBody(), true );
    if ( ( empty( $diff['host'] ) ) 
            || ( ( $sitematrixSearch = \array_search_recursive( $config['wiki'] = 'https://' . $diff['host'], $sitematrix, true ) ) === false )
            || ( \end( $sitematrixSearch ) !== 'url' ) ) {
        \header( 'Location: configsetter.php?error=wiki' );
        return;
    }
    
    /***********************************************
    * Getting the info of the first offending diff *
    ***********************************************/
    // If the diff has no query string, no way to get a revid
    if ( empty( $diff['query'] ) ) {
        \header( 'Location: configsetter.php?error=nodiff' );
        return;
    }
    
    // If the query has no 'diff' or 'oldid' element, no way to get a revid
    \parse_str( $diff['query'], $diffQueryStringArray );
    if ( ( !isset( $diffQueryStringArray['diff'] ) ) && ( !isset($diffQueryStringArray['oldid'] ) ) ) {
        \header( 'Location: configsetter.php?error=nodiff' );
        return;
    }
    if ( ( $diffQueryStringArray['diff'] === 'prev' ) || ( empty( $diffQueryStringArray['diff'] ) ) ) {
        if ( ( empty( $diffQueryStringArray['oldid'] ) ) || ( !\ctype_digit( $diffQueryStringArray['oldid'] ) ) ) {
            \header( 'Location: configsetter.php?error=nodiff' );
            return;
        }
        $diffQueryStringArray['diff'] = $diffQueryStringArray['oldid'];
    }
    
    // Getting user (offender) and starttimestamp
    $diffInfo = \json_decode( $api->request( 'GET', $config['wiki'] . '/w/api.php', [
        'query' => [ 
            'action' => 'query',
            'prop' => 'revisions',
            'revids' => $diffQueryStringArray['diff'],
            'rvprop' => 'timestamp|user',
            'format' => 'json',
            'formatversion' => 2
        ]
    ] )->getBody(), true );
    if ( ( ( !isset( $diffInfo['query']['pages'] ) )
            || ( \count( $pages = \array_values( $diffInfo['query']['pages'] ) ) !== 1 ) )
            || ( \count( $pages[0]['revisions'] ) !== 1 ) ) {
        \header( 'Location: configsetter.php?error=norev' );
        return;
    }
    $badRevision = $pages[0]['revisions'][0];
    $config['starttimestamp'] = $badRevision['timestamp'];
    $config['offender'] = $badRevision['user'];
    $prefilledNamespace = $pages[0]['ns'];
    
    /*****************************************************************
    * Getting the timestamp of the last offending diff, if it exists *
    *****************************************************************/
    // If the diff has no query string, no way to get a revid
    if ( !empty( $_POST['enddiff'] ) ) {
        $endDiff = \parse_url( $_POST['enddiff'] );
        if ( $endDiff === null ) {
            \header( 'Location: configsetter.php?error=noenddiff');
            return;
        }
        if ( empty( $endDiff['query'] ) ) {
            \header( 'Location: configsetter.php?error=noenddiff' );
            return;
        }
        
        // If the query has no 'diff' or 'oldid' element, no way to get a revid
        \parse_str( $endDiff['query'], $endDiffQueryStringArray );
        if ( ( !isset( $endDiffQueryStringArray['diff'] ) ) && ( !isset( $endDiffQueryStringArray['oldid'] ) ) ) {
            \header( 'Location: configsetter.php?error=noenddiff' );
            return;
        }
        if ( ( $endDiffQueryStringArray['diff'] === 'prev' ) || ( empty( $endDiffQueryStringArray['diff'] ) ) ) {
            if ( ( empty( $endDiffQueryStringArray['oldid'] ) ) || ( !\ctype_digit( $endDiffQueryStringArray['oldid'] ) ) ) {
                \header( 'Location: configsetter.php?error=nodiff' );
                return;
            }
            $endDiffQueryStringArray['diff'] = $endDiffQueryStringArray['oldid'];
        }
        
        // Getting endtimestamp
        $endDiffInfo = \json_decode( $api->request( 'GET', $config['wiki'] . '/w/api.php', [
            'query' => [
                'action' => 'query',
                'prop' => 'revisions',
                'revids' => $endDiffQueryStringArray['diff'],
                'rvprop' => 'timestamp',
                'format' => 'json',
                'formatversion' => 2
            ]
        ] )->getBody(), true );
        if ( ( ( !isset( $endDiffInfo['query']['pages'] ) )
                || ( \count( $endpages = \array_values( $endDiffInfo['query']['pages'] ) ) !== 1 ) )
                || ( \count( $endpages[0]['revisions'] ) !== 1 ) ) {
            \header( 'Location: configsetter.php?error=noendrev' );
            return;
        }
        if ( date_create_from_format( 'Y-m-d\\TH:i:sT', $endpages[0]['revisions'][0]['timestamp'] )->format( 'U' ) < date_create_from_format( 'Y-m-d\\TH:i:sT', $config['starttimestamp'] )->format( 'U' ) ) {
            \header( 'Location: configsetter.php?error=enddiffbeforestartdiff' );
            return;
        }
        $config['endtimestamp'] = $endpages[0]['revisions'][0]['timestamp'];
    }
    
    // Comparing namespaces from the request to namespaces existing on the wiki
    if ( ( !isset( $_POST['namespaces'] ) ) || ( !\count( $_POST['namespaces'] ) ) ) {
        \header( 'Location: configsetter.php?error=nonamespace' );
        return;
    }
    foreach ( $_POST['namespaces'] as $key => $value ) {
        if ( $value < 0 ) {
            unset( $_POST['namespaces'][ $key ] );
        }
    }
    $config['namespaces'] = \array_keys( $_POST['namespaces'] );
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
        \header( 'Location: configsetter.php?error=nonamespacesonwiki');
        return;
    }
    $localNamespaces = $localNamespacesRequest['query']['namespaces'];
    if ( \array_diff( $config['namespaces'], \array_keys( $localNamespaces ) ) ) {
        \header( 'Location: configsetter.php?error=wrongnamespace' );
        return;
    }
    
    // The bot request URL should be a diff on one of the sites handled
    // by the Wikimedia Foundation
    $botRequestArray = \parse_url( $_POST['botrequesturl'] );
    if ( ( empty( $botRequestArray['host'] ) ) || ( \array_search_recursive( 'https://' . $botRequestArray['host'], $sitematrix, true ) === false ) ) {
        \header( 'Location: configsetter.php?error=requesturl');
        return;
    }
    if ( !empty( $botRequestArray['query'] ) ) {
        \parse_str( $botRequestArray['query'], $botRequestQuery );
        // If the query has no 'diff' or 'oldid' element, no way to get a revid
        if ( isset( $botRequestQuery['diff'] ) ) {
            if ( ( $botRequestQuery['diff'] === 'prev' ) && ( ( !empty( $botRequestQuery['oldid'] ) ) && ( \ctype_digit( $botRequestQuery['oldid'] ) ) ) ) {
                $config['botrequestrevid'] = $botRequestQuery['oldid'];
            }
            elseif ( \ctype_digit( $botRequestQuery['diff'] ) ) {
                $config['botrequestrevid'] = $botRequestQuery['diff'];
            }
            else {
                \header( 'Location: configsetter.php?error=requesturl');
                return;
            }
        }
        else {
            \header( 'Location: configsetter.php?error=requesturl');
            return;
        }
    }
    
    // Whether or not to overwrite edits by other users
    if ( !empty( $_POST['nuke'] ) ) {
        $config['nuke'] = true;
    }
    
    // Finally, saving the config
    \file_put_contents( __DIR__ .'/rollbotconfig.json', \json_encode( $config ) );
}

?><!DOCTYPE html>
<html>
    <head>
        <title>
            Configuration setter for a new instance of RollBot
        </title>
        <script src="./vendor/components/jquery/jquery.min.js"></script>
    </head>
    <body>
<?php if ( isset( $config ) ) { 
    // The password shouldn't show on the resulting page
    $config['lg']['lgpassword'] = '****'; ?>
        <div style="border: 1px solid #0f0; background-color: #bfb; margin: 2em 8em; padding: 2em 5em; width: auto; text-align: center;">
                Configuration updated :
                <div style="text-align: left; width: -moz-max-content; margin: auto auto 1.5em; border: 1px solid #080; background-color: #8f8; padding: 1em;"><code style="white-space: pre;"><?php echo \json_encode( $config, \JSON_PRETTY_PRINT ); ?></code>
                </div>
                You can run one of the following commands :
                <ul style="text-align: left; width: -moz-max-content; margin: auto;">
                    <li><code>$ php <?php echo __DIR__ . '/index.php'; ?></code></li>
                    <li><code>$ php <?php echo __DIR__ . '/index.php'; ?> &amp;> <?php echo __DIR__ . '/rollbotsyslog'; ?> &amp;</code></li>
                </ul>
            </div><?php } ?><form method="get" action="./configsetter.php" id="form">
            <label for="diff">Paste a link to the first offending diff : </label><input type="text" id="diff" name="diff" size="100" /> <input type="button" value="Check" />
        </form>
        <script src="./configsetter.js"></script>
    </body>
</html>
