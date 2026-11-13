# Release Notes for Basin

## Unreleased

### Added
- Plugin settings: Basin Account API key (environment variable), linked Basin project, menu label, permanent-deletion toggle, cache duration and missing-form tracking.
- “Test connection” button on the settings screen.
- “Basin data” option in Utilities → Clear Caches.
- Forms screen listing every form in the linked Basin project, with type, spam protection, inbox and spam counts, endpoint URL and search.
- Form page with the endpoint URL, settings summary, ready-to-paste template code and an “Open in Basin” link.
- Form Studio forms: admins can paste a form’s embed code once so templates can output it. Embed codes are checked with Basin before saving, and a warning shows if a saved one stops working.
- Submissions screen for each form, with Inbox, Spam and Trash tabs, search, date range and sorting. Dates are shown in the form’s timezone, and unread submissions are in bold.
- Submission page with every field, attachments, location, page, referrer and browser, plus previous/next links. Opening a submission marks it as read in Basin.
- Mark submissions as read, unread, spam or not spam, move them to the trash and restore them, one at a time or in bulk. Large bulk actions run in the queue.
- Permanently delete submissions from Basin, when deletion is allowed.
- Attachments download through the Control Panel, so Basin’s public file links are never shown.
- Forms whose submissions come from Form Studio but have no embed code saved are flagged, with a one-click option to use the detected embed code.
- `craft.basin.form(uuid)` and `craft.basin.forms()` for templates. Lookups never wait more than 3 seconds for Basin and fall back to the last known list of forms.
- `form.render()` outputs a Form Studio form. Without saved embed code it outputs only an HTML comment for visitors, plus a notice in devMode and previews.
- Automatic spam protection: every Basin form on a front-end page gets the honeypot and CAPTCHA (reCAPTCHA v3 or v2, hCaptcha or Turnstile) that Basin requires for it, however the form was added, including hard-coded markup, `{% cache %}` blocks and forms added by JavaScript. Opt out per form with `data-basin-protect="false"`, or turn it off in the settings.
- `form.honeypot()`, `form.captcha()` and `form.spamProtection()` for placing spam protection yourself.
- Basin Form field type, with a setting to limit it to endpoint forms, Form Studio forms with embed code, or particular forms. A form that’s no longer in the project shows a warning and is kept until an editor changes it.
- Warnings in the Control Panel when the site uses a form that isn’t in the linked project, or renders a Form Studio form without its embed code, with the page it was used on.
- User permissions: “View submissions and submission counts”, “Manage submissions”, “Export submissions” and (when deletion is allowed) “Permanently delete submissions”. Submission counts are hidden from users without “View submissions”.
- If Basin can’t be reached, the Control Panel shows the last known list of forms.
