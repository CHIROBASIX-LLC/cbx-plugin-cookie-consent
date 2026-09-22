=== CHIROBASIX - Cookie Consent Banner ===
Contributors: chirobasix
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
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

Two records, because they answer different questions.

**Banner version history.** Every time the wording or design changes, a timestamped copy is kept.
This is the evidence regulators actually name (CNIL's recommended proof methods and the German
DSK guidance are both about retaining the banner's successive configurations), and it costs no
visitor privacy at all.

**Per-decision rows.** A unique reference, a timestamp, the choices made, how they were made and
the policy version. Exportable as CSV. This matches ISO/IEC TS 27560:2023, the standard for
consent records, which has no IP field.

No law requires any of this. No US law requires cookie-consent logging at all, and GDPR Art. 7(1)
requires only that you be ABLE TO DEMONSTRATE consent, which EDPB Guidelines 05/2020 para 106 says
"should not in itself lead to excessive amounts of additional data processing".

Deliberately NOT stored: IP address (off by default; can be switched to anonymised or full),
user agent (a fingerprinting vector that adds nothing to proof of consent), and the page address
(off by default: on a health site, pairing a visitor with a timestamped page view is the exact
record that has caused trouble elsewhere).

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

= 1.2.0 =
Consent log reworked to match what regulators actually ask for. Added a banner version history,
which is the evidence CNIL and the German DSK name. Each row now records HOW the choice was made
(accepted all, declined all, or chose). Removed user-agent capture entirely. The page address is
now off by default. Existing tables are migrated automatically.

= 1.1.0 =
Banner styling is now hardened against theme CSS. Elementor kits style every button globally
(display, font size, uppercase, letter spacing), which broke the button layout and, because the
theme sets `display`, even defeated the `hidden` attribute so the Save button showed when it
should not have. All banner rules are now double-class specificity with explicit resets.

= 1.0.1 =
Button corner rounding is now its own setting, so pill buttons can match a site whose buttons are pills.

= 1.0.0 =
First release.
