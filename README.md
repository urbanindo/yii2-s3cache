# yii2-s3cache
File caching component for Yii2 using AWS Simple Storage Service

[![Latest Stable Version](https://poser.pugx.org/urbanindo/yii2-s3cache/v/stable.svg)](https://packagist.org/packages/urbanindo/yii2-s3cache)
[![Total Downloads](https://poser.pugx.org/urbanindo/yii2-s3cache/downloads.svg)](https://packagist.org/packages/urbanindo/yii2-s3cache)
[![Latest Unstable Version](https://poser.pugx.org/urbanindo/yii2-s3cache/v/unstable.svg)](https://packagist.org/packages/urbanindo/yii2-s3cache)
[![Coverage Status](https://coveralls.io/repos/github/urbanindo/yii2-s3cache/badge.svg)](https://coveralls.io/github/urbanindo/yii2-s3cache)

This is intended for large and long-lived objects.

## Requirement

* PHP >= 5.5
* AWS SDK >= 3.28.0

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist urbanindo/yii2-s3cache "*"
```

or add

```
"urbanindo/yii2-s3cache": "*"
```

to the require section of your `composer.json` file.

## Setting Up

Add the component in the configuration.

```php
'components' => [
    's3Cache' => [
        'class' => 'UrbanIndo\Yii2\S3Cache\Cache',
        'bucket' => 'mybucket',
        'cachePrefix' => '123456',
        'config' => [
            'key' => 'AKIA1234567890123456',
            'secret' => '1234567890123456789012345678901234567890',
            'region' => 'ap-southeast-1',
        ],
    ]
]
```

## Usage

This is similar like regular [data caching](http://www.yiiframework.com/doc-2.0/guide-caching-data.html).

```php
$cache = Yii::$app->get('s3Cache');
// try retrieving $data from cache
$data = $cache->get($key);

if ($data === false) {

    // $data is not found in cache, calculate it from scratch

    // store $data in cache so that it can be retrieved next time
    $cache->set($key, $data);
}

// $data is available here
```
