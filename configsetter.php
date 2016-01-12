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
    
    /************************************************
    * Getting the revid of the first offending diff *
    ************************************************/
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
    
    // Comparing namespaces from the request to namespaces existing on the wiki
    if ( ( !isset( $_POST['namespaces'] ) ) || ( !\count( $_POST['namespaces'] ) ) ) {
        \header( 'Location: configsetter.php?error=nonamespace' );
        return;
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
    
    // The bot request URL doesn't have to be a diff, but it has to be on one of
    // the sites handled by the Wikimedia Foundation
    $botrequesthost = \parse_url( $_POST['botrequesturl'], \PHP_URL_HOST );
    if ( ( !isset( $botrequesthost ) ) || ( \array_search_recursive( 'https://' . $botrequesthost, $sitematrix, true ) === false ) ) {
        \header( 'Location: configsetter.php?error=requesturl');
        return;
    }
    $config['botrequesturl'] = $_POST['botrequesturl'];
    
    // Whether or not to overwrite edits by other users
    if ( !empty( $_POST['nuke'] ) ) {
        $config['nuke'] = true;
    }
    
    // Finally, saving the config
    \file_put_contents( __DIR__ .'/rollbotconfig.json', \json_encode( $config, \JSON_FORCE_OBJECT ) );
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
                <div style="text-align: left; width: -moz-max-content; margin: auto auto 1.5em; border: 1px solid #080; background-color: #8f8; padding: 1em;"><code style="white-space: pre;"><?php echo \json_encode( $config, \JSON_FORCE_OBJECT | \JSON_PRETTY_PRINT ); ?></code>
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
