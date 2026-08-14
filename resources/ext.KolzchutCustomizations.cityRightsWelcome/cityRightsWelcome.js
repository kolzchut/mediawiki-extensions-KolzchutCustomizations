/*!
 * CityRights welcome dialog for partner-referral traffic.
 *
 * Shows a welcome modal (content fetched from an on-wiki
 * MediaWiki:CityRights-welcome-<source> page) to visitors arriving with
 * ?utm_source=<known partner>. Everything is gated client-side on the
 * query parameter: the sites sit behind a shared edge cache, so the
 * server-rendered HTML must never vary on utm_source.
 *
 * Ported from the on-wiki gadget MediaWiki:Gadget-CityRightsWelcome.js
 * (main site, he + ar copies), unified:
 * - the wiki's content language picks the initial dialog language and
 *   the per-wiki partner list (the ar wiki only recognised
 *   justice.gov.il);
 * - language links come from wgCassandraLanguageLinks (Cassandra skin);
 *   the old provider, extension:WRLanguageLinks' wgLanguageLinks, was
 *   removed. The `|| {}` fallback keeps a missing provider from
 *   throwing;
 * - bootstrap.modal (registered by the Cassandra skin) is lazy-loaded
 *   before showing the dialog. The old ar copy called .modal() without
 *   loading it and threw, so the dialog never appeared on ar.
 *
 * The cookie and localStorage keys are unchanged, so dismissals made
 * under the gadget survive the migration.
 */
