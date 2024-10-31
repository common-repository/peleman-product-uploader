<?php
$wcKey = get_option('ppu-wc-key');
$wcSecret = get_option('ppu-wc-secret');
$pelemanAuth = get_option('peleman-authorization-key')

?>

<div class="ppu-settings">
    <h1>Peleman Product Uploader</h1>
    <hr>
    <p>This plugin uses the <a href="http://woocommerce.github.io/woocommerce-rest-api-docs/">WooCommerce REST API</a> and has the following requirements:</p>
    <ul>
        <li>WooCommerce 3.5+</li>
        <li>WordPress 4.4+</li>
        <li>Pretty permalinks in <strong>Settings > Permalinks</strong> so that the custom endpoints are supported. Default permalinks will not work.</li>
        <li>When calling this plugin via API, you need to generate Woocommerce read/write API keys here: <strong>WooCommerce > Settings > Advanced > REST API > Add Key</strong>.</li>
    </ul>
    <p>The base URL for the API endpoints is: <strong><?php echo get_site_url(); ?>/wp-json/ppu/v1/</strong>.
    </p>
    <hr>
    <h2>Plugins keys</h2>
    <form method="POST" action="options.php">
        <?php
        settings_fields('ppu_custom_settings');
        do_settings_sections('ppu_custom_settings');
        ?>
        <h3>
            Required for the plugin to internally call the WooCommerce REST API
        </h3>
        <div class="form-row">
            <div class="grid-medium-column">
                <label for="ppu-wc-key">WooCommerce key</label>
            </div>
            <div class="grid-large-column">
                <input type="text" id="ppu-wc-key" name="ppu-wc-key" value="<?php _e($wcKey); ?>" placeholder="WooCommerce key">
            </div>
        </div>
        <div class="form-row">
            <div class="grid-medium-column">
                <label for="ppu-wc-secret">WooCommerce secret</label>
            </div>
            <div class="grid-large-column">
                <input type="text" id="ppu-wc-secret" name="ppu-wc-secret" value="<?php _e($wcSecret); ?>" placeholder="WooCommerce secret">
            </div>
        </div>
        <h3>
            Add a "Peleman-Auth" HTTP header with the following value to all API calls
        </h3>
        <div class="form-row">
            <div class="grid-medium-column">
                <label for="peleman-authorization-key">REST API authentication</label>
            </div>
            <div class="grid-large-column-with-button">
                <input class="inline" type="text" id="peleman-authorization-key" name="peleman-authorization-key" value="<?php _e($pelemanAuth); ?>" placeholder="Authorization key">
                <div class="inline">
                    <button id="generate-peleman-auth-key" type="button" class="ppu-button ppu-button-secondary inline">Generate</button>
                </div>
            </div>
        </div>
        <button type="submit" class="button button-primary">Save changes</button>
    </form>
</div>