<?php

namespace QueryFilters;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

abstract class QueryBuilderFilter
{
    protected EloquentBuilder|QueryBuilder $builder;

    protected array $operations = [
        'eq' => '=',
        'lte' => '<=',
        'gte' => '>=',
        'gt' => '>',
        'lt' => '<',
        'neq' => '<>',
        'in' => 'in',
        'empty' => 'empty',
    ];

    protected array $filterMapping = [

    ];

    protected array $defaultSort = [

    ];

    protected array $formatSort = [

    ];

    protected array $fastSearchFilter = [

    ];

    protected array $localFilters = [
        'filters' => null,
        'sortBy' => null,
        'sortDesc' => null,
    ];

    protected ?Request $request;

    public function __construct(?Request $request)
    {
        if ($request) {
            $this->request = $request;
        }
    }

    public function setLocalFilters(array $filters, $sortBy = null, $sortDesc = null): void
    {
        $this->localFilters['filters'] = $filters;
        $this->localFilters['sortBy'] = $sortBy;
        $this->localFilters['sortDesc'] = $sortDesc;
    }

    public function filters(): array|string|null
    {
        if ($this->request) {
            return $this->request->query();
        }

        return $this->localFilters;
    }

    public function applyFilters(EloquentBuilder|QueryBuilder $builder): EloquentBuilder|QueryBuilder
    {
        $this->builder = $builder;
        $filterMapping = $this->filterMapping;
        $fastSearchFilter = $this->fastSearchFilter;

        $filters = $this->filters()['filters'] ?? null;
        if (!$filters) {
            return $this->builder;
        }

        foreach ($filters as $name => $value) {
            if ($name === 'search') {
                if (!$this->fastSearchFilter) {
                    $this->builder = $builder->where(function ($query) use ($filterMapping, $value) {
                        foreach ($filterMapping as $fm => $fmConf) {
                            if (isset($fmConf['type'], $fmConf['map']) && (in_array($fmConf['type'], ['text', 'strict-text']) || $fm === 'id')) {
                                if ($fm === 'id') {
                                    $query->orWhere($fmConf['map'], '=', intval($value));
                                } else {
                                    $query->orWhereRaw('lower(' . $fmConf['map'] . ') like (?)', ['%' . mb_strtolower($value) . '%']);
                                }
                            }
                        }
                    });
                } else {
                    $this->builder = $builder->where(function ($query) use ($fastSearchFilter, $filterMapping, $value) {
                        foreach ($fastSearchFilter as $fastField) {
                            $field = $filterMapping[$fastField] ?? null;
                            if ($field && isset($field['type']) && isset($field['map'])) {
                                if ($fastField === 'id' && is_int($value)) {
                                    $query->orWhere($field['map'], '=', $value);
                                } elseif (in_array($field['type'], ['text', 'strict-text'])) {
                                    $query->orWhereRaw('lower(' . $field['map'] . '::text) like (?)', ['%' . mb_strtolower($value) . '%']);
                                } elseif (in_array($field['type'], ['bool', 'bool-raw']) && is_numeric($value)) {
                                    $query->orWhere($field['map'], '=', in_array($value, ['ДА', 'Да', 'да', 'дА', '1', 'true', 'TRUE', 1]));
                                } elseif (in_array($field['type'], ['number', 'percent', 'numeric']) && is_numeric($value)) {
                                    $query->orWhere($field['map'], '=', $value);
                                } elseif (in_array($field['type'], ['datetime', 'date']) && date_create($value)) {
                                    $query->orWhere($field['map'], '=', $value);
                                }
                            }
                        }
                    });
                }

                continue;
            }

            $values = is_array($value) ? $value : [$value];

            if (method_exists($this, $name)) {
                call_user_func_array([$this, $name], array_filter($values));
            }

            $type = $this->filterMapping[$name]['type'] ?? null;
            $mapping = $this->filterMapping[$name]['map'] ?? $name;
            $operation = $this->filterMapping[$name]['operation'] ?? '=';

            foreach ($values as $userOperation => $val) {
                $userOperation = array_key_exists($userOperation, $this->operations) ? $this->operations[$userOperation] : null;

                if ($type && $mapping) {
                    if ($userOperation === 'empty') {
                        $val = in_array($val, ['ДА', 'Да', 'да', 'дА', '1', 'true', 'TRUE', 1]);
                        if ($val) {
                            $this->builder = $builder->whereNull($mapping);
                        } else {
                            $this->builder = $builder->whereNotNull($mapping);
                        }

                        continue;
                    }

                    if ($type === 'array') {
                        $this->builder = $builder->whereIn($mapping, $values);
                    }

                    switch ($type) {
                        case 'strict-text':
                            if (isset($userOperation) && ($userOperation === 'in')) {
                                $val = trim($val);
                                $explodedArray = explode('","', mb_substr(trim($val), 1, mb_strlen($val) - 2));
                                array_walk($explodedArray, function (&$item1) {
                                    $item1 = stripslashes($item1);
                                });
                                $this->builder = $builder->whereIn($mapping, $explodedArray);
                            } else {
                                $this->builder = $builder->whereRaw('lower(' . $mapping . ') = (?)', [mb_strtolower($val)]);
                            }
                            break;
                        case 'text':
                            if (isset($operation) && ($operation === 'in')) {
                                $val = trim($val);
                                $explodedArray = explode(',', $val);
                                array_walk($explodedArray, function (&$item1) {
                                    $item1 = mb_strtolower(stripslashes($item1));
                                });
                                $this->builder = $builder->whereIn($mapping, $explodedArray);
                            } elseif (isset($operation) && ($operation === '=' || $operation === '<>')) {
                                $this->builder = $builder->whereRaw('lower(' . $mapping . ') ' . $operation . ' (?)', [mb_strtolower($val)]);
                            } else {
                                $this->builder = $builder->whereRaw('lower(' . $mapping . '::text) like (?)', ['%' . mb_strtolower($val) . '%']);
                            }
                            break;
                        case 'number':
                            if (isset($userOperation) && $userOperation === 'in') {
                                $explodedArray = explode(',', $val);
                                if (count($explodedArray) > 1) {
                                    $this->builder = $builder->whereIn($mapping, $explodedArray);
                                } else {
                                    $this->builder = $builder->where($mapping, '=', $val);
                                }
                            } else {
                                $this->builder = $builder->where($mapping, $userOperation ?? $operation, $val);
                            }
                            break;
                        case 'number-or':
                            if (isset($userOperation) && $userOperation === 'in') {
                                $explodedArray = explode(',', $val);
                                $this->builder = $builder->where(function ($query) use ($mapping, $explodedArray, $val) {
                                    $mappingArr = explode(',', $mapping);
                                    foreach ($mappingArr as $map) {
                                        if (count($explodedArray) > 1) {
                                            $this->builder = $query->orWhereIn($map, $explodedArray);
                                        } else {
                                            $this->builder = $query->orWhere($map, '=', $val);
                                        }
                                    }
                                });
                            } else {
                                $this->builder = $builder->where(function ($query) use ($mapping, $val, $userOperation, $operation) {
                                    $mappingArr = explode(',', $mapping);
                                    foreach ($mappingArr as $map) {
                                        $this->builder = $query->orWhere($map, $userOperation ?? $operation, $val);
                                    }
                                });
                            }
                            break;
                        case 'numeric':
                            $step = $this->filterMapping[$name]['step'] ?? 1;
                            if (isset($userOperation) && $userOperation === 'in') {
                                $explodedArray = explode(',', $val);
                                if (count($explodedArray) > 1) {
                                    $this->builder = $builder->whereIn($mapping, $explodedArray);
                                } else {
                                    $this->builder = $builder->where($mapping, '>=', $val);
                                    $this->builder = $builder->where($mapping, '<', $val + $step);
                                }
                            } else {
                                if (!isset($userOperation) || $userOperation === '=') {
                                    $this->builder = $builder->where($mapping, '>=', $val);
                                    $this->builder = $builder->where($mapping, '<', $val + $step);
                                } else {
                                    $this->builder = $builder->where($mapping, $userOperation ?? $operation, $val);
                                }
                            }
                            break;
                        case 'percent':
                            $step = 0.01;
                            if (isset($userOperation) && $userOperation === 'in') {
                                $explodedArray = explode(',', $val);
                                if (count($explodedArray) > 1) {
                                    $percentArray = [];
                                    foreach ($explodedArray as $eval) {
                                        $percentArray[] = $eval / 100;
                                    }
                                    $this->builder = $builder->whereIn($mapping, $percentArray);
                                } else {
                                    $this->builder = $builder->where($mapping, '>=', $val / 100);
                                    $this->builder = $builder->where($mapping, '<', $val / 100 + $step);
                                }
                            } else {
                                if (!isset($userOperation) || $userOperation === '=') {
                                    $this->builder = $builder->where($mapping, '>=', $val / 100);
                                    $this->builder = $builder->where($mapping, '<', $val / 100 + $step);
                                } else {
                                    $this->builder = $builder->where($mapping, $userOperation ?? $operation, $val / 100);
                                }
                            }
                            break;
                        case 'bool':
                            $val = in_array($val, ['ДА', 'Да', 'да', 'дА', '1', 'true', 'TRUE', 1]);
                            if (isset($userOperation) && $userOperation === '<>') {
                                $val = !$val;
                            }
                            $this->builder = $builder->where($mapping, '=', $val);
                            break;
                        case 'bool-raw':
                            $val = in_array($val, ['ДА', 'Да', 'да', 'дА', '1', 'true', 'TRUE', 1]);
                            if (isset($userOperation) && $userOperation === '<>') {
                                $val = !$val;
                            }
                            $this->builder = $builder->whereRaw($mapping . '= (?)', [$val]);
                            break;
                        case 'datetime':
                        case 'date':
                            if (isset($userOperation) && $userOperation === 'in') {
                                $explodedArray = explode(',', $val);
                                if (count($explodedArray) > 1) {
                                    $this->builder = $builder->whereIn($mapping, $explodedArray);
                                } else {
                                    $this->builder = $builder->where($mapping, '=', $val);
                                }
                            } else {
                                if (!isset($userOperation)) {
                                    if ($type === 'datetime') {
                                        $this->builder = $builder->where($mapping, $operation, $val);
                                    } else {
                                        $this->builder = $builder->whereDate($mapping, $operation, $val);
                                    }
                                } else {
                                    $this->builder = $builder->where($mapping, $userOperation, $val);
                                }
                            }

                            break;
                    }
                }
            }
        }

        return $this->builder;
    }

