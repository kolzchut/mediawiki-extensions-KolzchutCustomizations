This extension adds some customizations that are only relevant to the [Kol-Zchut website](https://www.kolzchut.org.il).

Currently, it:

- changes the default search namespaces for anonymous users and editors - we don't
  want anonymous users to have autocomplete suggestions for our "backstage" namespaces, and this
  seemed like the best (and only?) way to achieve that.
- shows the CityRights partner-referral welcome dialog (see below).

## CityRights welcome dialog

The `ext.KolzchutCustomizations.cityRightsWelcome` module shows a welcome
modal to visitors arriving from a partner site with
`?utm_source=<known partner>` (e.g. `justice.gov.il`). It was ported from the
on-wiki gadget `MediaWiki:Gadget-CityRightsWelcome.js`/`.css` (main-site
he + ar copies, unified) so that it is version-controlled, greppable and
linted; see kolzchut/kz-mediawiki-main#81.

Key points:

- Loaded (via `BeforePageDisplay`) only on wikis whose content language is
  `he` or `ar` - the wikis that had the gadget.
- All gating happens **client-side** on the `utm_source` query parameter.
  Server-rendered output must not vary on a query parameter, because the
  sites sit behind a shared edge cache.
- The recognised partner list and per-partner settings live in
  `resources/ext.KolzchutCustomizations.cityRightsWelcome/cityRightsWelcome.js`
  (`legitSourcesByWiki` / `perSourceSettings`). The dialog *content* stays
  on-wiki, in `MediaWiki:CityRights-welcome-<source>` (with a `/ar`
  subpage for Arabic).
- Language links for the in-dialog language switcher come from
  `wgCassandraLanguageLinks` (set by the Cassandra skin), and the modal is
  shown with the Cassandra-provided `bootstrap.modal` module (lazy-loaded).
- User dismissals use the same cookie (`_CityRightsNoShow`) and
  localStorage key (`CityRightsSettings`) as the old gadget, so they
  survive the migration.

## Configuration
`$wgNamespacesToBeSearchedDefaultAnon`: an array of namespaces to search by default for anonymous users.
 since we use Google Search for anonymous users, this really only affects the search suggestions.
 default: [ NS_MAIN, NS_PROJECT ]

## Testing

### Test Case 1: Anonymous User Search Namespaces
1. Log out (or use private browsing)
2. Navigate to Special:Preferences (or check search behavior)
3. Verify that only namespaces 0 and 4 are searched by default (as configured in `$wgNamespacesToBeSearchedDefaultAnon`)

### Test Case 2: Registered User Search Namespaces
1. Log in as a user with edit rights
2. Check search namespace preferences
3. Verify that all configured search namespaces are enabled (from `$wgNamespacesToBeSearchedDefault`)

### Test Case 3: Extension Loading
Run the following command to verify the extension loads without errors:
```bash
php maintenance/run.php update
```

This will load the extension and verify hook interfaces exist, run any database updates, and confirm no fatal errors during initialization.
