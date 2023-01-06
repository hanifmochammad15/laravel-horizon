<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author Rahmat Setiawan <setiawaneggy@gmail.com>
 *
 * @method static $this channel(string $name)
 * @method static void info(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 *
 * @see \App\Services\LogService
 */
class Log extends Facade{
    protected static function getFacadeAccessor(){
    return 'Log';
    }
}