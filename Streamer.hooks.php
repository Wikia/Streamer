<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;

/**
 * Streamer
 * Streamer Hooks
 *
 * @license LGPLv3
 * @package Streamer
 * @link    https://www.mediawiki.org/wiki/Extension:Streamer
 **/

class StreamerHooks {
	/**
	 * Valid parameters.
	 *
	 * @var array
	 */
	static private $parameters = [
		'service' => [
			'required'	=> true,
			'default'	=> null,
			'values'	=> [
				'azubu',
				'mixer',
				'hitbox',
				'twitch',
				'youtube'
			]
		],
		'user' => [
			'required'	=> true,
			'default'	=> null
		],
		'template' => [
			'required'	=> false,
			'default'	=> 'block',
			'built_in'	=> [
				'block',
				'debug',
				'link',
				'live',
				'minilive',
				'thumbnail',
				'viewers'
			]
		],
		'link' => [
			'required'	=> false,
			'default'	=> null
		],
	];

	/**
	 * Any error messages that may have been triggerred.
	 *
	 * @var array
	 */
	static private $errors = false;

	/**
	 * Sets up this extension's parser functions.
	 *
	 * @access public
	 * @param  object	Parser object passed as a reference.
	 * @return boolean	true
	 */
	public static function onParserFirstCallInit(Parser &$parser) {
		if (!defined('STREAMER_VERSION')) {
			define('STREAMER_VERSION', '0.6.0');
		}

		$parser->setFunctionHook("streamer", "StreamerHooks::parseStreamerTag", SFH_OBJECT_ARGS);
		$parser->setFunctionHook("streamerinfo", "StreamerHooks::parseStreamerInfoTag", SFH_OBJECT_ARGS);

		return true;
	}

	/**
	 * Displays streamer information for the given parameters.
	 *
	 * @access public
	 * @param  object	Parser
	 * @param  object	PPFrame
	 * @param  array	Arguments
	 * @return array	Generated Output
	 */
	public static function parseStreamerTag(Parser &$parser, PPFrame $frame, $arguments) {
		self::$errors = false;

		/************************************/
		/* Clean Parameters                 */
		/************************************/
		$rawParameterOptions = [];
		if (is_array($arguments)) {
			foreach ($arguments as $argument) {
				$rawParameterOptions[] = trim($frame->expand($argument));
			}
		}
		$parameters = self::cleanAndSetupParameters($rawParameterOptions);

		/************************************/
		/* Error Checking                   */
		/************************************/
		if (self::$errors === false) {
			$streamer = ApiStreamerBase::newFromService($parameters['service']);
			if ($streamer !== false) {
				$userGood = $streamer->setUser($parameters['user']);
				if (!$userGood) {
					self::setError('streamer_error_invalid_user', [$parameters['service'], $parameters['user']]);
				} else {
					/************************************/
					/* HMTL Generation                  */
					/************************************/
					$streamerInfo = StreamerInfo::newFromServiceAndName($parameters['service'], $parameters['user']);
					$displayName = $streamerInfo->getDisplayName();

					if (isset($parameters['link'])) {
						$link = $parameters['link'];
					} else {
						$link = $streamerInfo->getLink();
					}
					if (!$link) {
						// Fallback in case of no actual links.
						$link = $streamer->getChannelUrl();
					}

					$variables = [
						'%ONLINE%'			=> $streamer->getOnline(),
						'%NAME%'			=> (!empty($displayName) ? $displayName : $streamer->getName()),
						'%VIEWERS%'			=> $streamer->getViewers(),
						'%DOING%'			=> $streamer->getDoing(),
						'%STATUS%'			=> $streamer->getStatus(),
						'%LIFETIME_VIEWS%'	=> $streamer->getLifetimeViews(),
						'%FOLLOWERS%'		=> $streamer->getFollowers(),
						'%LOGO%'			=> $streamer->getLogo(),
						'%THUMBNAIL%'		=> $streamer->getThumbnail(),
						'%CHANNEL_URL%'		=> $streamer->getChannelUrl(),
						'%LINK%'			=> $link
					];

					$html = self::getTemplateWithReplacements($parameters['template'], $variables);

					$parser->getOutput()->addModuleStyles(['ext.streamer']);
				}
			} else {
				self::setError('streamer_error_missing_service', 'Api' . ucfirst($parameters['service']));
			}
		}

		if (self::$errors !== false) {
			$html = "
			<div class='errorbox'>
				<strong>Streamer " . STREAMER_VERSION . "</strong><br/>
				" . implode("<br/>\n", self::$errors) . "
			</div>";
		}

		return [
			$html,
			'noparse' => false,
			'isHTML' => true
		];
	}

