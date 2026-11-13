# Basin for Craft CMS

Use [Basin](https://usebasin.com) forms on your Craft site, and view and manage their submissions from the Control Panel.

> In development. Full documentation will be published at https://heano.io/plugins/basin.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A Basin account with an Account API key

## Installation

```bash
composer require heano/craft-basin
php craft plugin/install basin
```

## Setup

1. In Basin, create an **Account API key**.
2. Add it to your `.env` file:
   ```
   BASIN_API_KEY="your-key"
   ```
3. In Craft, go to **Settings → Plugins → Basin**, enter `$BASIN_API_KEY`, and click **Test connection**.
4. Save, then choose the Basin project whose forms this site should use.
