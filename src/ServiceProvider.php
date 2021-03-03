<?php

namespace Diezeel\ManticoreScout;

use Illuminate\Support\ServiceProvider as Provider;
use Laravel\Scout\EngineManager;

class ServiceProvider extends Provider
{
    public function boot()
    {
        resolve(EngineManager::class)->extend('manticoresearch', function ($app) {
            return new ManticoreEngine();
        });

//        $this->app->extend(Builder::class, function ($app){
//            return new
//        });
//        Builder::macro('whereIn', function (string $attribute, array $arrayIn) {
//            $this->engine()->addWhereIn($attribute, $arrayIn);
//            return $this;
//        });
    }
}
