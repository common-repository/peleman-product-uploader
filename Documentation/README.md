# Peleman Product Uploader

Plugin that exposes a number of API endpoints to allow Fly2Data to upload all objects necessary for a webshop.
It is built using an OOP WordPress plugin skeleton ([Github repo](https://github.com/Peleman-NV/wordpress_oop_plugin_skeleton))

## Setup

### Installation

-   place this plugin in the WordPress plugin folder: wp-content/plugins/ Then, go to the admin panel > Plugins, and activate it. After installation, a menu item is created for the plugin, where additional setup can be done.
-   API keys: go to WooCommerce > Settings > Advanced > REST API > Add Key. Copy these keys (key & secret) to this plugins menu. **If these values are already filled in, this means the WordPress installation was copied from a previous one - this hasn't been a problem in the past and these existing keys can continue to be used**
-   Imaxel keys: you can copy these from previous installations. If required, you can contact Imaxel for new keys.
-   server settings: slow API uploads (or 503 errors, timeouts, etc) can be due to insufficient RAM/CPU's. In the past upgrading this has helped.

## Plugin file structure

-   Admin

    -   css
        -   style.css: styling for the plugins admin settings
    -   js
    -   partials
        -   ppu-menu.php: the plugins admin settings page
    -   PpuAdmin.php: this contains all the plugin functionality. Refer to the file itself and PHPDocBlocks for each function and below for some context.
    -   index.php: a catch-all page in case visitors try to access it directly (the server _should_ prevent this)

-   Documentation: contains documentation
-   Includes
    -   Plugin.php: where you add functions that "hook" into WordPress and WooCommerce functionality, ie. where you add your functionality to WordPress/WooCommerce
    -   PpuLoader.php: registers all actions, filters, and shortcode that are added in Plugin.php. Doesn't need to be changed
    -   PpuI18n.php: for internationalization. Unused in this plugin
    -   PpuActivator.php: script that runs on plugin activation. Not required in this plugin
    -   PpuDeactivator.php: script that runs on plugin deactivation. Not required in this plugin
-   Languages: unused in this plugin
-   Services
    -   ScriptTimerService.php: a file that logs how long scripts run. Used to monitor API upload times
-   vendor: the vendor packages - under normal circumstances, this won't need to be changed
-   composer.json: the list of composer packages used in the plugin
-   composer.lock: auto-generated
-   index.php: a catch-all page in case visitors try to access it directly (the server _should_ prevent this)
-   pelemanProductUploader.php: the entrypoint for the plugin. Everything starts here, with the function call on the last line. All plugin constants should be defined here.
-   uninstall.php: fired when the plugin is uninstalled

## PpuAdmin.php file

For this plugin, all the custom functionality can be found here. The plugin exposes a number of API endpoints (not RESTful) to upload and download items. The items are:

-   tags
-   categories
-   attributes
-   terms
-   images
-   products
-   product variations
-   menus (also, mega menus)

The functions are:

-   the constructor, style/script/admin page/plugin options loading & registering, apiClient instantiation;
-   registering endpoints (`register_rest_route()`)
-   callbacks for the registered endpoints
-   utility classes for the callbacks
