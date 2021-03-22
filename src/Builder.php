<?php

namespace Diezeel\ManticoreScout;

use Illuminate\Container\Container;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder as ScoutBuilder;
use ManticoreSearch\Laravel\Facade;
use Manticoresearch\Query;
use Manticoresearch\Query\Range;

class Builder extends ScoutBuilder
{

    /** @var \Manticoresearch\Search */
    public $search;

    public function __construct($model, $query, $callback = null, $softDelete = false)
    {
        parent::__construct($model, $query, $callback, $softDelete);

        $this->within($model->searchableAs());
    }

    /**
     * All of the available clause operators.
     *
     * @var string[]
     */
    public $operators = [
        '=' => 'equals',
        '<' => 'lt',
        '>' => 'gt',
        '<=' => 'lte',
        '>=' => 'gte',
        '<>' => '<>',
        '!=' => 'ne',
        'between' => 'range',

        'in' => 'in',
        'equals' => 'equals',
        'lt' => 'lt',
        'gt' => 'gt',
        'lte' => 'lte',
        'gte' => 'gte',
        'range' => 'range',
    ];


    /**
     * Specify a custom index to perform this search on.
     *
     * @param  string  $index
     * @return \Laravel\Scout\Builder
     */
    public function within($index)
    {
        $this->index = $index;
        $idx = Facade::connection()->index($this->index);
        $this->search = $idx->search($this->query);
        return $this;
    }

    /**
     * Add a constraint to the search query.
     *
     * @param  string  $field
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return $this
     */
    public function where($field, $operator = null, $value = null)
    {
        if ($field instanceof Query) {
            $this->search->filter($field);
            return $this;
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        switch ($operator) {
            case 'ne':
                $this->search->notFilter($field, 'equals', $value);
                break;
            case '<>':
                $this->search->filter(new Range($field, [
                    'ge' => $value,
                    'le' => $value
                ]));
                break;
            default:
                $this->search->filter($field, $operator, $value);
        }

        return $this;
    }

    /**
     * @param  string  $field
     * @param  mixed  $values
     */
    public function whereNot($field, $value)
    {
        $this->search->notFilter($field, 'equals', $value);
        return $this;
    }

    /**
     * @param  string  $field
     * @param  array  $values
     */
    public function whereIn($field, array $values)
    {
        $this->search->filter(new Query\In($field, $values));
        return $this;
    }

    /**
     * @param  string  $field
     * @param  array  $values
     */
    public function whereNotIn($field, array $values)
    {
        $this->search->notFilter(new Query\In($field, $values));
        return $this;
    }

    /**
     * @param  string  $field
     * @param  mixed  $values
     */
    public function whereNull($field)
    {
        $this->search->filter($field, '');

        return $this;
    }

    /**
     * @param  string  $field
     * @param  mixed  $values
     */
    public function whereNotNull($field)
    {
        $this->search->notFilter($field, '');

        return $this;
    }

    /**
     * @param $field
     * @param  mixed  $from
     * @param  mixed  $to
     * @param  string  $lte
     * @param  string  $gte
     * @return $this
     */
    public function whereBetween($field, $from = null, $to = null, $lte = 'lte', $gte = 'gte')
    {
        if (is_array($from)) {
            if (count($from) >= 2) {
                [$from, $to] = [...array_values($from)];
            } else {
                $from = reset($from);
            }
        }
        if ($from !== null) {
            $range[$gte] = $from;
        }
        if ($to !== null) {
            $range[$lte] = $to;
        }
        if (!empty($range)) {
            $this->search->filter(new Range($field, $range));
        }
        return $this;
    }


    /**
     * Include soft deleted records in the results.
     *
     * @return $this
     */
    public function withTrashed()
    {
//        unset($this->wheres['__soft_deleted']);
        return $this;
    }

    /**
     * Include only soft deleted records in the results.
     *
     * @return $this
     */
    public function onlyTrashed()
    {
//        return tap($this->withTrashed(), function () {
//            $this->wheres['__soft_deleted'] = 1;
//        });
        return $this;
    }

    /**
     * Set the "limit" for the search query.
     *
     * @param  int  $limit
     * @return $this
     */
    public function limit($limit)
    {
        $this->search->limit($limit);

        return $this;
    }

    /**
     * Set the "limit" for the search query.
     *
     * @param  int  $limit
     * @return $this
     */
    public function take($limit)
    {
        return $this->limit($limit);
    }

    /**
     * Add an "order" for the search query.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->search->sort($column, $direction);

        return $this;
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $this->model->newCollection($engine->map(
            $this,
            $rawResults = $engine->paginate($this, $perPage, $page),
            $this->model
        )->all());

        $total = $engine->getTotalCount($rawResults);

        $hasMorePages = ($perPage * $page) < $engine->getTotalCount($rawResults);

        $paginator = Container::getInstance()->makeWith(Paginator::class, [
            'items' => $results,
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ])->hasMorePagesWhen($hasMorePages);

        return $paginator->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $this->model->newCollection($engine->map(
            $this,
            $rawResults = $engine->paginate($this, $perPage, $page),
            $this->model
        )->all());

        $paginator = Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $results,
            'total' => $engine->getTotalCount($rawResults),
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ]);

        return $paginator->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a simple paginator with raw data.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateRaw($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $engine->paginate($this, $perPage, $page);

        $paginator = Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $results,
            'total' => $engine->getTotalCount($results),
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ]);

        return $paginator->appends('query', $this->query);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @param  string  $value
     * @param  string  $operator
     * @param  bool  $useDefault
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, 'equals'];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new \InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $this->operators[$operator]];
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     *
     * @param  string  $operator
     * @param  mixed  $value
     * @return bool
     */
    protected function invalidOperatorAndValue($operator, $value)
    {
        return is_null($value) && array_key_exists($operator, $this->operators);
    }

    /**
     * @param  string  $method
     * @param  array  $arguments
     * @return $this
     * @throws \Exception
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->search, $method)) {
            call_user_func_array([$this->search, $method], $arguments);
            return $this;
        }
        throw new \Exception(sprintf('The required method "%s" does not exist for %s', $method, get_class($this)));
    }
}
