<?php

namespace SilverStripe\Filesystem\Storage;

use Config;
use Exception;
use Flushable;
use SS_Cache;
use Zend_Cache_Core;

/**
 * Handle references to generated files via cached tuples
 *
 * Important: If you are using the default FlysystemStore with legacy_filenames, you will need to ?flush
 * in order to refresh combined files.
 * 
 * @package framework
 * @subpackage filesystem
 */
class CacheGeneratedAssetHandler implements GeneratedAssetHandler, Flushable {

	/**
	 * Lifetime of cache
	 *
	 * @config
	 * @var int
	 */
	private static $lifetime = 0;

	/**
	 * Backend for generated files
	 *
	 * @var AssetStore
	 */
	protected $assetStore = null;

	/**
	 * Assign the asset backend
	 *
	 * @param AssetStore $store
	 * @return $this
	 */
	public function setAssetStore(AssetStore $store) {
		$this->assetStore = $store;
		return $this;
	}

	/**
	 * Get the asset backend
	 *
	 * @return AssetStore
	 */
	public function getAssetStore() {
		return $this->assetStore;
	}

	/**
	 * @return Zend_Cache_Core
	 */
	protected static function get_cache() {
		$cache = SS_Cache::factory('CacheGeneratedAssetHandler');
		$cache->setLifetime(Config::inst()->get(__CLASS__, 'lifetime'));
		return $cache;
	}

	/**
	 * Flush the cache
	 */
	public static function flush() {
		self::get_cache()->clean();
	}

	public function getGeneratedURL($filename, $entropy = 0, $callback = null) {
		$result = $this->getGeneratedFile($filename, $entropy, $callback);
		if($result) {
			return $this
				->getAssetStore()
				->getAsURL($result['Filename'], $result['Hash'], $result['Variant']);
		}
	}

	public function getGeneratedContent($filename, $entropy = 0, $callback = null) {
		$result = $this->getGeneratedFile($filename, $entropy, $callback);
		if($result) {
			return $this
				->getAssetStore()
				->getAsString($result['Filename'], $result['Hash'], $result['Variant']);
		}
	}

	/**
	 * Generate or return the tuple for the given file, optionally regenerating it if it
	 * doesn't exist
	 *
	 * @param string $filename
	 * @param mixed $entropy
	 * @param callable $callback
	 * @return array tuple array if available
	 * @throws Exception If the file isn't available and $callback fails to regenerate content
	 */
	protected function getGeneratedFile($filename, $entropy = 0, $callback = null) {
		// Check if there is an existing asset
		$cache = self::get_cache();
		$cacheID = $this->getCacheKey($filename, $entropy);
		$data = $cache->load($cacheID);
		if($data) {
			$result = unserialize($data);
			return $this->validateResult($result, $filename);
		}

		// Regenerate
		if($callback) {
			// Invoke regeneration and save
			$content = call_user_func($callback);
			return $this->updateContent($filename, $entropy, $content);
		}
	}

	public function updateContent($filename, $entropy, $content) {
		$cache = self::get_cache();
		$cacheID = $this->getCacheKey($filename, $entropy);

		// Store content
		$result = $this
			->getAssetStore()
			->setFromString($content, $filename);
		if($result) {
			$cache->save(serialize($result), $cacheID);
		}
		
		return $this->validateResult($result, $filename);
	}

	/**
	 * Get cache key for the given generated asset
	 *
	 * @param string $filename
	 * @param mixed $entropy
	 * @return string
	 */
	protected function getCacheKey($filename, $entropy = 0) {
		$cacheID = sha1($filename);
		if($entropy) {
			$cacheID .= '_' . sha1($entropy);
		}
		return $cacheID;
	}

	/**
	 * Validate that the given result is valid
	 *
	 * @param mixed $result
	 * @param string $filename
	 * @return array The result
	 * @throws Exception
	 */
	protected function validateResult($result, $filename) {
		// Retrieve URL from tuple
		$store = $this->getAssetStore();
		if($result && $store->exists($result['Filename'], $result['Hash'], $result['Variant'])) {
			return $result;
		}

		throw new Exception("Error regenerating file \"{$filename}\"");
	}

}
