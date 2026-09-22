=== CHIROBASIX - Cookie Consent Banner ===
Contributors: chirobasix
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+

Cookie consent banner with Google Consent Mode v2. Design and wording are editable in WP Admin.
No third-party service, no subscription, no external requests, no per-visit limits.

== What it does ==

Shows a cookie banner. When a visitor declines, Google Analytics stops tracking them and any
tracking already stored on their device is deleted. When they accept, everything runs as normal.

It works by issuing Google's Consent Mode v2 signal before any tag loads, which is the part a
normal popup plugin cannot do. Google tags obey that signal on their own. Non-Google tags, such
as a Facebook pixel, need one trigger set in Google Tag Manager (see Settings > Cookie Consent >
Setup).

== Setup ==

1. Activate the plugin.
2. Settings > Cookie Consent to set colours and wording.
3. Add a link with the address `#cookie-settings` to the footer so visitors can change their mind.
4. If the site has a Facebook pixel, follow the Setup tab.

== Consent log ==

Every Accept and Decline is recorded in the site's own database: a unique reference, a timestamp,
the choices made, the policy version and the page. Exportable as CSV.

By default NO IP address is recorded. An IP is itself personal data, so storing one to prove that
someone declined tracking works against the point. Full or anonymised IP capture can be switched
on under Behaviour for a site that needs it.

== Notes for developers ==

- Consent Mode default is printed at `wp_head` priority 0, ahead of Elementor Custom Code
  (`wp_head` priority 10) and almost everything else.
- dataLayer events: `cbx_consent_ready`, `cbx_consent_update`, `cbx_consent_analytics_granted`,
  `cbx_consent_marketing_granted`. The two `_granted` events replay on every page load once a
  decision is stored, not only on the page where the visitor clicked.
- Registers as a WP Consent API consent manager when that plugin is present.
- Region is decided in the browser from the IANA timezone. Never branch server-side on the
  consent cookie: a page cache will serve one visitor's answer to everyone.
- Filters: `cbxcc_settings`, `cbxcc_enabled`.
- JS API: `window.cbxConsent.state()`, `window.cbxConsent.open()`.

== Changelog ==

= 1.0.0 =
First release.
