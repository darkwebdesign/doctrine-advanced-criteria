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
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\EntityRepository as DoctrineEntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;

/**
 * Enables advanced criteria on the Doctrine entity repository.
 *
 * @author Raymond Schouten
 *
 * @todo relations
 */
class EntityRepository extends DoctrineEntityRepository
{
    /** @var string */
    protected $alias = '';

    /** @var array */
    protected $defaultOrderBy = array();

    /**
     * Constructor.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $entityManager
     * @param \Doctrine\ORM\Mapping\ClassMetadata $class
     */
    public function __construct(ObjectManager $entityManager, ClassMetadata $class)
    {
        parent::__construct($entityManager, $class);

        $this->initAlias();
    }

    /**
     * Initializes entity alias.
     */
    protected function initAlias()
    {
        if ($this->alias === '') {
            $this->alias = strtolower(substr(strrchr($this->getClassName(), '\\'), 1));
        }
    }

    /**
     * Finds all entities in the repository.
     *
     * @return array
     */
    public function findAll()
    {
        return $this->findBy(array());
    }

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
        $queryBuilder = $this->createQueryBuilder($this->alias);

        $this->handleCriteria($queryBuilder, $criteria);
        $this->handleOrderBy($queryBuilder, $orderBy);
        $this->addLimitAndOffset($queryBuilder, $limit, $offset);

//        var_dump($queryBuilder->getQuery()->getSQL());

        return $queryBuilder->getQuery()->getResult();
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
        $queryBuilder = $this
            ->createQueryBuilder($this->alias)
            ->select('COUNT(' . $this->alias . ')');

        $this->handleCriteria($queryBuilder, $criteria);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
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
     * Handles criteria.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param array $criteria
     *
     * @throws \Doctrine\ORM\ORMException
     */
    protected function handleCriteria(QueryBuilder $queryBuilder, array $criteria)
    {
        foreach ($criteria as $field => $fieldCriteria) {
            if ($this->getClassMetadata()->hasField($field) || $this->getClassMetadata()->hasAssociation($field)) {
                $this->handleFieldCriteria($queryBuilder, $this->alias . '.' . $field, $fieldCriteria);
            } else {
                throw ORMException::unrecognizedField($field);
            }
        }
    }

    /**
     * Handles field criteria.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param string $field
     * @param mixed $fieldCriteria
     */
    protected function handleFieldCriteria(QueryBuilder $queryBuilder, $field, $fieldCriteria)
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
            $this->validateOperator($operator, $value);
            $this->addWhere($queryBuilder, $field, $operator, $value);
        }
    }

    /**
     * Checks if the field criteria contains advanced criteria.
     *
     * @param mixed $fieldCriteria
     *
     * @return bool
     */
    protected function hasFieldAdvancedCriteria($fieldCriteria)
    {
        return is_array($fieldCriteria) && array_keys($fieldCriteria) !== range(0, count($fieldCriteria) - 1);
    }

    /**
     * Validates operator based upon the value.
     *
     * @param string $operator
     * @param mixed $value
     *
     * @throws \Doctrine\ORM\ORMException
     */
    protected function validateOperator($operator, $value)
    {
        static $typeOperators = array(
            'boolean' => array('=', '!=', '<>'),
            'integer' => array('=', '!=', '<>', '>', '<', '<=', '>='),
            'double' => array('=', '!=', '<>', '>', '<', '<=', '>='),
            'string' => array('=', '!=', '<>', '>', '<', '<=', '>=', 'LIKE', 'NOT LIKE'),
            'array' => array('IN', 'NOT IN'),
            'object' => array('=', '!=', '<>'),
            'NULL' => array('IS', 'IS NOT'),
        );

        if (!array_key_exists(gettype($value), $typeOperators)) {
            throw new ORMException(sprintf('Criteria type "%s" is not supported.', gettype($value)));
        }

        if (!in_array($operator, $typeOperators[gettype($value)], true)) {
            throw new ORMException(
                sprintf('Operator "%s" is not supported for type "%s".', $operator, gettype($value))
            );
        }
    }

    /**
     * Adds criteria to query builder.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param string $field
     * @param string $operator
     * @param mixed $value
     */
    protected function addWhere(QueryBuilder $queryBuilder, $field, $operator, $value)
    {
        $parameter = 'parameter_' . md5(serialize(implode('/', array($field, $operator, $value))));
        if (is_null($value)) {
            $queryBuilder->andWhere(sprintf('%s %s NULL', $field, $operator));
        } elseif (is_array($value)) {
            $queryBuilder->andWhere(sprintf('%s %s (:%s)', $field, $operator, $parameter));
            $queryBuilder->setParameter($parameter, $value);
        } else {
            $queryBuilder->andWhere(sprintf('%s %s :%s', $field, $operator, $parameter));
            $queryBuilder->setParameter($parameter, $value);
        }
    }

    /**
     * Handles order by.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param array|null $orderBy
     *
     * @throws \Doctrine\ORM\ORMException
     */
    protected function handleOrderBy(QueryBuilder $queryBuilder, array $orderBy = null)
    {
        if (is_null($orderBy)) {
            $orderBy = $this->defaultOrderBy;
        }
        foreach ((array) $orderBy as $field => $order) {
            if ($this->getClassMetadata()->hasField($field) || $this->getClassMetadata()->hasAssociation($field)) {
                $this->addOrderBy($queryBuilder, $this->alias . '.' . $field, $order);
            } else {
                throw ORMException::unrecognizedField($field);
            }
        }
    }

    /**
     * Adds order-by to query builder.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param string $field
     * @param mixed $order
     */
    protected function addOrderBy(QueryBuilder $queryBuilder, $field, $order)
    {
        $queryBuilder->addOrderBy($field, $order);
    }

    /**
     * Adds limit and offset to query builder.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param int|null $limit
     * @param int|null $offset
     */
    protected function addLimitAndOffset(
        QueryBuilder $queryBuilder,
        $limit = null,
        $offset = null
    ) {
        $queryBuilder->setMaxResults($limit);
        $queryBuilder->setFirstResult($offset);
    }
}