	/**
	 * Update database records for streamer information from the streamerinfo tag.
	 *
	 * @access public
	 * @param  object	Parser
	 * @param  object	PPFrame
	 * @param  array	Arguments
	 * @return array	Generated Output
	 */
	public static function parseStreamerInfoTag(Parser &$parser, PPFrame $frame, $arguments) {
		self::$errors = false;

		$title = $parser->getPage();
		$html = '';

		if ($parser->getOptions()->getIsPreview()) {
			return [
				$html,
				'noparse' => true,
				'isHTML' => true
			];
		}

		/************************************/
		/* Clean Parameters                 */
		/************************************/
		$rawParameterOptions = [];
		if (is_array($arguments)) {
			foreach ($arguments as $argument) {
				$rawParameterOptions[] = trim($frame->expand($argument));
			}
		}
		$parameters = self::cleanAndSetupParameters($rawParameterOptions);

		/************************************/
		/* Error Checking                   */
		/************************************/
		if (self::$errors === false) {
			$streamer = ApiStreamerBase::newFromService($parameters['service']);
			$userGood = $streamer->setUser($parameters['user']);

			if (!$userGood) {
				self::setError('streamer_error_invalid_user', [$parameters['service'], $parameters['user']]);
			} else {
				/************************************/
				/* Database Handling                */
				/************************************/
				$streamerInfo = StreamerInfo::newFromServiceAndName($parameters['service'], $parameters['user']);
				$streamerInfo->load();

				if ( $title instanceof Title ) {
					$streamerInfo->setDisplayName( $title->getRootText() );
					$streamerInfo->setPageTitle( $title );
				}

				$streamerInfo->save();
			}
		}

		if (self::$errors !== false) {
			$html = "
			<div class='errorbox'>
				<strong>Streamer " . STREAMER_VERSION . "</strong><br/>
				" . implode("<br/>\n", self::$errors) . "
			</div>";
		}

		return [
			$html,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/**
	 * Clean user supplied parameters and setup defaults.
	 *
	 * @access private
	 * @param  array	Raw strings of 'parameter=option'.
	 * @return array	Safe Parameter => Option key value pairs.
	 */
	private static function cleanAndSetupParameters($rawParameterOptions) {
		// Check user supplied parameters.
		foreach ($rawParameterOptions as $raw) {
			$equals = strpos($raw, '=');
			if ($equals === false || $equals === 0 || $equals === strlen($raw) - 1) {
				continue;
			}

			[$parameter, $option] = explode('=', $raw);
			$parameter = trim($parameter);
			$option = trim($option);

			if (isset(self::$parameters[$parameter])) {
				if (array_key_exists('values', self::$parameters[$parameter]) && is_array(self::$parameters[$parameter]['values'])) {
					if (!in_array($option, self::$parameters[$parameter]['values'])) {
						// Throw an error.
						self::setError('streamer_error_invalid_option', [$parameter, $option]);
					} else {
						$cleanParameterOptions[$parameter] = $option;
					}
				} else {
					$cleanParameterOptions[$parameter] = $option;
				}
			} else {
				self::setError('streamer_error_bad_parameter', [$parameter]);
			}
		}

		foreach (self::$parameters as $parameter => $parameterData) {
			if ($parameterData['required'] && !array_key_exists($parameter, $cleanParameterOptions)) {
				self::setError('streamer_error_parameter_required', [$parameter]);
			}
			// Assign the default if not supplied by the user and a default exists.
			if (!$parameterData['required'] && !array_key_exists($parameter, $cleanParameterOptions) && $parameterData['default'] !== null) {
				$cleanParameterOptions[$parameter] = $parameterData['default'];
			}
		}

		return $cleanParameterOptions;
	}

	/**
	 * Return a parsed template with variables replaced.
	 *
	 * @access private
	 * @param  string	Template Name - Either a built in template or a namespaced template.
	 * @param  array	Replacement Variables
	 * @return string	HTML
	 */
	private static function getTemplateWithReplacements($template, $variables) {
		$rawTemplate = StreamerTemplate::get($template);

		if ($rawTemplate !== false) {
			foreach ($variables as $variable => $replacement) {
				$rawTemplate = str_replace($variable, $replacement, $rawTemplate);
			}
		}

		return $rawTemplate;
	}

	/**
	 * Set a non-fatal error to be returned to the end user later.
	 *
	 * @access private
	 * @param  string	Message language string.
	 * @param  array	Message replacements.
	 * @return void
	 */
	private static function setError($message, $replacements) {
		self::$errors[] = wfMessage($message, $replacements)->escaped();
	}

	/**
	 * Catch when #streamerinfo tags are removed and delete from the database.
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return bool
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $user,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	) {
		$revisionContent = $revisionRecord->getContent( $revisionRecord::RAW );
		$revisionLookup = MediaWikiServices::getInstance()
			->getRevisionLookup();
		$previousRevision = $revisionLookup->getPreviousRevision( $revisionRecord );

		if ( $previousRevision instanceof RevisionRecord ) {
			$previousRevisionContent = $previousRevision->getContent( $revisionRecord::RAW );
			if ( strpos( $previousRevisionContent->getNativeData(), "{{#streamerinfo" ) !== false &&
				 strpos( $revisionContent->getNativeData(), "{{#streamerinfo" ) === false ) {
				// Time to remove from the database.
				$DB = wfGetDB( DB_PRIMARY );
				$result = $DB->select(
					[ 'streamer' ],
					[ '*' ],
					[ 'page_title' => $wikiPage->getTitle() ],
					__METHOD__
				);
				$row = $result->fetchRow();

				$streamerInfo = StreamerInfo::newFromRow( $row );
				if ( $streamerInfo !== false ) {
					$streamerInfo->delete();
				}
			}
		}

		return true;
	}

	/**
	 * Setups and Modifies Database Information
	 *
	 * @access public
	 * @param  object	DatabaseUpdater Object
	 * @return boolean	true
	 */
	public static function onLoadExtensionSchemaUpdates($updater = null) {
		$extDir = __DIR__;
		$updater->addExtensionUpdate(['addTable', 'streamer', "{$extDir}/install/sql/streamer_table_streamer.sql", true]);

		return true;
	}
}
