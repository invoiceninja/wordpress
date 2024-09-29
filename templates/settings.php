<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap">
    <h1>Invoice Ninja</h1>
    <?php settings_errors(); ?>

    <form method="post" action="options.php" autocomplete="off">
    <input autocomplete="new-password" name="hidden" type="text" style="display:none;"/>

    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-credentials">Credentials</a></li>
        <li><a href="#tab-clients">Clients</a></li>
        <li><a href="#tab-products"><?php echo esc_attr( get_option( 'invoiceninja_products_label') ) ?></a></li>
        <li><a href="#tab-localization">Localization</a></li>
        <li><a href="#tab-templates">Templates</a></li>
        <li><a href="#tab-custom-css">Custom CSS</a></li>
        <li><a href="#tab-about">About</a></li>
    </ul>

    <?php settings_fields( 'invoiceninja_settings' ); ?>

    <div class="tab-content">
        <div id="tab-credentials" class="tab-pane active">
            <?php do_settings_sections( 'invoiceninja_credentials' ); ?>
        </div>
        <div id="tab-clients" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_clients' ); ?>
        </div>            
        <div id="tab-products" class="tab-pane">
            <?php do_settings_sections( 'invoiceninja_products' ); ?>
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
                For details about the plugin's options please refer to the <a href="https://github.com/invoiceninja/wordpress/?tab=readme-ov-file#invoice-ninja--wordpress-plugin" target="_blank">GitHub readme</a>.
            </p>
            <p>
                Feel free to <a href="https://github.com/invoiceninja/wordpress/issues" target="_blank">create an issue</a> on GitHub to suggest a feature you'd like to see added.
            <p>
        </div>            
    </div>

    <p class="clearfix">
        <?php submit_button(); ?>        
    </p>
    
    </form>

    <?php if ($profile) { ?>
        <div class="card connection-info">
            <?php if ( $logo_url ) { ?>
                <img src="<?php echo esc_url( $logo_url ) ?>" height="80" style="float: left;padding-right: 16px;"/>
            <?php } ?>
            <h1 class="title" style="padding-top: 0px"><?php echo esc_attr( $settings->name ); ?></h1>
            <?php if ( $website_url ) { ?>
                <a href="<?php echo esc_url( $website_url ) ?>" target="_blank"><?php echo esc_url( $settings->website ); ?></a>
            <?php } ?>
            <div style="padding-top: 8px">
                <?php echo esc_attr( $total_count ) . ' ' . esc_attr( $total_count == 1 ? $product_label : $products_label ); ?>
                <?php if ( $has_page && $total_count > 0 ) { ?>
                    • <a href="/<?php echo esc_attr( strtolower( $products_label ) ); ?>" target="_blank">View Page</a> [<?php echo esc_attr( $statuses[$page->post_status] ); ?>]
                <?php } ?>
            </div>
        </div>

        <form method="post" action="" style="float: left; margin-right: 12px">
            <input type="hidden" name="invoiceninja_action" value="refresh_company">
            <?php wp_nonce_field('invoiceninja_refresh_company', 'invoiceninja_nonce'); ?>
            <input type="submit" class="button button-primary" value="Refresh Company">
        </form>

        <form method="post" action="" style="float: left; margin-right: 12px">
            <input type="hidden" name="invoiceninja_action" value="export_clients">
            <?php wp_nonce_field('invoiceninja_export_clients', 'invoiceninja_nonce'); ?>
            <input type="submit" class="button button-primary" value="Export Clients" 
            <?php
                if ( ! get_option( 'invoiceninja_included_roles' ) ) {
                    echo 'onclick="alert(\'No roles are included on the clients tab\'); return false;"';
                }
            ?>>
        </form>
        
        <form method="post" action="">
            <input type="hidden" name="invoiceninja_action" value="import_products">
            <?php wp_nonce_field('invoiceninja_import_products', 'invoiceninja_nonce'); ?>
            <input type="submit" class="button button-primary" value="Import <?php echo esc_attr( $products_label ); ?>">
        </form>

    <?php } ?>

</dvi>