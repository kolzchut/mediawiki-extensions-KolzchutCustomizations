<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\KolzchutCustomizations;

use Config;
use Language;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentity;

class Hooks implements
	\MediaWiki\User\Hook\UserGetDefaultOptionsHook,
	\MediaWiki\User\Options\Hook\LoadUserOptionsHook,
	\MediaWiki\Hook\BeforePageDisplayHook
{

	/** @var PermissionManager */
	private $permissionManager;

	/** @var Config */
	private $mainConfig;

	/** @var Language */
	private $contentLanguage;

	/**
	 * @param PermissionManager $permissionManager injected service
	 * @param Config $mainConfig injected service
	 * @param Language $contentLanguage injected service
	 */
	public function __construct(
		PermissionManager $permissionManager,
		Config $mainConfig,
		Language $contentLanguage
	) {
		$this->permissionManager = $permissionManager;
		$this->mainConfig = $mainConfig;
		$this->contentLanguage = $contentLanguage;
	}

	/**
	 * This hook is called after fetching core default user options but before returning the options.
	 * We use it to turn off the default namespaces for search.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserGetDefaultOptions
	 *
	 * @param array &$defaultOptions Array of preference keys and their default values.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		$allNamespaces = $this->contentLanguage->getNamespaceIds();
		$namespacesToAllow = $this->mainConfig->get( 'NamespacesToBeSearchedDefaultAnon' );
		foreach ( $allNamespaces as $ns ) {
			$defaultOptions[ 'searchNs' . $ns ] = in_array( $ns, $namespacesToAllow );
		}
	}

	/**
	 * This hook is called when user options/preferences are being loaded from the database.
	 * Therefore, it's not called for anonymous users... unfortunately for us.
	 * So we use onUserGetDefaultOptions() to turn off most namespaces for search,
	 * then use this to turn them back on for editors.
	 *
	 * @param UserIdentity $user User identity object
	 * @param array &$options Options, can be modified.
	 */
	public function onLoadUserOptions( UserIdentity $user, array &$options ): void {
		if ( $user->isRegistered() &&
			$this->permissionManager->userHasRight( $user, 'edit' )
		) {
			$namespacesToSearch = $this->mainConfig->get( 'NamespacesToBeSearchedDefault' );
			foreach ( $namespacesToSearch as $ns => $value ) {
				// Ignore talk namespaces
				if ( $ns % 2 !== 0 ) {
					continue;
				}
				$options[ 'searchNs' . $ns ] = true;
			}
		}
	}

	/**
	 * Load the CityRights partner-welcome module on the wikis that had the
	 * CityRightsWelcome gadget (main-site he and ar). The module is added on
	 * every page view, exactly like the `default` gadget it replaces: the
	 * behaviour is gated client-side on ?utm_source=<partner>, because the
	 * sites sit behind a shared edge cache and server output must not vary
	 * on a query parameter.
	 *
	 * @param \MediaWiki\Output\OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( in_array( $this->contentLanguage->getCode(), [ 'he', 'ar' ], true ) ) {
			$out->addModules( 'ext.KolzchutCustomizations.cityRightsWelcome' );
		}
	}

}
