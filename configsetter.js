function RollBotDiffCheck() {
  $.post( 'configsetter.ajax.php?', { diff: $( '#diff' ).val() },
  function( data ) {
    var starttimestamp, namespaces, i;
    // Purging most of the form to start from scratch
    $( '#form' ).attr( 'method', 'get' );
    $( '#error' ).remove();
    $( '#offenderdiv' ).empty().remove();
    $( '#starttimestampdiv' ).empty().remove();
    $( '#botrequesturldiv' ).empty().remove();
    $( '#nukediv' ).empty().remove();
    $( '#namespaces' ).empty().remove();
    $( '#sendform' ).remove();
    
    // If the data provided was faulty
    if ( data.error ) {
      $( '#diff' ).after( $( '<div id="error" style="width: auto; border: 1px solid #f00; padding: 1em 3em; margin 1em;"></div>' ).text( data.error ) );
      return;
    }
    
    // Recreating most of the form from scratch
    // Text input with the name of the offender
    $( '<div id="offenderdiv"></div>' ).appendTo( $( '#form' ) );
    $( '<label for="offender">Offender : </label>' ).appendTo( $( '#offenderdiv' ) );
    $( '<input type="text" id="offender" name="offender" />' ).val( data.offender ).appendTo( $( '#offenderdiv' ) );
    
    // Text input with the properly formatted starttimestamp
    $( '<div id="starttimestampdiv"></div>' ).appendTo( $( '#form' ) );
    $( '<label for="starttimestamp">Start Timestamp (ISO) : </label>' ).appendTo( $( '#starttimestampdiv' ) );
    $( '<input type="text" id="starttimestamp" name="starttimestamp" />' ).val( data.starttimestamp ).appendTo( $( '#starttimestampdiv' ) );
    
    // Text input with the URL of the bot request
    $( '<div id="botrequesturldiv"></div>' ).appendTo( $( '#form' ) );
    $( '<label for="botrequesturl">URL of the diff of the bot request : </label>' ).appendTo( $( '#botrequesturldiv' ) );
    $( '<input type="text" id="botrequesturl" name="botrequesturl" size="100" />' ).appendTo( $( '#botrequesturldiv' ) );
    
    // Checkbox to decide if we should overwrite other users' edits
    $( '<div id="nukediv"></div>' ).appendTo( $( '#form' ) );
    $( '<label for="nuke">Nuke ? </label>' ).appendTo( $( '#nukediv' ) );
    $( '<input type="checkbox" id="nuke" name="nuke" />' ).appendTo( $( '#nukediv' ) );
    
    // Fieldset with the list of namespaces, each with a checkbox
    if ( data.namespaces ) {
      namespaces = $( '<fieldset id="namespaces"></fieldset>' );
      namespaces.appendTo( $( '#form' ) );
      namespaces.append(
        $( '<input type="button" id="selectallnamespaces" value="Select All" />' ),
        $( '<input type="button" id="unselectallnamespaces" value="Unselect All" />' ),
        $( '<input type="button" id="inverseselectednamespaces" value="Inverse selection" />' ),
        '<br/>'
      );
      for ( i in data.namespaces.list ) {
        // Name of the namespace (or <Main> for namespaces without a name ;
        // usually this should mean the main namespace...)
        // For namespaces with a default content model (including 'wikibase-item'),
        // append it between parentheses.
        $( '<label for="namespaces_' + i + '"></label>' ).text( data.namespaces.list[ i ].name + ( data.namespaces.list[ i ].defaultcontentmodel ? ' (' + data.namespaces.list[ i ].defaultcontentmodel + ')' : '' ) ).appendTo( namespaces );
        // Tick the checkbox for the namespace of the provided (offending) diff
        if ( parseInt( i, 10 ) === parseInt( data.namespaces.s, 10 ) ) {
          $( '<input type="checkbox" id="namespaces_' + i + '" name="namespaces[' + i + ']" value="' + i + '" />').prop( "checked", true ).appendTo( namespaces );
        }
        else {
          $( '<input type="checkbox" id="namespaces_' + i + '" name="namespaces[' + i + ']" value="' + i + '" />').appendTo( namespaces );
        }
        namespaces.append( $( '<br/>' ) );
      }
      $( '#form' ).append( $( '<input type="submit" id="sendform" value="Send form" />' ).css( 'margin', '3em auto') );
      $( '#form' ).attr( 'method', 'post' );
    }
  }, "json");
}

$( function() {
  $( '#form' ).submit( function(e) {
    // Prevent form submission in case it's missing a list
    // of namespaces
    if ( !$( '#form input[id^="namespaces"]:checkbox:checked' ).length ) {
      e.preventDefault();
      if ( $( '#form input[id^="namespaces"]:checkbox' ).length ) {
        $( '#diff' ).change();
      }
    }
  } );
  $( '#diff' ).change( RollBotDiffCheck );
  $( '#form' ).on( 'click', '#selectallnamespaces',
    function() {
      $( '#form input:checkbox' ).each( function( index) { $( this ).prop( 'checked', true ); } );
    }
  );
  $( '#form' ).on( 'click', '#unselectallnamespaces',
    function() {
      $( '#form input:checkbox' ).each( function( index) { $( this ).prop( 'checked', false ); } );
    }
  );
  $( '#form' ).on( 'click', '#inverseselectednamespaces',
    function() {
      $( '#form input:checkbox' ).each( function( index) { $( this ).prop( 'checked', !$( this ).prop( 'checked' ) ); } );
    }
  );
} );
