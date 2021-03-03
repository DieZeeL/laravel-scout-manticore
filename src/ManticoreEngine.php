<?php

namespace Diezeel\ManticoreScout;

use Foolz\SphinxQL\Helper;
use Foolz\SphinxQL\SphinxQL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine as AbstractEngine;
use Laravel\Scout\Searchable;
use ManticoreSearch\Laravel\Facade;
use ManticoreSearch\Laravel\ManticoreSearchable;

class ManticoreEngine extends AbstractEngine
{

    /**
     * @var \ManticoreSearch\Client $manticore
     */
    protected $manticore;

    /**
     * @var array
     */
    protected $whereIns = [];

    public function __construct()
    {
        $this->manticore = Facade::connection();
    }

    /**
     * Update the given model in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $models->each(function ($model) {
            if (!empty($searchableData = $model->toSearchableArray())) {
                $index = $this->manticore->index($model->searchableAs());
                $index->replaceDocument($searchableData,(int)$model->getScoutKey());
            }
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models *
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }
        $models->each(function ($model) {
            $index = $this->manticore->index($model->searchableAs());
            $index->deleteDocument((int)$model->getScoutKey());
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        /**
         * @var Searchable $model
         */
//        $model = $builder->model;
//        $index = $this->manticore->index($model->searchableAs());
//        $query = $index->search($builder->query)

        return $builder->get();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param int $perPage
     * @param int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder)->limit($perPage * ($page - 1), $perPage)
            ->execute();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Builder $builder
     * @param mixed $results
     * @param Model|Searchable $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results->count() === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results->fetchAllAssoc())->pluck('id')->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder, $objectIds
        )->filter(function (/** @var Searchable $model */ $model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function (/** @var Searchable $model */ $model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     * @return Collection
     */
    public function mapIds($results)
    {
        return collect($results->fetchAllAssoc())->pluck('id')->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        $res = (new Helper($this->sphinx->getConnection()))->showMeta()->execute();
        $assoc = $res->fetchAllAssoc();
        $totalCount = $results->count();
        foreach ($assoc as $item => $value) {
            if ($value["Variable_name"] == "total_found") {
                $totalCount = $value["Value"];
            }
        }

        return $totalCount;
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param Model $model
     * @return void
     */
    public function flush($model)
    {
        if (isset($model->isRT)) { // Only RT indexes support truncate
            $index = $model->searchableAs();
            $res = (new Helper($this->sphinx->getConnection()))->truncateRtIndex($index)->execute();
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @return SphinxQL
     */
    protected function performSearch(Builder $builder)
    {
        /**
         * @var Searchable $model
         */
        $model = $builder->model;
        $index = $this->manticore->index($model->searchableAs());
        $query = $index->search($builder->query);

        foreach ($builder->wheres as $clause => $filters) {
            $query->filter($clause, 'eq', $filters);
        }

        $query = $this->sphinx
            ->select('*', SphinxQL::expr('WEIGHT() AS weight'))
            ->from($index)
            ->match('*', SphinxQL::expr('"' . $builder->query . '"/1'))
            ->limit($builder->limit??20);

        foreach ($builder->wheres as $clause => $filters) {
            $query->where($clause, '=', $filters);
        }

        foreach ($this->whereIns as $whereIn) {
            $query->where(key($whereIn), 'IN', $whereIn[key($whereIn)]);
        }

        if ($builder->callback) {
            call_user_func(
                $builder->callback,
                $query
            );
        }

        if (empty($builder->orders)) {
            $query->orderBy('weight', 'DESC');
        } else {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        }

        return $query;
    }

    /**
     * @param string $attribute
     * @param array $arrayIn
     */
    public function addWhereIn(string $attribute, array $arrayIn)
    {
        $this->whereIns[] = array($attribute => $arrayIn);
    }
}