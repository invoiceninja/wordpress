<div class="wrap">
    <h1>Invoice Ninja</h1>
    <?php settings_errors(); ?>

    <form action="options.php" method="post">

    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-1">Configuration</a></li>
        <li><a href="#tab-2">Localization</a></li>
        <li><a href="#tab-3">Templates</a></li>
        <li><a href="#tab-4">Custom CSS</a></li>
    </ul>

    <div class="tab-content">
        <div id="tab-1" class="tab-pane active">
            <?php settings_fields( 'invoiceninja_configuration' ); ?>
            <?php do_settings_sections( 'invoiceninja_configuration' ); ?>
        </div>
        <div id="tab-2" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja' ); ?>
        </div>            
        <div id="tab-3" class="tab-pane">
        </div>            
        <div id="tab-4" class="tab-pane">
        </div>            
    </div>


    <?php submit_button(); ?>    

    </form>
</dvi>