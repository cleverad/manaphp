<?php
namespace ManaPHP\Mongodb\Model;

use ManaPHP\Component;
use ManaPHP\Di;
use ManaPHP\Model\CriteriaInterface;
use ManaPHP\Mongodb\Model\Criteria\Exception as CriteriaException;
use MongoDB\Driver\Command;

/**
 * Class ManaPHP\Mongodb\Model\Criteria
 *
 * @package ManaPHP\Mongodb\Model
 *
 * @property \ManaPHP\Paginator             $paginator
 * @property \ManaPHP\CacheInterface        $modelsCache
 * @property \ManaPHP\Http\RequestInterface $request
 */
class Criteria extends Component implements CriteriaInterface
{
    /**
     * @var array
     */
    protected $_projection;

    /**
     * @var array
     */
    protected $_aggregate = [];

    /**
     * @var string
     */
    protected $_modelName;

    /**
     * @var array
     */
    protected $_filters = [];

    /**
     * @var string
     */
    protected $_order;

    /**
     * @var string|callable
     */
    protected $_index;

    /**
     * @var int
     */
    protected $_limit;

    /**
     * @var int
     */
    protected $_offset;

    /**
     * @var bool
     */
    protected $_distinct;

    /**
     * @var int|array
     */
    protected $_cacheOptions;

    /**
     * @var array
     */
    protected $_group;

    /**
     * @var bool
     */
    protected $_forceUseMaster = false;

    /**
     * Criteria constructor.
     *
     * @param string       $modelName
     * @param string|array $fields
     */
    public function __construct($modelName, $fields = null)
    {
        $this->_modelName = $modelName;

        if ($fields !== null) {
            $this->select($fields);
        }
        $this->_dependencyInjector = Di::getDefault();
    }

    /**
     * Sets SELECT DISTINCT / SELECT ALL flag
     *
     * @param string $field
     *
     * @return array
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     */
    public function distinctField($field)
    {
        /**
         * @var \ManaPHP\ModelInterface $modelName
         */
        $modelName = $this->_modelName;
        $source = $modelName::getSource();

        /**
         * @var \ManaPHP\MongodbInterface $db
         */
        $db = $this->_dependencyInjector->getShared($modelName::getDb());

        $cmd = ['distinct' => $source, 'key' => $field];
        if (count($this->_filters) !== 0) {
            $cmd['query'] = ['$and' => $this->_filters];
        }

        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $cursor = $db->command(new Command($cmd));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        $r = $cursor->toArray()[0];
        if (!$r['ok']) {
            throw new CriteriaException('`:distinct` distinct for `:collection` collection failed `:code`: `:msg`',
                ['distinct' => $field, 'code' => $r['code'], 'msg' => $r['errmsg'], 'collection' => $source]);
        }

        return $r['values'];
    }

    /**
     * @param string|array $fields
     *
     * @return static
     */
    public function select($fields)
    {
        if (!is_array($fields)) {
            $fields = explode(',', str_replace(['[', ']', "\t", ' ', "\r", "\n"], '', $fields));
        }

        $this->_projection = array_fill_keys($fields, 1);

        return $this;
    }

    /**
     * @param array $expr
     *
     * @return static
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     */
    public function aggregate($expr)
    {
        foreach ($expr as $k => $v) {
            if (is_array($v)) {
                $this->_aggregate[$k] = $v;
                continue;
            }

            if (preg_match('#^(\w+)\((.*)\)$#', $v, $match) !== 1) {
                throw new CriteriaException('`:aggregate` aggregate is invalid.', ['aggregate' => $v]);
            }

            $accumulator = strtolower($match[1]);
            $operand = $match[2];
            if ($accumulator === 'count') {
                $this->_aggregate[$k] = ['$sum' => 1];
            } elseif ($accumulator === 'sum' || $accumulator === 'avg' || $accumulator === 'max' || $accumulator === 'min') {
                if (preg_match('#^[\w\.]+$#', $operand) === 1) {
                    $this->_aggregate[$k] = ['$' . $accumulator => '$' . $operand];
                } elseif (preg_match('#^([\w\.]+)\s*([\+\-\*/%])\s*([\w\.]+)$#', $operand, $match2) === 1) {
                    $operator_map = ['+' => '$add', '-' => '$subtract', '*' => '$multiply', '/' => '$divide', '%' => '$mod'];
                    $sub_operand = $operator_map[$match2[2]];
                    $sub_operand1 = is_numeric($match2[1]) ? (double)$match2[1] : ('$' . $match2[1]);
                    $sub_operand2 = is_numeric($match2[3]) ? (double)$match2[3] : ('$' . $match2[3]);
                    $this->_aggregate[$k] = ['$' . $accumulator => [$sub_operand => [$sub_operand1, $sub_operand2]]];
                } else {
                    throw new CriteriaException('unknown `:operand` operand of `:aggregate` aggregate', ['operand' => $operand, 'aggregate' => $v]);
                }
            } else {
                throw new CriteriaException('unknown `:accumulator` accumulator of `:aggregate` aggregate',
                    ['accumulator' => $accumulator, 'aggregate' => $v]);
            }
        }

        return $this;
    }

