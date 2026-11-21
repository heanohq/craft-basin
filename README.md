# Basin for Craft

Your [Basin](https://usebasin.com) forms, inside Craft.

Let Basin do the hard work of forms: blocking spam, sending notifications and storing uploads. Submissions go straight to Basin, so there's no mail setup and no personal data in your Craft database. You still read, manage and export every submission in the Control Panel, and each form gets the spam protection it needs automatically.

## Features

- **Submissions in the Control Panel.** Inbox, spam and trash for every form, with search, dates and bulk actions. Mark submissions as read or spam, move them to the trash, or send them to the form's webhooks again, without leaving Craft.
- **Create and edit forms.** Create forms in your Basin project, change their notifications, redirect, auto-reply and retention, and delete them, from the Control Panel.
- **Spam protection, added for you.** Whatever CAPTCHA and honeypot Basin requires for a form, the page gets it, even in hard-coded markup and cached templates. Works with reCAPTCHA v2 and v3, hCaptcha and Turnstile.
- **A Basin Form field.** Let editors pick a form for an entry. It warns them if a form is no longer in your Basin project.
- **Form Studio forms.** Add a Form Studio form's embed code once, then output it anywhere with one line of Twig.
- **CSV export and a dashboard widget.** Export any view of your submissions, and see unread counts and the latest submissions on the dashboard.
- **Twig and GraphQL.** Look up forms by their Basin ID in templates, or query them for a headless front end.
- **An error log.** If a form is deleted or moved out of your project, it simply isn't shown on the site, and the Control Panel tells you where it was used.
- **Permissions.** Choose who can see submissions, manage them, export them, and create, edit or delete forms. Permanently deleting submissions is off unless you turn it on.

## Your markup, Basin's endpoint

Write the form however you like:

```twig
{% set form = craft.basin.form('demo0contact') %}
{% if form %}
    <form action="{{ form.action }}" method="post">
        <input type="email" name="email" required>
        <textarea name="message"></textarea>
        <button>Send</button>
    </form>
{% endif %}
```

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A [Basin](https://usebasin.com) account on the **Growth plan or higher**, which Basin needs for Account API keys

## Installation

Install it from the Plugin Store in your Control Panel, or with Composer:

```bash
composer require heano/craft-basin
php craft plugin/install basin
```

## Setup

1. In Basin, go to **Account → API** and create an **Account API key**. Account API keys need Basin's [Growth plan or higher](https://usebasin.com/pricing).
2. Add it to your `.env` file:
   ```bash
   BASIN_API_KEY="your-account-api-key"
   ```
3. In Craft, go to **Basin → Settings**, enter `$BASIN_API_KEY` and click **Test connection**.
4. Save, then choose the Basin project whose forms this site uses.

Every form in that project is now available to the site. Each environment can link its own project, such as a staging one.

## Documentation

Everything else, from spam protection to GraphQL, is in the [documentation](https://heano.io/craft-plugins/basin/docs/).

## Support

Found a bug or have a question? [Open an issue](https://github.com/heanohq/craft-basin/issues).

Basin for Craft is made by [heano](https://heano.io). It isn't made by Basin.
