# WordPress Plugin

WordPress plugin for [Invoice Ninja](https://github.com/invoiceninja/invoiceninja)

## Features
* Import products from Invoice Ninja as custom pages in WordPress.
* Export WordPress users as clients in Invoice Ninja.
* Enable Single sign-on (SSO) for the Client Portal.
* Integrated shopping cart functionality.
* Add a custom widget to your WordPress site.

<p align="center">
    <img src="https://github.com/invoiceninja/wordpress/blob/main/assets/images/screenshot.png?raw=true" alt="Screenshot"/>
</p>

## Settings

### Credentials
- **Token**: Enter your Invoice Ninja v5 API token here to authenticate and connect your WordPress site with Invoice Ninja. Using https ensures secure data transfer between the two platforms.
- **URL**: Provide the URL where your Invoice Ninja instance is accessible. If the URL is blank the plugin will connect to the hosted Invoice Ninja platform at invoicing.co.

### Clients
- **Sync Clients**: Enable this option to automatically export WordPress users as clients in Invoice Ninja when they are created or updated. This ensures your client database is always up to date without manual intervention.
- **If Match Is Found**: Specify whether to skip or update a client if a matching client is found in Invoice Ninja during the export process. The default setting is to skip, but you can choose to update existing client information if needed.
- **Included Roles**: Define which WordPress user roles should be included in the export process. Only users with the specified roles will be exported to Invoice Ninja, allowing for precise control over your client synchronization.

### Products
- **Sync Products**: Enable this feature to automatically import products from Invoice Ninja into WordPress on an hourly basis. These products will be created as custom pages in WordPress, providing an up-to-date product catalog on your site.
- **Online Purchases**: Configure how your customers can purchase products. Set to 'Single' for a 'Buy Now' button for immediate purchase or 'Multiple' for an 'Add to Cart' button, allowing customers to add items to their cart for later checkout.

### Localization
- **Product Label**: Singular label to use for individual products.
- **Products Label**: Customize the plural label used for multiple products. This label will appear wherever multiple products are listed.

> [!NOTE]  
> Note: Additional fields will be displayed if Online Purchases and/or Inventory Tracking are enabled, providing more detailed configuration options.

### Templates
- **Product Template**: Define the HTML template used to generate the product list page. 
- **Image Template**: If your products in Invoice Ninja have an Image URL set, the images will be imported and displayed alongside the product information in WordPress.

### Custom CSS
- **Product Page**: Add custom CSS to style individual product pages. This gives you the flexibility to match the product pages with your site’s overall design.
- **Products Page**: Add custom CSS to style the product listing page, ensuring a cohesive look across all product-related content on your site.
    
## Credits
* [Hillel Coren](https://hillel.dev)
* [Oliver Flueckiger](https://www.oliver-flueckiger.ch)
