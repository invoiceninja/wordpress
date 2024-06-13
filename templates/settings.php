<div class="wrap">
    <h1>Invoice Ninja</h1>
    <?php settings_errors(); ?>

    <form method="post" action="options.php" autocomplete="off">
    <input autocomplete="new-password" name="hidden" type="text" style="display:none;"/>

    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-credentials">Credentials</a></li>
        <li><a href="#tab-options">Options</a></li>
        <li><a href="#tab-localization">Localization</a></li>
        <li><a href="#tab-templates">Templates</a></li>
        <li><a href="#tab-custom-css">Custom CSS</a></li>
        <li><a href="#tab-about">About</a></li>
    </ul>

    <?php settings_fields( 'invoiceninja_settings' ); ?>

    <div class="tab-content">
        <div id="tab-credentials" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_credentials' ); ?>
        </div>
        <div id="tab-options" class="tab-pane active">
            <?php do_settings_sections( 'invoiceninja_options' ); ?>
        </div>            
        <div id="tab-localization" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_localization' ); ?>
        </div>            
        <div id="tab-templates" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_templates' ); ?>
        </div>            
        <div id="tab-custom-css" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_custom_css' ); ?>
        </div>            
        <div id="tab-about" class="tab-pane">
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

        <form method="post" action="" style="float: left; margin-right: 12px">
            <input type="hidden" name="invoiceninja_action" value="refresh_company">
            <?php wp_nonce_field('invoiceninja_refresh_company', 'invoiceninja_nonce'); ?>
            <input type="submit" class="button button-primary" value="Refresh Company">
        </form>

        <form method="post" action="">
            <input type="hidden" name="invoiceninja_action" value="import_products">
            <?php wp_nonce_field('invoiceninja_import_products', 'invoiceninja_nonce'); ?>
            <input type="submit" class="button button-primary" value="Import <?php echo $products_label; ?>">
        </form>

    <?php } ?>

</dvi>