    /**
     * Appends a condition to the current conditions using a AND operator
     *
     *<code>
     *    $builder->andWhere('name = "Peter"');
     *    $builder->andWhere('name = :name: AND id > :id:', array('name' => 'Peter', 'id' => 100));
     *</code>
     *
     * @param string|array           $condition
     * @param int|float|string|array $bind
     *
     * @return static
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     */
    public function where($condition, $bind = [])
    {
        if ($condition === null) {
            return $this;
        }

        if (is_array($condition)) {
            /** @noinspection ForeachSourceInspection */
            foreach ($condition as $k => $v) {
                $this->where($k, $v);
            }
        } else {
            if (is_scalar($bind)) {
                if (preg_match('#^([\w\.]+)\s*([<>=!]*)$#', $condition, $matches) !== 1) {
                    throw new CriteriaException('unknown `:condition` condition', ['condition' => $condition]);
                }

                list(, $field, $operator) = $matches;
                if ($operator === '') {
                    $operator = '=';
                }

                $operator_map = ['=' => '$eq', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', '!=' => '$ne', '<>' => '$ne'];
                $this->_filters[] = [$field => [$operator_map[$operator] => $bind]];
            } else {
                $this->_filters[] = [$condition => $bind];
            }
        }

        return $this;
    }

    /**
     * Appends a BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->betweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $expr
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function betweenWhere($expr, $min, $max)
    {
        $this->_filters[] = [$expr => ['$gte' => $min, '$lt' => $max]];

        return $this;
    }

    /**
     * Appends a NOT BETWEEN condition to the current conditions
     *
     *<code>
     *    $builder->notBetweenWhere('price', 100.25, 200.50);
     *</code>
     *
     * @param string           $expr
     * @param int|float|string $min
     * @param int|float|string $max
     *
     * @return static
     */
    public function notBetweenWhere($expr, $min, $max)
    {
        $this->_filters[] = ['$or' => [[$expr => ['$lt' => $min]], [$expr => ['$gte' => $max]]]];

        return $this;
    }

    /**
     * Appends an IN condition to the current conditions
     *
     *<code>
     *    $builder->inWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string                           $expr
     * @param array|\ManaPHP\Db\QueryInterface $values
     *
     * @return static
     */
    public function inWhere($expr, $values)
    {
        $this->_filters[] = [$expr => ['$in' => $values]];

        return $this;
    }

    /**
     * Appends a NOT IN condition to the current conditions
     *
     *<code>
     *    $builder->notInWhere('id', [1, 2, 3]);
     *</code>
     *
     * @param string                           $expr
     * @param array|\ManaPHP\Db\QueryInterface $values
     *
     * @return static
     */
    public function notInWhere($expr, $values)
    {
        $this->_filters[] = [$expr => ['$nin' => $values]];

        return $this;
    }

    /**
     * @param string|array $expr
     * @param string       $like
     *
     * @return static
     */
    public function likeWhere($expr, $like)
    {
        $this->_filters[$expr] = ['$regex' => $like];

        return $this;
    }

    /**
     * Sets a ORDER BY condition clause
     *
     *<code>
     *    $builder->orderBy('Robots.name');
     *    $builder->orderBy(array('1', 'Robots.name'));
     *</code>
     *
     * @param string|array $orderBy
     *
     * @return static
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     */
    public function orderBy($orderBy)
    {
        if (is_string($orderBy)) {
            foreach (explode(',', $orderBy) as $item) {
                if (preg_match('#^\s*([\w\.]+)(\s+asc|\s+desc)?$#i', $item, $match) !== 1) {
                    throw new CriteriaException('unknown `:order` order by for `:model` model', ['order' => $orderBy, 'model' => $this->_modelName]);
                }
                $this->_order[$match[1]] = (!isset($match[2]) || strtoupper(ltrim($match[2])) === 'ASC') ? 1 : -1;
            }
        } else {
            /** @noinspection ForeachSourceInspection */
            foreach ($orderBy as $field => $value) {
                if ((is_int($value) && $value === SORT_ASC) || (is_string($value) && strtoupper($value) === 'ASC')) {
                    $this->_order[$field] = 1;
                } else {
                    $this->_order[$field] = -1;
                }
            }
        }

        return $this;
    }

    /**
     * Sets a LIMIT clause, optionally a offset clause
     *
     *<code>
     *    $builder->limit(100);
     *    $builder->limit(100, 20);
     *</code>
     *
     * @param int $limit
     * @param int $offset
     *
     * @return static
     */
    public function limit($limit, $offset = null)
    {
        if ($limit > 0) {
            $this->_limit = (int)$limit;
        }

        if ($offset > 0) {
            $this->_offset = (int)$offset;
        }

        return $this;
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return static
     */
    public function page($size, $page = null)
    {
        if ($page === null && $this->request->has('page')) {
            $page = $this->request->get('page', 'int');
        }

        $this->limit($size, $page ? ($page - 1) * $size : null);

        return $this;
    }

    /**
     * Sets a GROUP BY clause
     *
     *<code>
     *    $builder->groupBy(array('Robots.name'));
     *</code>
     *
     * @param string|array $groupBy
     *
     * @return static
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     */
    public function groupBy($groupBy)
    {
        if (is_string($groupBy)) {
            if (strpos($groupBy, '(') !== false) {
                if (preg_match('#^([\w\.]+)\((.*)\)$#', $groupBy, $match) === 1) {
                    $func = strtoupper($match[1]);
                    if ($func === 'SUBSTR') {
                        $parts = explode(',', $match[2]);

                        if ($parts[1] === '0') {
                            throw new CriteriaException('`:group` substr index is 1-based', ['group' => $groupBy]);
                        }
                        $this->_group[$parts[0]] = ['$substr' => ['$' . $parts[0], $parts[1] - 1, (int)$parts[2]]];
                    }
                } else {
                    throw new CriteriaException('`:group` group is not supported. ', ['group' => $groupBy]);
                }
            } else {
                foreach (explode(',', str_replace(' ', '', $groupBy)) as $field) {
                    $this->_group[$field] = '$' . $field;
                }
            }
        } else {
            $this->_group = $groupBy;
        }

        return $this;
    }

    /**
     * @param callable|string $indexBy
     *
     * @return static
     */
    public function indexBy($indexBy)
    {
        $this->_index = $indexBy;

        return $this;
    }

    /**
     * @param array|int $options
     *
     * @return static
     */
    public function cache($options)
    {
        $this->_cacheOptions = $options;

        return $this;
    }

    /**
     * @return array
     */
    protected function _execute()
    {
        /**
         * @var \ManaPHP\ModelInterface $modelName
         */
        $modelName = $this->_modelName;
        $source = $modelName::getSource();
        /**
         * @var \ManaPHP\MongodbInterface $db
         */
        $db = Di::getDefault()->getShared($modelName::getDb());
        if (count($this->_aggregate) === 0) {
            $options = [];

            if ($this->_projection !== null) {
                $options['projection'] = $this->_projection;
            }

            if ($this->_order !== null) {
                $options['sort'] = $this->_order;
            }

            if ($this->_offset !== null) {
                $options['skip'] = $this->_offset;
            }

            if ($this->_limit !== null) {
                $options['limit'] = $this->_limit;
            }

            return $db->query($source, $this->_filters ? ['$and' => $this->_filters] : [], $options, !$this->_forceUseMaster);
        } else {
            $pipeline = [];
            if (count($this->_filters) !== 0) {
                $pipeline[] = ['$match' => ['$and' => $this->_filters]];
            }

            $pipeline[] = ['$group' => ['_id' => $this->_group] + $this->_aggregate];

            if ($this->_order !== null) {
                $pipeline[] = ['$sort' => $this->_order];
            }

            if ($this->_offset !== null) {
                $pipeline[] = ['$skip' => $this->_offset];
            }

            if ($this->_limit !== null) {
                $pipeline[] = ['$limit' => $this->_limit];
            }

            $r = $db->pipeline($source, $pipeline);

            if ($this->_group === null) {
                return $r;
            }

            $rows = [];
            foreach ($r as $k => $v) {
                if ($v['_id'] !== null) {
                    $v += $v['_id'];
                }
                unset($v['_id']);
                $rows[$k] = $v;
            }

            return $rows;
        }
    }

    /**
     * @return array
     */
    public function execute()
    {
        if ($this->_cacheOptions !== null) {
            $cacheOptions = $this->_getCacheOptions();
            $data = $this->modelsCache->get($cacheOptions['key']);
            if ($data !== false) {
                return json_decode($data, true)['items'];
            }
        }

        $items = $this->_execute();

        if (isset($cacheOptions)) {
            $this->modelsCache->set($cacheOptions['key'], json_encode(['time' => date('Y-m-d H:i:s'), 'items' => $items]), $cacheOptions['ttl']);
        }

        return $items;
    }

    /**
     * @return int
     */
    protected function _getTotalRows()
    {
        $this->_limit = null;
        $this->_offset = null;
        $this->_order = null;
        $this->_aggregate['count'] = ['$sum' => 1];
        $r = $this->_execute();
        return $r[0]['count'];
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return \ManaPHP\PaginatorInterface
     * @throws \ManaPHP\Mongodb\Model\Criteria\Exception
     * @throws \ManaPHP\Paginator\Exception
     */
    public function paginate($size, $page = null)
    {
        if ($page === null && $this->request->has('page')) {
            $page = $this->request->get('page', 'int');
        }
        $this->page($size, $page);

        do {
            if ($this->_cacheOptions !== null) {
                $cacheOptions = $this->_getCacheOptions();

                if (($result = $this->modelsCache->get($cacheOptions['key'])) !== false) {
                    $result = json_decode($result, true);

                    $count = $result['count'];
                    $items = $result['items'];
                    break;
                }
            }

            $copy = clone $this;
            $items = $this->fetchAll();

            if ($this->_limit === null) {
                $count = count($items);
            } else {
                if (count($items) % $this->_limit === 0) {
                    $count = $copy->_getTotalRows();
                } else {
                    $count = $this->_offset + count($items);
                }
            }

            if (isset($cacheOptions)) {
                $this->modelsCache->set($cacheOptions['key'],
                    json_encode(['time' => date('Y-m-d H:i:s'), 'count' => $count, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $cacheOptions['ttl']);
            }

        } while (false);

        $this->paginator->items = $items;
        return $this->paginator->paginate($count, $size, $page);
    }

    /**
     *
     * @return array
     */
    protected function _getCacheOptions()
    {
        $cacheOptions = is_array($this->_cacheOptions) ? $this->_cacheOptions : ['ttl' => $this->_cacheOptions];

        if (!isset($cacheOptions['key'])) {
            $data = [];
            foreach (get_object_vars($this) as $k => $v) {
                if ($v !== null && !$v instanceof Component) {
                    $data[$k] = $v;
                }
            }
            $cacheOptions['key'] = md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $cacheOptions;
    }

    /**
     * @param bool $forceUseMaster
     *
     * @return static
     */
    public function forceUseMaster($forceUseMaster = true)
    {
        $this->_forceUseMaster = $forceUseMaster;

        return $this;
    }

    /**
     * @param bool $asModel
     *
     * @return array|\ManaPHP\ModelInterface|false
     */
    public function fetchOne($asModel = false)
    {
        $r = $this->limit(1)->fetchAll($asModel);
        return isset($r[0]) ? $r[0] : false;
    }

    /**
     * @param bool $asModel
     *
     * @return array|\ManaPHP\Mongodb\Model[]
     */
    public function fetchAll($asModel = false)
    {
        if (!$asModel && $this->_index === null) {
            return $this->execute();
        }

        $rows = [];
        $index = $this->_index;
        foreach ($this->execute() as $k => $document) {
            $value = $asModel ? new $this->_modelName($document) : $document;
            if ($index === null) {
                $rows[] = $value;
            } elseif (is_scalar($index)) {
                $rows[$document[$index]] = $value;
            } else {
                $rows[$index($document)] = $document;
            }
        }
        return $rows;
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->select(['_id'])->fetchOne() !== false;
    }

    public function delete()
    {
        /**
         * @var \ManaPHP\ModelInterface $modelName
         */
        $modelName = $this->_modelName;
        if (($db = $modelName::getDb($this)) === false) {
            throw new CriteriaException('`:model` model db sharding for update failed',
                ['model' => $modelName, 'context' => $this]);
        }

        if (($source = $modelName::getSource($this)) === false) {
            throw new CriteriaException('`:model` model table sharding for update failed',
                ['model' => $modelName, 'context' => $this]);
        }

        return $this->_dependencyInjector->getShared($db)->delete($source, $this->_filters ? ['$and' => $this->_filters] : []);
    }

    public function update($fieldValues)
    {
        /**
         * @var \ManaPHP\ModelInterface $modelName
         */
        $modelName = $this->_modelName;
        if (($db = $modelName::getDb($this)) === false) {
            throw new CriteriaException('`:model` model db sharding for update failed',
                ['model' => $modelName, 'context' => $this]);
        }

        if (($source = $modelName::getSource($this)) === false) {
            throw new CriteriaException('`:model` model table sharding for update failed',
                ['model' => $modelName, 'context' => $this]);
        }

        return $this->_dependencyInjector->getShared($db)->update($source, $fieldValues, $this->_filters ? ['$and' => $this->_filters] : []);
    }
}