<?php
/**
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Owncloud\Updater\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Event\ProgressEvent;
use Owncloud\Updater\Utils\Feed;

class Fetcher {

	const DEFAULT_BASE_URL = 'https://updates.owncloud.com/server/';

	/**
	 * @var Locator $locator
	 */
	protected $locator;

	/**
	 * @var ConfigReader $configReader
	 */
	protected $configReader;

	/**
	 * @var Client $httpClient
	 */
	protected $httpClient;
	protected $requiredFeedEntries = [
		'version',
		'versionstring',
		'url'
	];

	/**
	 * Constructor
	 *
	 * @param Client $httpClient
	 * @param Locator $locator
	 * @param ConfigReader $configReader
	 */
	public function __construct(Client $httpClient, Locator $locator, ConfigReader $configReader){
		$this->httpClient = $httpClient;
		$this->locator = $locator;
		$this->configReader = $configReader;
	}

	/**
	 * Download new owncloud package
	 * @param Feed $feed
	 * @param Callable $onProgress
	 * @throws \UnexpectedValueException
	 */
	public function getOwncloud(Feed $feed, callable $onProgress){
		if ($feed->isValid()){
			$downloadPath = $this->getBaseDownloadPath($feed);
			$url = $feed->getUrl();
			$request = $this->httpClient->createRequest(
					'GET',
					$url,
					[
						'save_to' => $downloadPath,
						'timeout' => 600
					]
			);
			$request->getEmitter()->on('progress', $onProgress);
			$response = $this->httpClient->send($request);
			if ($response->getStatusCode() !== 200){
				throw new \UnexpectedValueException('Failed to download ' . $url . '. Server responded with ' . $response->getStatusCode() . ' instead of 200.');
			}
		}
	}

	/**
	 * Produce a local path to save the package to
	 * @param Feed $feed
	 * @return string
	 */
	public function getBaseDownloadPath(Feed $feed){
		$basePath = $this->locator->getDownloadBaseDir();
		return $basePath . '/' . $feed->getDownloadedFileName();
	}

	/**
	 * Get md5 sum for the package
	 * @param Feed $feed
	 * @return string
	 */
	public function getMd5(Feed $feed){
		$fullChecksum = $this->download($feed->getChecksumUrl());
		// we got smth like "5776cbd0a95637ade4b2c0d8694d8fca  -"
		//strip trailing space & dash
		return substr($fullChecksum, 0, 32);
	}

	/**
	 * Read update feed for new releases
	 * @return Feed
	 */
	public function getFeed(){
		$url = $this->getFeedUrl();
		$xml = $this->download($url);
		$tmp = [];
		if ($xml){
			$loadEntities = libxml_disable_entity_loader(true);
			$data = @simplexml_load_string($xml);
			libxml_disable_entity_loader($loadEntities);
			if ($data !== false){
				$tmp['version'] = (string) $data->version;
				$tmp['versionstring'] = (string) $data->versionstring;
				$tmp['url'] = (string) $data->url;
				$tmp['web'] = (string) $data->web;
			}
		}

		return new Feed($tmp);
	}

	/**
	 * Produce complete feed URL
	 * @return string
	 */
	protected function getFeedUrl(){
		$currentVersion = $this->configReader->getByPath('system.version');
		$version = explode('.', $currentVersion);
		$version['installed'] = $this->configReader->getByPath('apps.core.installedat');
		$version['updated'] = $this->configReader->getByPath('apps.core.lastupdatedat');
		$version['updatechannel'] = $this->configReader->getByPath('apps.core.OC_Channel');
		$version['edition'] = $this->configReader->getEdition();
		$version['build'] = $this->locator->getBuild();

		$url = self::DEFAULT_BASE_URL . '?version=' . implode('x', $version);
		return $url;
	}

	/**
	 * Get URL content
	 * @param string $url
	 * @return string
	 * @throws \UnexpectedValueException
	 */
	protected function download($url){
		$response = $this->httpClient->get($url, ['timeout' => 600]);
		if ($response->getStatusCode() !== 200){
			throw new \UnexpectedValueException('Failed to download ' . $url . '. Server responded with ' . $response->getStatusCode() . ' instead of 200.');
		}
		return $response->getBody()->getContents();
	}

}
