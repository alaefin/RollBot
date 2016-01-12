<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/RollBotfunctions.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    echo \json_encode( ['error' => 'posterror' ] );
    return;
}

if ( empty( $_POST['diff'] ) ) {
    echo \json_encode( ['error' => 'differror' ] );
    return;
}

$ajaxResponse = [];
$api = new \GuzzleHttp\Client( [ 'base_uri' => 'https://www.wikidata.org/w/api.php', 'headers' => [ 'User-Agent' => 'RollBot v0.1 (human interface), by [[:fr:User:Alphos]]' ] ] );
if ( ( ( $diff = \parse_url( $_POST['diff'] ) ) === null ) || ( empty( $diff['host'] ) ) ) {
    echo \json_encode( ['error' => 'diffurlerror' ] );
    return;
}
$wiki = 'https://' . $diff['host'];
$sitematrix = \json_decode( $api->request( 'POST', '', [ 
    'form_params' => [ 'action' => 'sitematrix', 'format' => 'json' ]
] )->getBody(), true );
\parse_str( $diff['query'], $diffQueryStringArray );
if ( ( ( $sitematrixSearch = \array_search_recursive( $wiki, $sitematrix, true ) ) === false )
        || ( \end( $sitematrixSearch ) !== 'url' ) ) {
    echo \json_encode( ['error' => 'wikierror' ] );
    return;
}

if ( $diffQueryStringArray['diff'] === 'prev' ) {
    $diffQueryStringArray['diff'] = $diffQueryStringArray['oldid'];
}
$diffInfo = \json_decode( $api->request( 'GET', $wiki . '/w/api.php', [
    'query' => [ 
        'action' => 'query',
        'prop' => 'revisions',
        'revids' => $diffQueryStringArray['diff'],
        'rvprop' => 'ids|timestamp|user',
        'format' => 'json',
        'formatversion' => 2
    ]
] )->getBody(), true );
if ( ( ( !isset( $diffInfo['query']['pages'] ) )
        || ( \count( $pages = \array_values( $diffInfo['query']['pages'] ) ) !== 1 ) ) 
        || ( \count( $pages[0]['revisions'] ) !== 1 ) ) {
    echo \json_encode( ['error' => 'noreverror' ] );
    return;
}
$badRevision = $pages[0]['revisions'][0];
$ajaxResponse['starttimestamp'] = $badRevision['timestamp'];
$ajaxResponse['offender'] = $badRevision['user'];
$prefilledNamespace = $pages[0]['ns'];

$namespacesRequest = \json_decode( $api->request( 'POST', $wiki . '/w/api.php',  [
    'form_params' => [
        'action' => 'query',
        'meta' => 'siteinfo',
        'siprop' => 'namespaces|general',
        'format' => 'json',
        'formatversion' => 2
    ]
] )->getBody(), true );
if ( ( !isset( $namespacesRequest['query']['namespaces'] ) ) || ( !\is_array( $namespacesRequest['query']['namespaces'] ) ) ) {
    echo \json_encode( [ 'error' => 'nonamespace' ] );
    return;
}
$namespaces = $namespacesRequest['query']['namespaces'];
foreach( $namespaces as $namespaceId => $namespace ) {
    if  ( empty( $namespace['name'] ) ) {
        if ( empty( $namespace['canonical'] ) ) {
            $namespaces[ $namespaceId ]['name'] = '<Main>';
        }
        else {
            $namespaces[ $namespaceId ]['name'] = $namespace['canonical'];
        }
    }
}
$ajaxResponse['namespaces'] = [ 's' => $prefilledNamespace, 'list' => $namespaces ];
echo \json_encode( $ajaxResponse );
