/* HTML Pages → Detect Forms (Forms Converter Add-On for HTML Page Publisher). */
( function () {
	// Show the conversion notes for the form plugin picked in "Convert to".
	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target.matches( '.ffhpp-target' ) ) {
			return;
		}
		var form = event.target.closest( '.ffhpp-form' );
		form.querySelectorAll( '.ffhpp-notes' ).forEach( function ( notes ) {
			notes.hidden = notes.dataset.target !== event.target.value;
		} );
	} );

	// Confirm before converting a form that posts to an external service.
	document.addEventListener( 'submit', function ( event ) {
		var message = event.target.matches( '.ffhpp-convert' ) ? event.target.dataset.confirm : '';
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
}() );
