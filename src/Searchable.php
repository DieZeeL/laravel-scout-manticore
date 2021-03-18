<?php

namespace Diezeel\ManticoreScout;

use Laravel\Scout\Searchable as ScoutSearchable;
use Manticoresearch\Search;

trait Searchable
{
    use ScoutSearchable;


    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return Search
     */
    public static function search($query = '', $callback = null)
    {
//        $model = new static();
//        $index = Facade::connection()->index($model->searchableAs());
//        return $index->search($query);

        return app(Builder::class, [
            'model' => new static,
            'query' => $query,
            'callback' => $callback,
            'softDelete'=> static::usesSoftDelete() && config('scout.soft_delete', false),
        ]);
    }
}
