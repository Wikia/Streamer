<?php
/**
 * Streamer
 * Twitch API
 *
 * @license LGPLv3
 * @package Streamer
 * @link    https://www.mediawiki.org/wiki/Extension:Streamer
 **/

class ApiTwitch extends ApiStreamerBase {
	/**
	 * API Entry Point
	 *
	 * @var string
	 */
	protected $apiEntryPoint = "https://api.twitch.tv/kraken/";

	/**
	 * Main Constructor
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->service = 'twitch';
		parent::__construct();
	}

	/**
	 * Set the user identifier.
	 * This function should do any validation and return a boolean.
	 *
	 * @access public
	 * @return string	User Identifier
	 * @return boolean	Success
	 */
	public function setUser($user) {
		if (preg_match("#^[\w]+$#i", $user) !== 1) {
			return false;
		}
		$this->user = $user;

		if ($this->loadCache()) {
			return true;
		}

		// Get channel ID from username
		$userData = $this->makeApiRequest(['users?login=' . $this->user]);
		if ($userData === false || !isset($userData['_total']) || $userData['_total'] != 1) {
			return false;
		}
		$userID = $userData['users'][0]['_id'];

		// Get channel data
		$channel = $this->makeApiRequest(['channels', $userID]);
		if ($channel === false) {
			return false;
		}

		if (isset($channel['display_name'])) {
			$this->setName($channel['display_name']);
			$this->setLogo($channel['logo']);
			$this->setDoing($channel['game']);
			$this->setLifetimeViews($channel['views']);
			$this->setChannelUrl($channel['url']);
			$this->setStatus($channel['status']);
			$this->setFollowers($channel['followers']);
		}

		$stream = $this->makeApiRequest(['streams', $userID]);

		// Twitch sort of pretends this end point does not exist when the user is not streaming.  So instead of returning false on a fake API error it is better to check and set the stream to be listed as offline.
		if (is_array($stream) && array_key_exists('stream', $stream) && $stream['stream'] !== null) {
			$this->setViewers($stream['stream']['viewers']);
			$this->setThumbnail($stream['stream']['preview']);
			$this->setOnline(true);
		} else {
			$this->setOnline(false);
		}

		$this->updateCache();

		return true;
	}

	/**
	 * Make an API request to the service.
	 *
	 * @access protected
	 * @param  array	URL bits to put between directory separators.
	 * @return mixed	Parsed JSON or false on error.
	 */
	protected function makeApiRequest($bits) {
		$req = $this->httpFactory->create(
			$this->getFullRequestUrl( $bits ),
			[ 'timeout' => $this->getRequestOptions()['timeout'], ]
		);
		$req->setHeader("Client-ID", $this->config->get( 'TwitchClientId' ));
		$req->setHeader("Accept", "application/vnd.twitchtv.v5+json");
		$req->execute();

		$rawJson = $req->getContent();
		$json = $this->parseRawJson($rawJson);

		if ($json === false) {
			return false;
		}
		return $json;
	}
}
