# WordPress Plugin

WordPress plugin for [Invoice Ninja](https://github.com/invoiceninja/invoiceninja)

## Features
* Import products from Invoice Ninja as pages in WordPress
* Export users from WordPress as clients in Invoice Ninja
* Single sign-on (SSO) for the Client Portal
* Shopping cart
* Custom widget

<p align="center">
    <img src="https://github.com/invoiceninja/wordpress/blob/main/assets/images/screenshot.png?raw=true" alt="Screenshot"/>
</p>

## Settings

### Credentials
- Token: An Invoice Ninja v5 API token.
- URL: The URL to access the app.

### Clients
- Sync Clients: New users created in WordPress and existing users which are updated will be automatically exported to Invoice Ninja.
- If Match Is Found: When the user is exported if a matching client is found in Invoice Ninja it can either be skipped (default) or updated.
- Included Roles: Only users who have the roles included in this list will be exported to Invoice Ninja. 

### Products
- Sync Products: Products in Invoice Ninja will be automatically imported hourly to create custom pages in WordPress.
- Online Purchases: When set to 'Single' your customers will see a 'Buy Now' button to purchase an item, when set to 'Multiple' your customers will see an 'Add to Cart' button which will add the item to their cart. 

### Localization
- Product Label: Singular label to use for individual products.
- Products Label: Plural label to use for multiple products.

> [!NOTE]  
> Additional fields will be shown if Online Purchases and/or Inventory Tracking are enabled.

### Templates
- Product Template:
- Image Template:

### Custom CSS
- Product Page:
- Products Page:
    

## Credits
* [Hillel Coren](https://hillel.dev)
* [Oliver Flueckiger](https://www.oliver-flueckiger.ch)
