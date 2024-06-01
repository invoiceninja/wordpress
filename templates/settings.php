<div class="wrap">
    <h1>Invoice Ninja</h1>
    <?php settings_errors(); ?>

    <form action="options.php" method="post">
        <?php
            settings_fields( 'invoiceninja' );
            do_settings_sections( 'invoiceninja' );
            submit_button();
        ?>    
    </form>
</dvi>