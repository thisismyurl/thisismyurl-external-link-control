/**
 * External Link Control - Click Tracking
 * 
 * @package TIMU_ELC
 * @since 1.6113
 */

(function() {
    'use strict';

    /**
     * Track external link clicks on page.
     */
    document.addEventListener( 'click', function( event ) {
        const link = event.target.closest( 'a' );
        
        if ( ! link ) {
            return;
        }

        const href = link.getAttribute( 'href' );
        
        if ( ! href || ! isExternalUrl( href ) ) {
            return;
        }

        // The actual tracking is done server-side via redirect
        // This file is here for potential future client-side enhancements
    } );

    /**
     * Check if URL is external based on site domain.
     *
     * @param {string} url URL to check.
     * @return {boolean} True if external, false otherwise.
     */
    function isExternalUrl( url ) {
        if ( url.indexOf( '://' ) === -1 ) {
            return false;
        }

        const siteUrl = window.location.origin;
        return url.indexOf( siteUrl ) === -1 && ( url.indexOf( 'http' ) === 0 || url.indexOf( 'https' ) === 0 );
    }
})();