    public function applySort(EloquentBuilder|QueryBuilder $builder): EloquentBuilder|QueryBuilder
    {
        $this->builder = $builder;
        $sortBy = $this->filters()['sortBy'] ?? null;
        $sortDesc = $this->filters()['sortDesc'] ?? null;
        if (!$sortBy) {
            $sortBy = $this->defaultSort;
        } else {
            $defaultDirection = (!is_array($sortDesc)) ? ((isset($sortDesc) && $sortDesc == 'true') ? 'DESC' : 'ASC') : null;
        }

        $sortBy = !is_array($sortBy) ? [$sortBy] : $sortBy;

        foreach ($sortBy as $idx => $s) {
            if (!$sortDesc) {
                $direction = 'ASC';
            } else {
                if (is_array($sortDesc)) {
                    $direction = isset($sortDesc[$idx]) && $sortDesc[$idx] == 'true' ? 'DESC' : 'ASC';
                } else {
                    $direction = $sortDesc == 'true' ? 'DESC' : 'ASC';
                }
            }

            if (method_exists($this, $s) || isset($this->filterMapping[$s])) {
                if (isset($this->filterMapping[$s])) {
                    if (isset($this->filterMapping[$s]['sortMap'])) {
                        $sortMapArr = explode(',', $this->filterMapping[$s]['sortMap']);
                        foreach ($sortMapArr as $sortMap) {
                            $this->builder = $this->formatOrderBy($sortMap, $direction, $builder);
                        }

                    } else {
                        $this->builder = $this->formatOrderBy($this->filterMapping[$s]['map'] ?? $s, $direction, $builder);
                    }
                } else {
                    $this->builder = $this->formatOrderBy($s, $direction, $builder);
                }
            }
        }

        foreach ($this->defaultSort as $fieldName => $direction) {
            if (in_array($fieldName, $sortBy)) {
                continue;

            }

            $this->builder = $this->formatOrderBy($fieldName, $defaultDirection ?? $direction, $builder);
        }

        return $this->builder;
    }

    protected function formatOrderBy($fieldName, $direction, EloquentBuilder|QueryBuilder $builder): EloquentBuilder|QueryBuilder
    {
        $formatSort = $this->formatSort[$fieldName] ?? 'default';

        switch ($formatSort) {
            case 'date':
                $builder->orderByRaw($fieldName . '::date ' . $direction);
                break;
            case 'default':
                $builder->orderBy($fieldName, $direction);
                break;
        }

        return $builder;
    }

    public function applyFiltersAndSorting(EloquentBuilder|QueryBuilder $builder): EloquentBuilder|QueryBuilder
    {
        $this->builder = $this->applyFilters($builder);
        $this->builder = $this->applySort($builder);

        return $this->builder;
    }
}
