<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;


/**
 * ElasticSearch Facade
 *
 * @author Rahmat Setiawan <setiawaneggy@gmail.com>
 *
 * @method static $this index(string $index = 'default')
 * @method static bool create(array $body)
 * @method static bool bulk(array $body)
 * @method static collect get()
 * @method static array all()
 * @method static void setSize()
 * @method static array toArray()
 * @method static $this limit(int $limit)
 * @method static bool delete(...$id)
 * @method static void info()
 * @method static string version()
 * @method static $this where(string $key, string $value)
 * @method static $this whereLike(string $key, string $value)
 * @method static Illuminate\Support\Collection lazy()
 * @method static bool exists(array $array)
 * @method static $this search(string $terms)
 *
 * @see \App\Services\ElasticsearchService
 */
class ES extends Facade{
    protected static function getFacadeAccessor(){
    return 'ES';
    }
}