This extension adds some customizations that are only relevant to the [Kol-Zchut website](https://www.kolzchut.org.il).

Currently, it changes the default search namespaces for anonymous users and editors - we don't
want anonymous users to have autocomplete suggestions for our "backstage" namespaces, and this
seemed like the best (and only?) way to achieve that.

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
