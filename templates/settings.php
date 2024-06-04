<div class="wrap">
    <h1>Invoice Ninja</h1>
    <?php settings_errors(); ?>

    <form method="post" action="options.php" autocomplete="off">
    <input autocomplete="new-password" name="hidden" type="text" style="display:none;"/>

    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-1">Configuration</a></li>
        <li><a href="#tab-2">Localization</a></li>
        <li><a href="#tab-3">Templates</a></li>
        <li><a href="#tab-4">About</a></li>
    </ul>

    <?php settings_fields( 'invoiceninja_settings' ); ?>

    <div class="tab-content">
        <div id="tab-1" class="tab-pane active">
            <?php do_settings_sections( 'invoiceninja_configuration' ); ?>
        </div>
        <div id="tab-2" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_localization' ); ?>
        </div>            
        <div id="tab-3" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_templates' ); ?>
        </div>            
        <div id="tab-4" class="tab-pane">
            <p>
                Thank you for installing the <a href="https://invoiceninja.com" target="_blank">Invoice Ninja</a> plugin for WordPress!
            </p>
            <p>
                Please <a href="https://github.com/invoiceninja/wordpress/issues" target="_blank">create an issue</a> on GitHub to suggest a feature you'd like to see added.
            <p>
        </div>            
    </div>

    <p class="clearfix">
        <?php submit_button(); ?>        
    </p>
    
    </form>

    <?php if ($company) { ?>
        <div class="card connection-info">
            <?php echo $company ?>
        </div>

        <form method="post" action="">
            <input type="hidden" name="my_plugin_action" value="run_code">
            <?php wp_nonce_field('my_plugin_run_code', 'my_plugin_nonce'); ?>
            <input type="submit" name="run_code_button" class="button button-primary" value="Import Products">
        </form>
    <?php } ?>

</dvi>