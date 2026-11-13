# Release Notes for Basin

## Unreleased

### Added
- Plugin settings: Basin Account API key (environment variable), linked Basin project, menu label, permanent-deletion toggle, cache duration and missing-form tracking.
- “Test connection” button on the settings screen.
- “Basin data” option in Utilities → Clear Caches.
- Forms screen listing every form in the linked Basin project, with type, spam protection, inbox and spam counts, endpoint URL and search.
- Form page with the endpoint URL, settings summary, ready-to-paste template code and an “Open in Basin” link.
- Form Studio forms: admins can paste a form’s embed code once so templates can output it. Embed codes are checked with Basin before saving, and a warning shows if a saved one stops working.
- User permissions: “View submissions and submission counts”, “Manage submissions”, “Export submissions” and (when deletion is allowed) “Permanently delete submissions”. Submission counts are hidden from users without “View submissions”.
- If Basin can’t be reached, the Control Panel shows the last known list of forms.
