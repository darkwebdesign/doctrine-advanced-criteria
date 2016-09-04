<?php
/**
 * Copyright (c) 2016 DarkWeb Design
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace DarkWebDesign\DoctrineAdvancedCriteria\ORM;

use BadMethodCallException;
use DarkWebDesign\DoctrineAdvancedCriteria\ORM\ORMException;
use DateTime;
use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\EntityRepository as DoctrineEntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

/**
 * Enables advanced criteria on the Doctrine entity repository.
 *
 * @author Raymond Schouten
 */
class EntityRepository extends DoctrineEntityRepository
{
    /** @var string */
    private $rootAlias;

    /** @var int */
    private $aliasIndex;

    /** @var array */
    private $innerJoins;

    /** @var \Doctrine\ORM\QueryBuilder */
    private $queryBuilder;

    /**
     * Finds a single entity by a set of criteria.
     *
     * @param array $criteria
     * @param array|null $orderBy
     *
     * @return object|null
     */
    public function findOneBy(array $criteria, array $orderBy = null)
    {
        if ($entities = $this->findBy($criteria, $orderBy, 1, 0)) {
            return reset($entities);
        }

        return null;
    }

    /**
     * Finds entities by a set of criteria.
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param int|null $limit
     * @param int|null $offset
     *
     * @return array
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        $this->resetFind();

        $this->rootAlias = $this->generateAlias();

        $this->queryBuilder = $this->createQueryBuilder($this->rootAlias);

        $this->handleCriteria($criteria);
        $this->handleOrderBy($orderBy);

        $this->queryBuilder->setMaxResults($limit);
        $this->queryBuilder->setFirstResult($offset);

        return $this->queryBuilder->getQuery()->getResult();
    }

    /**
     * Finds entity count in the repository.
     *
     * @return int
     */
    public function findCountAll()
    {
        return $this->findCountBy(array());
    }