( function () {
	'use strict';

	var wikiLanguage = mw.config.get( 'wgContentLanguage' ),
		legitSourcesByWiki = {
			he: [
				'hodhasharon', 'patientsrightssociety', 'health.gov.il',
				'justice.gov.il', 'mse.gov.il', 'education.gov.il', 'cio.gov.il',
				'women.mse.gov.il', 'young.mse.gov.il', 'seniors.mse.gov.il',
				'minorities.mse.gov.il', 'digitalisrael.mse.gov.il', 'ceo.mse.gov.il',
				'hospitals.health.gov.il'
			],
			ar: [ 'justice.gov.il' ]
		};

	var cr = mw.CityRights = {
		savedSettings: null, // Loaded on init
		$dialog: null,
		legitSources: legitSourcesByWiki[ wikiLanguage ] || [],
		language: wikiLanguage,
		languageSwitchFlag: false,
		languageLinks: mw.config.get( 'wgCassandraLanguageLinks' ) || {},
		hasLanguageLink: function ( langCode ) {
			return !!cr.languageLinks[ langCode ];
		},
		getLanguageLinkUrl: function ( langCode ) {
			var link = cr.languageLinks[ langCode ];
			if ( link ) {
				return link.url;
			}
			return null;
		},
		hasTranslation: function ( langCode ) {
			return cr.settings.languages.indexOf( langCode ) !== -1;
		},
		shouldTranslate: function ( langCode ) {
			return ( cr.hasLanguageLink( langCode ) && cr.hasTranslation( langCode ) );
		},
		perSourceSettings: {
			'justice.gov.il': {
				backTo: 'https://www.justice.gov.il/',
				title: false,
				languages: [ 'he', 'ar' ],
				texts: {
					he: {
						okButton: 'אני מאשר/ת'
					},
					ar: {
						okButton: 'انا اوافق'
					}
				}
			},
			'mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/ministry_for_social_equality',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'women.mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/the_authority_for_the_advancement_of_the_status_of_women',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'young.mse.gov.il': {
				backTo: 'https://www.gov.il/he/departments/units/tzeirim',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'seniors.mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/Topics/senior_citizens_subject',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'minorities.mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/Units/minorities_shivyon',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'digitalisrael.mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/digital_israel',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'ceo.mse.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/ministry_for_social_equality',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'education.gov.il': {
				backTo: 'https://edu.gov.il/',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'health.gov.il': {
				backTo: 'https://www.gov.il/he/departments/ministry_of_health/',
				title: false,
				okButton: 'אני מאשר/ת'
			},
			'hospitals.health.gov.il': {
				title: false,
				okButton: 'אני מאשר/ת',
				allowDoNotShowAgain: false
			},
			'cio.gov.il': {
				backTo: 'https://www.gov.il/he/Departments/technical_support_for_online_services',
				title: false,
				okButton: 'אני מאשר/ת',
				allowDoNotShowAgain: false
			}
		},
		cookieName: '_CityRightsNoShow',
		msgPrefix: 'MediaWiki:CityRights-welcome-',
		listNoShow: [],
		param: 'utm_source',
		source: '',
		settings: {
			backTo: false,
			allowDoNotShowAgain: true,
			languages: [ 'he' ]
		},
		texts: {
			he: {
				title: 'ברוכים הבאים לכל זכות!',
				okButton: 'סגור',
				closeButton: 'סגור',
				doNotShowAgain: 'אל תציגו בעתיד',
				backToOfficialSite: 'לא, תודה - קחו אותי לאתר הרשמי',
				langSwitchButton: 'العربية'
			},
			ar: {
				title: 'ברוכים הבאים לכל זכות!',
				okButton: 'إغلاق',
				closeButton: 'إغلاق',
				doNotShowAgain: 'لا تعرضوا الاقتراح في المستقبل',
				backToOfficialSite: 'لا، شكرا - اود الانتقال للموقع الرسمي',
				langSwitchButton: 'עברית'
			}
		},

		newDialog: function ( text ) {
			if ( cr.$dialog !== null ) {
				cr.$dialog.modal( 'hide' );
				cr.$dialog.remove();
				cr.languageSwitchFlag = false;
			}
			cr.$dialog = cr.createDialogElement( text );

			cr.$dialog.find( '.cityrights-welcome-language-switcher' ).on( 'click', '.btn', function () {
				cr.switchLanguage( cr.language !== 'he' ? 'he' : 'ar' );
			} );

			cr.$dialog.on( 'hide.bs.modal', function () {
				// This might be a language switch, not closing of the modal
				if ( cr.languageSwitchFlag === true ) {
					return;
				}
				if ( cr.settings.allowDoNotShowAgain === true ) {
					var doNotShowAgain = cr.$dialog.find( '.dismiss-forever > input' ).prop( 'checked' );
					if ( doNotShowAgain ) {
						cr.listNoShow.push( cr.source );
						mw.cookie.set( cr.cookieName, JSON.stringify( cr.listNoShow ) );
					}
				}

				if ( cr.language !== 'he' ) {
					var link = cr.getLanguageLinkUrl( cr.language );
					if ( link ) {
						window.location.href = link;
					}
				}
			} );

			cr.$dialog.on( 'shown.bs.modal', function () {
				cr.$dialog.find( '.btn-default' ).trigger( 'focus' );
			} );

			// bootstrap.modal is registered by the Cassandra skin; load it
			// only when the dialog is actually shown.
			mw.loader.using( 'bootstrap.modal' ).then( function () {
				cr.$dialog.modal();
			}, function ( error ) {
				mw.log.error( 'CityRightsWelcome: bootstrap.modal failed to load', error );
			} );
		},

		/*
		 * options.backTo: a URL to send the user to if the dialog is declined.
		 * This turns into a yes/no confirmation.
		 */
		createDialogElement: function ( content ) {
			var texts = cr.texts[ cr.language ],
				backButton = '';
			if ( cr.settings.backTo ) {
				backButton = '<a class="backButton" href="' + cr.settings.backTo + '">' + texts.backToOfficialSite + '</a>';
			}
			var html = '<div class="modal modal-govt-welcome" data-backdrop="static" tabindex="-1" role="dialog">' +
				'<div class="modal-dialog" role="document">' +
				'<div class="modal-content">';

			if ( cr.settings.title ) {
				html += '<div class="modal-header">' +
					'<button type="button" class="close" data-dismiss="modal" aria-label="' + texts.closeButton + '"><span aria-hidden="true">&times;</span></button>' +
					'<h4 class="modal-title text-center">' + texts.title + '</h4>' +
					'</div>';
			}
			html += '<div class="modal-body">';
			if ( cr.shouldTranslate( 'ar' ) ) {
				html += '<div class="cityrights-welcome-language-switcher text-right"><div class="btn">' + texts.langSwitchButton + '</div></div>';
			}
			html += content + '</div>' +
				'<div class="modal-footer">' +
				'<button type="button" class="btn btn-default btn-lg" data-dismiss="modal">' + texts.okButton + '</button>' +
				'<div class="form-inline modal-footer-extra-controls">';
			if ( cr.settings.allowDoNotShowAgain === true ) {
				html += '<label class="dismiss-forever checkbox">' +
					'<input type="checkbox"> <span>' + texts.doNotShowAgain + '</span>' +
					'</label>';
			}
			html += backButton +
				'</div>' +
				'</div>' +
				'</div><!-- /.modal-content --></div><!-- /.modal-dialog --></div>';

			return $( html );
		},

		init: function () {
			var language;
			cr.savedSettings = mw.storage.getObject( 'CityRightsSettings' ) || {};
			cr.source = mw.util.getParamValue( cr.param );
			if ( cr.source === null || cr.source === '' ) {
				return;
			}
			cr.source = cr.source.replace( / /g, '' ).toLowerCase();
			if ( cr.legitSources.indexOf( cr.source ) < 0 ) {
				return;
			}

			cr.loadNoShowList();
			if ( cr.listNoShow.indexOf( cr.source ) > -1 ) {
				return;
			}

			var sourceSettings = cr.perSourceSettings[ cr.source ];
			if ( typeof ( sourceSettings ) === 'object' ) {
				$.extend( true, cr.settings, sourceSettings );
				$.extend( true, cr.texts, sourceSettings.texts );
			}

			language = mw.util.getParamValue( 'language' ) || cr.savedSettings.language;
			if ( language !== cr.language && cr.shouldTranslate( language ) ) {
				cr.setLanguage( language );
			}

			cr.getContent();
		},

		loadNoShowList: function () {
			var cookie = mw.cookie.get( cr.cookieName );
			try {
				cr.listNoShow = JSON.parse( cookie );
			} catch ( e ) {}

			cr.listNoShow = cr.listNoShow || []; // Handle NULL or error
		},

		getContent: function () {
			var api, basePageName, pageLangSuffix, title, templateParams, wikitext;

			api = new mw.Api();
			basePageName = cr.msgPrefix + mw.util.rawurlencode( cr.source );
			pageLangSuffix = ( cr.language === 'he' ) ? '' : ( '/' + cr.language );
			title = basePageName + pageLangSuffix;
			templateParams = cr.settings.name ? 'name=' + cr.settings.name : '';
			templateParams = '|' + templateParams;
			wikitext = '{{' + title + templateParams + '}}';

			api.parse( wikitext, { disablelimitreport: true, uselang: 'he' } )
				.done( function ( result ) {
					if ( result.pageid !== 0 ) {
						cr.newDialog( result );
					}
				} )
				.fail( function ( error ) {
					mw.log( 'Message failed to load through the API: ', error );
				} );
		},

		saveSettings: function () {
			mw.storage.setObject( 'CityRightsSettings', cr.savedSettings );
		},

		switchLanguage: function ( langCode ) {
			if ( langCode !== 'he' && !cr.shouldTranslate( langCode ) ) {
				return;
			}

			cr.languageSwitchFlag = true;
			cr.setLanguage( langCode );
			cr.getContent();
		},

		setLanguage: function ( langCode ) {
			cr.language = langCode;
			cr.savedSettings.language = langCode;
			cr.saveSettings();
		}
	};

	cr.init();

}() );
