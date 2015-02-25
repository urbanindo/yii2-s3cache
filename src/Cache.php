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
     * A string prefixed to every cache key so that it is unique globally in the whole cache storage.
     * It is recommended that you set a unique cache key prefix for each application if the same cache
     * storage is being used by different applications.
     * 
     * To ensure interoperability, only alphanumeric characters should be used.
     * 
     * But for S3 cache, it's okay to use '/' for convenience.
     * 
     * @var string 
     */
    public $keyPrefix;

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
     * The directory path for the cache.
     * 
     * This is useful if the bucket is used by many other components.
     * @var string
     */
    public $directoryPath = '';

    /**
     * The level of sub-directories to store cache files. Defaults to 0.
     * 
     * This will separate string key into hierachical directory. This will be
     * helpful when using hashKey and manual browsing.
     * @var integer
     */
    public $directoryLevel = 0;

    /**
     * Whether to hash the key or not when the key is already string.
     * 
     * @var boolean
     */
    public $hashKey = false;

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
     * Checks whether a specified key exists in the cache.
     * 
     * This can be faster than getting the value from the cache if the data is big.
     * Note that this method does not check whether the dependency associated
     * with the cached data, if there is any, has changed. So a call to [[get]]
     * may return false while exists returns true.
     * @param mixed $key a key identifying the cached value. This can be a simple string or
     * a complex data structure consisting of factors representing the key.
     * @return boolean true if a value exists in cache, false if the value is not in the cache or expired.
     */
    public function exists($key) {
        $key = $this->buildKey($key);
        $expires = $this->getObjectExpirationTime($cacheKey);
        return $expires !== false;
    }

    /**
     * Builds a normalized cache key from a given key.
     *
     * This will baypass if the we don't want to normalize the key.
     *
     * @param mixed $key the key to be normalized
     * @return string the generated cache key
     */
    public function buildKey($key) {
        if (!is_string($key) || $this->hashKey) {
            return parent::buildKey($key);
        }
        return $this->keyPrefix . $key;
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
            if (($expires = $this->getObjectExpirationTime($cacheKey)) !== false
                    && $expires > time()) {
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
        return $this->deleteObject($cacheKey);
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
                if (!$expiredOnly || $expiredOnly && ($expires = $this->getObjectExpirationTime($cacheKey))
                        !== false &&
                        $expires > $time) {
                    $this->deleteObject($cacheKey);
                }
            }
        }
    }

    /**
     * List cache keys.
     * @return array array of keys.
     */
    private function listCacheKeys() {
        try {
            $objects = $this->_client->getIterator('ListObjects',
                    [
                'Bucket' => $this->bucket,
                'Prefix' => $this->directoryPath,
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
        if ($this->directoryLevel > 0) {
            $base = $this->directoryPath;
            for ($i = 0; $i < $this->directoryLevel; ++$i) {
                if (($prefix = substr($key, $i + $i, 2)) !== false) {
                    $base .= self::DIRECTORY_SEPARATOR . $prefix;
                }
            }
            return (!empty($base) ? $base . self::DIRECTORY_SEPARATOR : '') . $key . $this->cacheFileSuffix;
        } else {
            return (!empty($this->directoryPath) ? $this->directoryPath . self::DIRECTORY_SEPARATOR : '') . $key . $this->cacheFileSuffix;
        }
    }

    /**
     * Delete object in the S3
     * @param string $cacheKey the cache key.
     * @return boolean whether the deletion succeed.
     */
    private function deleteObject($cacheKey) {
        try {
            $return = $this->_client->deleteObject(array(
                'Bucket' => $this->bucket,
                'Key' => $cacheKey,
            ));
            return $return['DeleteMarker'] == 'true';
        } catch (\Aws\S3\Exception\NoSuchKeyException $exc) {
            return false;
        }
    }

    /**
     * Get object expiration time.
     * @param string $cacheKey get the cache key.
     * @return integer|boolean the expiration time, false if not found.
     */
    private function getObjectExpirationTime($cacheKey) {
        try {
            $result = $this->_client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $cacheKey,
            ]);
            return strtotime($result['Expires']);
        } catch (\Aws\S3\Exception\NoSuchKeyException $exc) {
            return false;
        }
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