    /**
     * Finds entity count by a set of criteria.
     *
     * @param array $criteria
     *
     * @return int
     */
    public function findCountBy(array $criteria)
    {
        $this->resetFind();

        $this->rootAlias = $this->generateAlias();

        $this->queryBuilder = $this->createQueryBuilder($this->rootAlias);
        $this->queryBuilder->select($this->queryBuilder->expr()->countDistinct($this->rootAlias));

        $this->handleCriteria($criteria);

        return (int) $this->queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Adds support for magic findBy*, findOneBy* and findCountBy* methods.
     *
     * @param string $method
     * @param array $arguments
     *
     * @throws \BadMethodCallException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array|object|null
     */
    public function __call($method, $arguments)
    {
        if (0 === strpos($method, 'findBy')) {
            $by = substr($method, 6);
            $method = 'findBy';
        } elseif (0 === strpos($method, 'findOneBy')) {
            $by = substr($method, 9);
            $method = 'findOneBy';
        } elseif (0 === strpos($method, 'findCountBy')) {
            $by = substr($method, 11);
            $method = 'findCountBy';
        } else {
            throw new BadMethodCallException(sprintf(
                "Undefined method '%s'. The method name must start with either findBy, findOneBy or findCountBy.",
                $method
            ));
        }

        if (!isset($arguments[0])) {
            throw ORMException::findByRequiresParameter($method . $by);
        }

        $fieldName = lcfirst(Inflector::classify($by));

        if ($this->getClassMetadata()->hasField($fieldName) || $this->getClassMetadata()->hasAssociation($fieldName)) {
            return $this->$method(array($fieldName => $arguments[0]));
        } else {
            throw ORMException::invalidFindByCall($this->getEntityName(), $fieldName, $method . $by);
        }
    }

    /**
     * Resets internal state for find* methods.
     */
    private function resetFind()
    {
        $this->aliasIndex = 0;
        $this->innerJoins = array();
    }

    /**
     * Handles criteria.
     *
     * @param array $criteria
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function handleCriteria(array $criteria)
    {
        foreach ($criteria as $field => $fieldCriteria) {
            list($field, $alias, $classMetadata) = $this->handleFieldAssociations($field);
            if ($classMetadata->hasField($field) || $classMetadata->hasAssociation($field)) {
                $this->handleFieldCriteria($alias . '.' . $field, $fieldCriteria);
            } else {
                throw ORMException::unknownField($classMetadata->getName(), $field);
            }
        }
    }

    /**
     * Handles order-by.
     *
     * @param array|null $orderBy
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function handleOrderBy(array $orderBy = null)
    {
        foreach ((array) $orderBy as $field => $order) {
            list($field, $alias, $classMetadata) = $this->handleFieldAssociations($field);
            if ($classMetadata->hasField($field) || $classMetadata->hasAssociation($field)) {
                $this->addOrderBy($alias . '.' . $field, $order);
            } else {
                throw ORMException::unknownField($classMetadata->getName(), $field);
            }
        }
    }

    /**
     * Handles field associations.
     *
     * @param string $field
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    private function handleFieldAssociations($field)
    {
        $alias = $this->rootAlias;
        $classMetadata = $this->getClassMetadata();

        if (strpos($field, '.') !== false) {
            $associations = explode('.', $field);
            $field = array_pop($associations);
            $path = '';

            foreach ($associations as $association) {
                if ($classMetadata->hasAssociation($association)) {
                    $path = ltrim($path . '.' . $association, '.');
                    if (!isset($this->innerJoins[$path])) {
                        $this->innerJoins[$path] = $this->generateAlias();
                        $this->addInnerJoin($alias . '.' . $association, $this->innerJoins[$path]);
                    }
                    $alias = $this->innerJoins[$path];
                    $className = $classMetadata->getAssociationTargetClass($association);
                    $classMetadata = $this->getEntityManager()->getClassMetadata($className);
                } else {
                    throw ORMException::unknownAssociation($classMetadata->getName(), $association);
                }
            }
        }

        return array($field, $alias, $classMetadata);
    }

    /**
     * Handles field criteria.
     *
     * @param string $field
     * @param mixed $fieldCriteria
     */
    private function handleFieldCriteria($field, $fieldCriteria)
    {
        if (!$this->hasFieldAdvancedCriteria($fieldCriteria)) {
            if (is_null($fieldCriteria)) {
                $operator = 'IS';
            } elseif (is_array($fieldCriteria)) {
                $operator = 'IN';
            } else {
                $operator = '=';
            }
            $fieldCriteria = array($operator => $fieldCriteria);
        }

        $fieldCriteria = array_change_key_case($fieldCriteria, CASE_UPPER);

        foreach ($fieldCriteria as $operator => $value) {
            $this->validateOperator($operator);
            $this->validateOperatorValue($operator, $value);
            $this->addWhere($field, $operator, $value);
        }
    }

    /**
     * Checks if field criteria contains advanced criteria.
     *
     * @param mixed $fieldCriteria
     *
     * @return bool
     */
    private function hasFieldAdvancedCriteria($fieldCriteria)
    {
        return is_array($fieldCriteria) && array_keys($fieldCriteria) !== range(0, count($fieldCriteria) - 1);
    }

    /**
     * Validates operator.
     *
     * @param string $operator
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function validateOperator($operator)
    {
        static $operators = array(
            '=', '!=', '<>', '<', '>', '<=', '>=',
            'IS', 'IS NOT',
            'LIKE', 'NOT LIKE',
            'IN', 'NOT IN',
            'BETWEEN', 'NOT BETWEEN',
            'INSTANCEOF',
        );

        if (!in_array($operator, $operators)) {
            throw ORMException::invalidOperator($operator);
        }
    }

    /**
     * Validates operator value.
     *
     * @param string $operator
     * @param mixed $value
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function validateOperatorValue($operator, $value)
    {
        static $typeOperators = array(
            'boolean'         => array('=', '!=', '<>', 'IS', 'IS NOT'),
            'integer'         => array('=', '!=', '<>', '<', '>', '<=', '>='),
            'double'          => array('=', '!=', '<>', '<', '>', '<=', '>='),
            'string'          => array('=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'),
            'string/entity'   => array('=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'INSTANCEOF'),
            'array'           => array('IN', 'NOT IN'),
            'array/range'     => array('IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'),
            'object/datetime' => array('=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'),
            'object/entity'   => array('=', '!=', '<>'),
            'object/string'   => array('=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'),
            'null'            => array('IS', 'IS NOT'),
        );

        $type = $this->determineType($value);

        if (!array_key_exists($type, $typeOperators) || !in_array($operator, $typeOperators[$type])) {
            throw ORMException::invalidOperatorValue($operator);
        }
    }

    /**
     * Determines the value type.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function determineType($value)
    {
        $type = strtolower(gettype($value));

        if ('string' === $type && $this->isEntity($value)) {
            $type = 'string/entity';
        } elseif ('object' === $type) {
            if ($value instanceof DateTime) {
                $type = 'object/datetime';
            } elseif ($this->isEntity(get_class($value))) {
                $type = 'object/entity';
            } elseif (is_callable(array($value, '__toString'))) {
                $type = 'object/string';
            }
        } elseif ('array' === $type && $this->isRange($value)) {
            $type = 'array/range';
        }

        return $type;
    }

    /**
     * Checks if class name is a Doctrine entity.
     *
     * @param $className
     *
     * @return bool
     */
    private function isEntity($className)
    {
        return $this->getEntityManager()->getMetadataFactory()->hasMetadataFor($className);
    }

    /**
     * Checks if array can be used as a range array.
     *
     * @param array $array
     *
     * @return bool
     */
    private function isRange(array $array)
    {
        static $rangeTypes = array(
            'boolean',
            'integer',
            'double',
            'string',
            'string/entity',
            'object/datetime',
            'object/string',
        );

        if (2 !== count($array)) {
            return false;
        }

        $startType = $this->determineType($array[0]);
        $endType = $this->determineType($array[1]);

        return in_array($startType, $rangeTypes) && in_array($endType, $rangeTypes);
    }

    /**
     * Adds inner-join to query builder.
     *
     * @param string $field
     * @param string $alias
     */
    private function addInnerJoin($field, $alias)
    {
        $this->queryBuilder->innerJoin($field, $alias);
    }

    /**
     * Adds where-condition to query builder.
     *
     * @param string $field
     * @param string $operator
     * @param mixed $value
     */
    private function addWhere($field, $operator, $value)
    {
        if (in_array($operator, array('IN', 'NOT IN'))) {
            $parameter = $this->generateParameter($field, $operator, $value);
            $this->queryBuilder->andWhere(sprintf('%s %s (:%s)', $field, $operator, $parameter));
            $this->queryBuilder->setParameter($parameter, $value);
        } elseif (in_array($operator, array('BETWEEN', 'NOT BETWEEN'))) {
            $parameter1 = $this->generateParameter($field, $operator, $value[0]);
            $parameter2 = $this->generateParameter($field, $operator, $value[1]);
            $this->queryBuilder->andWhere(sprintf('%s %s :%s AND :%s', $field, $operator, $parameter1, $parameter2));
            $this->queryBuilder->setParameter($parameter1, $value[0]);
            $this->queryBuilder->setParameter($parameter2, $value[1]);
        } else {
            $parameter = $this->generateParameter($field, $operator, $value);
            $this->queryBuilder->andWhere(sprintf('%s %s :%s', $field, $operator, $parameter));
            $this->queryBuilder->setParameter($parameter, $value);
        }
    }

    /**
     * Adds order-by to query builder.
     *
     * @param string $field
     * @param mixed $order
     */
    private function addOrderBy($field, $order)
    {
        $this->queryBuilder->addOrderBy($field, $order);
    }

    /**
     * Generates unique table alias.
     *
     * @return string
     */
    private function generateAlias()
    {
        return '_t' . $this->aliasIndex++;
    }

    /**
     * Generates parameter based on the field, operator and value.
     *
     * @param string $field
     * @param string $operator
     * @param mixed $value
     *
     * @return string
     */
    private function generateParameter($field, $operator, $value)
    {
        return 'parameter_' . md5(serialize(array($field, $operator, $value)));
    }
}
