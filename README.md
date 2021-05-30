This extension adds some customizations that are only relevant to the [Kol-Zchut website](https://www.kolzchut.org.il).

Currently, it changes the default search namespaces for anonymous users and editors - we don't
want anonymous users to have autocomplete suggestions for our "backstage" namespaces, and this
seemed like the best (and only?) way to achieve that.

## Configuration
`$wgNamespacesToBeSearchedDefaultAnon`: an array of namespaces to search by default for anonymous users.
 since we use Google Search for anonymous users, this really only affects the search suggestions.
 default: [ NS_MAIN, NS_PROJECT ]
