# Release Notes for Basin

## Unreleased

### Changed
- Guzzle is now listed as a requirement of the plugin, which uses it directly. Craft already installs it, so nothing changes for existing sites.
- The plugin now requires `guzzlehttp/psr7` 2.0 or later, which it already needed: with 1.x, every request to Basin failed.

### Fixed
- The honeypot field is now hidden on sites whose Content Security Policy blocks inline styles. It was visible there, so people could fill it in and have their submission filed as spam.
- Fixed a Twig deprecation warning on a submission's page, which would become an error in Twig 4.

## 1.0.0 - 2026-11-21

### Added
- Plugin settings: Basin Account API key (environment variable), linked Basin project (chosen from your projects, or an environment variable that holds one’s ID), menu label, permanent-deletion toggle, cache duration and missing-form tracking.
- The settings screen checks the API key as soon as it’s entered and lists the account’s projects, so a first setup needs only one save. A “Test connection” button checks it again.
- “Basin data” option in Utilities → Clear Caches.
- Forms screen listing every form in the linked Basin project, with spam protection, endpoint URL, search, and inbox and spam counts that link to the form’s submissions.
- Form page with the endpoint URL, settings summary, ready-to-paste template code and an “Open in Basin” link. It warns when Basin’s “Enable AJAX” is on, since visitors without JavaScript would see raw JSON, and links straight to the form’s spam settings in Basin.
- Form Studio: paste a form’s embed code once so templates can show its Form Studio version. Embed codes are checked with Basin before saving, and the error log shows if a saved one stops working.
- Submissions screen for each form, with Inbox, Spam and Trash tabs, search, date range and sorting. Dates are shown in the form’s timezone, and unread submissions are in bold.
- Submission page with every field, attachments, location, page, referrer and browser, plus previous/next links. Opening a submission marks it as read in Basin.
- Mark submissions as read, unread, spam or not spam, move them to the trash and restore them, one at a time or in bulk. Large bulk actions run in the queue.
- Permanently delete submissions from Basin, when deletion is allowed.
- “Send to webhooks again” on a submission’s page, for forms with webhooks in Basin.
- Create forms in the linked Basin project from the Forms screen, so editors don’t need a Basin login.
- Edit a form’s name, timezone, notification emails, redirect and thank-you page, auto-reply and data retention from its page. Only the settings you change are sent to Basin. Recipients are shown as chips, marked as verified or waiting for verification, and adding a Send to address asks you to confirm, since it gets no notifications until it’s verified. Spam protection and webhooks are still set in Basin.
- Delete forms from Basin, after typing the form’s name to confirm.
- “Create forms and change their settings in Basin” and “Delete forms from Basin” permissions.
- Basin’s notification email addresses and webhook URLs are removed from form data before it’s cached, along with the Turnstile secret.
- Attachments download through the Control Panel, so Basin’s public file links are never shown.
- Forms whose submissions come from Form Studio but have no embed code saved are flagged, with a one-click option to use the detected embed code.
- `craft.basin.form(id)` and `craft.basin.forms()` for templates. Lookups never wait more than 3 seconds for Basin and fall back to the last known list of forms.
- `craft.basin.render()` outputs a form’s Form Studio embed, given a Basin ID, a form or a Basin Form field’s value, with no need to check the form exists first. A form that can’t be shown outputs only an HTML comment for visitors, plus a notice in devMode and previews. `form.render()` does the same for a form already in hand.
- `hiddenFields` option for `render()`, to add values from the page (such as the entry) to each Form Studio submission.
- Automatic spam protection: every Basin form on a front-end page gets the honeypot and CAPTCHA (reCAPTCHA v3 or v2, hCaptcha or Turnstile) that Basin requires for it, however the form was added, including hard-coded markup, `{% cache %}` blocks, pages cached by Blitz and forms added by JavaScript. Opt out per form with `data-basin-protect="false"`, or turn it off in the settings.
- `form.honeypot()`, `form.captcha()` and `form.spamProtection()` for placing spam protection yourself.
- Basin Form field type, with settings to allow only forms with a Form Studio embed, or particular forms. A form that’s no longer in the project shows a warning and is kept until an editor changes it.
- Error log, with a badge on its menu item, listing forms the site uses but can’t show: forms that aren’t in the linked project, Form Studio embeds without embed code, and saved embed code that no longer works, with the page each was used on. Entries clear themselves once the problem is fixed, or after 30 days without happening again.
- CSV export of the current submissions view (form, tab, search, dates and sort), with every field as a column, CP links for attachments and, optionally, IP address, browser and referrer. Up to 1,000 submissions download straight away; larger exports are prepared in the background and can be downloaded once, by the person who asked for them, for 24 hours. An export holds up to 50,000 submissions, the newest first, and says so if there were more.
- Basin Submissions dashboard widget showing unread and inbox counts and the latest submissions, for one form or the whole project.
- reCAPTCHA version per form, set on the form’s page, or in templates with `data-basin-recaptcha="v2"` on the `<form>` or `form.captcha({ version: 'v2' })`. Forms using v2 and v3 can share a page.
- “Set up forms on this site” permission for adding Form Studio embed codes and choosing a form’s reCAPTCHA version. These are saved in the database, not project config, so they can be changed in production. Settings for a form that has been gone from Basin for 30 days are removed.
- “Change in Basin” link next to a form’s spam protection.
- GraphQL: `basinForms` and `basinForm(uuid:)` queries, enabled per schema with “Query Basin forms”, and Basin Form fields return the form. Forms expose only what a front end needs: name, endpoint URL, JSON-response and redirect settings, Form Studio script and the spam protection to render.
- Unread count badge on the Basin menu item and Submissions subnav item, which can be turned off in the settings.
- User permissions: “View submissions and submission counts”, “Manage submissions”, “Export submissions” and (when deletion is allowed) “Permanently delete submissions”. Submission counts are hidden from users without “View submissions”.
- If Basin can’t be reached, the Control Panel shows the last known list of forms.
