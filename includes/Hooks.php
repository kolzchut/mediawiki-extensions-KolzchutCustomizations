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
use User;

class Hooks implements
	\MediaWiki\User\Hook\UserGetDefaultOptionsHook,
	\MediaWiki\User\Hook\UserLoadOptionsHook
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
		$namespaces = $this->contentLanguage->getNamespaceIds();
		foreach ( $namespaces as $ns ) {
			$defaultOptions[ 'searchNs' . $ns ] = in_array( $ns, [ NS_MAIN, NS_PROJECT ] );
		}
	}

	/**
	 * This hook is called when user options/preferences are being loaded from the database.
	 * Therefore, it's not called for anonymous users... unfortunately for us.
	 * So we use onUserGetDefaultOptions() to turn off most namespaces for search,
	 * then use this to turn them back on for editors.
	 *
	 * @param User $user User object
	 * @param array &$options Options, can be modified.
	 */
	public function onUserLoadOptions( $user, &$options ) {
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

}
