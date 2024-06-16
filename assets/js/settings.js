window.addEventListener( 'load', function() {

    var tabs = this.document.querySelectorAll( 'ul.nav-tabs > li' );

    for (i=0; i<tabs.length; i++) {
        tabs[i].addEventListener( 'click', switchTab );
    }

    function switchTab( event ) {
        event.preventDefault();

        var clickedTab = event.currentTarget;        
        var anchor = event.target;
        var activePaneId = anchor.getAttribute( 'href' );

        if (!clickedTab || !activePaneId) {
            return;
        }

        var active = document.querySelector( 'ul.nav-tabs li.active' );
        if (active) {
            active.classList.remove( 'active' );
        }

        var active = document.querySelector( '.tab-pane.active' );
        if (active) {
            active.classList.remove( 'active' );
        }

        var activePane = document.querySelector( activePaneId );
        activePane.classList.add( 'active' );
        clickedTab.classList.add( 'active' );
    }
});