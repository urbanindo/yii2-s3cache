<?php

use Aws\S3\S3Client;
use UrbanIndo\Yii2\S3Cache\Cache;

class CacheTest extends TestCase
{
    const BUCKET_NAME = 'cache-bucket';
    
    const PREFIX = 'prefix/';

    const ACCESS_KEY = 'minio_access_key';

    const SECRET_KEY = 'minio_secret_key';
    
    /**
     * @var S3Client
     */
    private $_client;

    /**
     * @return \yii\caching\Cache
     */
    private function getCache() {
        return Yii::$app->cache;
    }
    
    public function setUp() {
        Yii::$app->set('cache', [
            'class' => Cache::class,
            'bucket' => self::BUCKET_NAME,
            'directoryPath' => self::PREFIX,
            'config' => [
                'version' => '2006-03-01',
                'credentials' => [
                    'key' => self::ACCESS_KEY,
                    'secret' => self::SECRET_KEY,
                ],
                'use_path_style_endpoint' => true,
                'region' => 'us-east-1',
                'endpoint' => 'http://127.0.0.1:9000'
            ]
        ]);
        $this->_client = S3Client::factory([
            'version' => '2006-03-01',
            'credentials' => [
                'key' => self::ACCESS_KEY,
                'secret' => self::SECRET_KEY,
            ],
            'region' => 'us-east-1',
            'use_path_style_endpoint' => true,
            'endpoint' => 'http://127.0.0.1:9000'
        ]);
        if (!$this->_client->doesBucketExist(self::BUCKET_NAME)) {
            $this->_client->createBucket(['Bucket' => self::BUCKET_NAME]);
        }
        $objects = $this->_client->getIterator('ListObjects', array('Bucket' => self::BUCKET_NAME));
        
        foreach ($objects as $object) {
            $this->_client->deleteObject([
                'Bucket' => self::BUCKET_NAME,
                'Key'    => $object['Key'],
            ]);
        }
        $this->_client->putObject([
            'Bucket' => self::BUCKET_NAME,
            'Key'    => 'my-key',
            'Body'   => 'this is the body!'
        ]);
    }
    
    private function listObjects() {
        $objects = $this->_client->getIterator('ListObjects', array('Bucket' => self::BUCKET_NAME));
        return array_map(function($object) { return $object['Key'];}, iterator_to_array($objects));
    }
    
    private function getContent($key) {
        $result = $this->_client->getObject([
            'Bucket' => self::BUCKET_NAME,
            'Key'    => $key,
        ]);
        return (string) $result['Body'];
    }

    public function testSet() {
        $key = 'value1';
        $this->assertEquals(false, $this->getCache()->get($key));
        $this->getCache()->set($key, 'TEMP', 2592000);
    }
}
