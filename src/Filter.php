<?php

namespace BiginFilter;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use BiginFilter\Constants\References;
use BiginSupport\Traits\UserTimezoneTrait;

class Filter
{
    use UserTimezoneTrait;

    /**
     * @param array $data
     * @param array $conditions
     * @param null $builder
     * @return array
     */
    public function filtersSearchHelper(array $data, array $conditions, &$builder = null): array
    {
        return array_filter(
            array_map(function ($config, $keyword) use ($data, &$builder) {
                $searchKeyword = array_get($data, $keyword);
                $column = array_get($config, 'column');

                $this->applyFilter($searchKeyword, array_get($config, 'applyBeforeFilter', []));

                if (isset($searchKeyword) && $searchKeyword !== '') {
                    switch (array_get($config, 'inputType')) {
                        case References::DATA_TYPE_DATE_TIME_ZONE:
                            $searchKeyword = Carbon::createFromTimestamp(
                                strtotime($searchKeyword),
                                $this->getDefaultTimezone()
                            )->setTimezone(Config::get('app.timezone', 'UTC'));

                            if (array_get($config, 'is_end')) {
                                $searchKeyword->addHours(24);
                            }
                            break;
                        case References::DATA_TYPE_INTEGER:
                            $searchKeyword = (int)$searchKeyword;
                            break;
                        case References::DATA_TYPE_BOOLEAN:
                            $searchKeyword = filter_var($searchKeyword, FILTER_VALIDATE_BOOLEAN);
                            break;
                    }
                    $this->applyFilter($searchKeyword, array_get($config, 'applyAfterFilter', []));

                    if (
                        is_null($searchKeyword) ||
                        $searchKeyword === ''
                    ) {
                        return false;
                    }

                    $this->applyFilter($searchKeyword, array_get($config, 'callUserFunc', []), true);

                    $operator = array_get($config, 'operator', '=');

                    switch ($operator) {
                        case References::FILTER_OPERATOR_ILIKE:
                            $searchKeyword = "%{$searchKeyword}%";
                            break;
                        case References::FILTER_OPERATOR_IN:
                            if ($builder instanceof EloquentBuilder || $builder instanceof QueryBuilder) {
                                $builder->whereIn($column, is_array($searchKeyword) ? $searchKeyword : []);
                                return false;
                            }
                    }

                    if (is_array($column)) {
                        if ($builder instanceof EloquentBuilder || $builder instanceof QueryBuilder) {
                            $builder
                                ->where(function ($query) use ($column, $operator, $searchKeyword) {
                                    foreach ($column as $queryColumn) {
                                        $query->orWhere($queryColumn, $operator, $searchKeyword);
                                    }
                                });
                        }
                        return false;
                    }

                    return [$column, $operator, $searchKeyword];
                }
            }, $conditions, array_keys($conditions))
        );
    }

    /**
     * @param $searchKeyword
     * @param array $filters
     * @param bool $isCallable
     */
    public function applyFilter(&$searchKeyword, array $filters = [], bool $isCallable = false)
    {
        foreach ($filters as $instance) {
            if ($isCallable) {
                $searchKeyword = call_user_func($instance, $searchKeyword);
            } else {
                $instance = new $instance($searchKeyword);
                $searchKeyword = $instance instanceof ICallbackFilter ? $instance->getValue() : $instance->__toString();
            }
        }
    }

    /**
     * getSortString
     *
     * @param array $filters
     * @return string
     */
    public function getSortString(array $filters, array $options): string
    {
        $orderBy = array_get($filters, 'ascending') ? 'ASC' : 'DESC';
        $orderField = array_get($options, 'default', 'id');

        if (
        in_array(
            array_get($filters, 'orderBy', $orderField),
            array_get($options, 'columns')
        )
        ) {
            $orderField = array_get($filters, 'orderBy', $orderField);
        }

        return "{$orderField} {$orderBy}";
    }
}
