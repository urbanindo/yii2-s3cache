<?php

/**
 * S3Cache class file.
 * @author Petra Barus <petra.barus@gmail.com>
 * @since 2015.02.25
 */

namespace UrbanIndo\Yii2\S3Cache;

use Aws\S3\S3Client;

/**
 * S3Cache provides caching for AWS S3.
 * 
 * This is intended for large and long-lived objects.
 * 
 * There are some consideration for using the S3 Cache.
 * 
 * - It's preferable to use `\` as the separator for the key instead of `-` or
 *   other character. This will be convenient for manual cache browsing (either
 *   using S3 console or s3cmd).
 * 
 * - The getValue implementation will use only 1 GET instead of 1 GET and 1 HEAD 
 *   for expiration checking. Since the S3 is initially intended for big and 
 *   long-lived object, the first one is more preferable.
 * 
 * @author Petra Barus <petra.barus@gmail.com>
 * @since 2015.02.25
 */
class Cache extends \yii\caching\Cache {

    /**
     * The directory separator.
     */
    const DIRECTORY_SEPARATOR = '/';

    /**
     * The probability (parts per million) that garbage collection (GC) should be performed
     * when storing a piece of data in the cache. Defaults to 10, meaning 0.001% chance.
     * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all.
     * @var integer 
     */
    public $gcProbability = 10;

    /**
     * The bucket to store the cache.
     * @var string
     */
    public $bucket = '';

    /**
     * The path prefix.
     * @var string 
     */
    public $cachePrefix = '';

    /**
     * Cache file suffix. Defaults to '.bin'.
     * @var string 
     */
    public $cacheFileSuffix = '.bin';

    /**
     * Configuration for S3Client.
     * @var array
     */
    public $config;

    /**
     * The S3 client.
     * @var S3Client
     */
    private $_client;

    /**
     * Whether to store in reduced redundancy or not.
     * @var boolean
     */
    public $reducedRedundancy = true;

    /**
     * Initializes the S3 client.
     */
    public function init() {
        parent::init();
        $this->_client = S3Client::factory($this->config);
    }

    /**
     * Stores a value identified by a key into cache if the cache does not contain this key.
     * This is the implementation of the method declared in the parent class.
     *
     * @param string $key the key identifying the value to be cached
     * @param string $value the value to be cached
     * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
     * @return boolean true if the value is successfully stored into cache, false otherwise
     */
    protected function addValue($key, $value, $duration) {
        $cacheKey = $this->getCacheKey($key);
        try {
            if ($this->getObjectExpirationTime($cacheKey) > time()) {
                return false;
            }
            return $this->setValue($key, $value, $duration);
        } catch (\Aws\S3\Exception\NoSuchKeyException $exc) {
            return $this->setValue($key, $value, $duration);
        }
    }

    /**
     * Deletes a value with the specified key from cache
     * This is the implementation of the method declared in the parent class.
     * 
     * @param string $key the key of the value to be deleted
     * @return boolean if no error happens during deletion
     */
    protected function deleteValue($key) {
        $cacheKey = $this->getCacheKey($key);
        try {
            $return = $this->deleteObject($cacheKey);
            return $return['DeleteMarker'] == 'true';
        } catch (\Aws\S3\Exception\NoSuchKeyException $exc) {
            return false;
        }
    }

    /**
     * Deletes all values from cache.
     * This is the implementation of the method declared in the parent class.
     * @return boolean whether the flush operation was successful.
     */
    protected function flushValues() {
        $this->gc(true, false);
        return true;
    }

    /**
     * Removes expired cache files.
     * @param boolean $force whether to enforce the garbage collection regardless of [[gcProbability]].
     * Defaults to false, meaning the actual deletion happens with the probability as specified by [[gcProbability]].
     * @param boolean $expiredOnly whether to removed expired cache files only.
     * If false, all cache files under [[cachePath]] will be removed.
     */
    public function gc($force = false, $expiredOnly = true) {
        $time = time();
        if ($force || mt_rand(0, 1000000) < $this->gcProbability) {
            foreach ($this->listCacheKeys() as $cacheKey) {
                if (!$expiredOnly || $expiredOnly && $this->getObjectExpirationTime($cacheKey)
                        > $time) {
                    $this->deleteObject($cacheKey);
                }
            }
        }
    }

    /**
     * List cache keys.
     */
    private function listCacheKeys() {
        try {
            $objects = $this->_client->getIterator('ListObjects',
                    [
                'Bucket' => $this->bucket,
                'Prefix' => $this->cachePrefix,
            ]);
            $keys = [];
            foreach ($objects as $object) {
                //yield $object['Key'];
                $keys[] = $object['Key'];
            }
            return $keys;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            return [];
        }
    }

    /**
     * Retrieves a value from cache with a specified key.
     * This is the implementation of the method declared in the parent class.
     * 
     * The implementation will use only 1 GET instead of 1 GET and 1 HEAD for
     * expiration checking. Since the S3 is initially intended for big and
     * long-lived object, the first one is more preferable.
     * 
     * @param string $key a unique key identifying the cached value
     * @return string|boolean the value stored in cache, false if the value is not in the cache or expired.
     */
    protected function getValue($key) {
        $cacheKey = $this->getCacheKey($key);
        try {
            $result = $this->getObject($cacheKey);
            $expires = strtotime($result['Expires']);
            if ($expires > time()) {
                /* @var $body \Guzzle\Http\EntityBody */
                return $result['Body'] . "";
            } else {
                $this->deleteObject($cacheKey);
                return false;
            }
        } catch (\Aws\S3\Exception\NoSuchKeyException $exc) {
            return false;
        }
    }

    /**
     * Stores a value identified by a key in cache.
     * This is the implementation of the method declared in the parent class.
     *
     * @param string $key the key identifying the value to be cached
     * @param string $value the value to be cached
     * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
     * @return boolean true if the value is successfully stored into cache, false otherwise
     */
    protected function setValue($key, $value, $duration) {
        $cacheKey = $this->getCacheKey($key);
        $timestamp = time() + (($duration > 0) ? $duration : 31536000);
        $result = $this->_client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $cacheKey,
            'Body' => $value,
            'Expires' => $timestamp,
            'ContentType' => 'text/plain',
            'StorageClass' => $this->reducedRedundancy ? 'REDUCED_REDUNDANCY' : 'STANDARD',
        ]);
        return $result['ETag'] !== null;
    }

    /**
     * Returns the cache file path given the cache key.
     * 
     * @param string $key cache key
     * @return string the cache file path
     */
    protected function getCacheKey($key) {
        return (!empty($this->cachePrefix) ? $this->cachePrefix . '/' : '') . $key . $this->cacheFileSuffix;
    }

    /**
     * Delete object in the S3
     * @param string $cacheKey the cache key.
     * @return mixed the result.
     */
    private function deleteObject($cacheKey) {
        return $this->_client->deleteObject(array(
                    'Bucket' => $this->bucket,
                    'Key' => $cacheKey,
        ));
    }

    /**
     * Get object expiration time.
     * @param string $cacheKey get the cache key.
     * @return integer the cache key.
     */
    private function getObjectExpirationTime($cacheKey) {
        $result = $this->_client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $cacheKey,
        ]);
        return strtotime($result['Expires']);
    }

    /**
     * Get object from bucket.
     * @param string $cacheKey the cache key.
     * @return mixed
     */
    private function getObject($cacheKey) {
        return $this->_client->getObject([
                    'Bucket' => $this->bucket,
                    'Key' => $cacheKey,
        ]);
    }

}
