window.addEventListener( 'load', function() {

    var tabs = this.document.querySelectorAll( 'ul.nav-tabs > li' );

    for (i=0; i<tabs.length; i++) {
        tabs[i].addEventListener( 'click', switchTab );
    }

    function switchTab( event ) {
        event.preventDefault();

        var active = document.querySelector( 'ul.nav-tabs li.active' );
        if (active) {
            active.classList.remove( 'active' );
        }

        var active = document.querySelector( '.tab-pane.active' );
        if (active) {
            active.classList.remove( 'active' );
        }

        var clickedTab = event.currentTarget;        
        if (clickedTab) {
            clickedTab.classList.add( 'active' );
        }

        var anchor = event.target;
        var activePaneId = anchor.getAttribute( 'href' );
        var activePane = document.querySelector( activePaneId );
        if (activePane) {
            activePane.classList.add( 'active' );
        }
    }
});