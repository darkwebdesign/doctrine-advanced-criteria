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
use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\EntityRepository as DoctrineEntityRepository;
use Doctrine\ORM\ORMException;

/**
 * Transforms between a boolean and a string.
 *
 * @author Raymond Schouten
 */
class EntityRepository extends DoctrineEntityRepository
{
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
        return array();
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
        return (int) 0;
    }

    /**
     * Adds support for magic finders.
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
                "Undefined method '%s'. The method name must start with either findBy, findOneBy or findCountBy!",
                $method
            ));
        }

        if (!isset($arguments[0])) {
            throw ORMException::findByRequiresParameter($method . $by);
        }

        $fieldName = lcfirst(Inflector::classify($by));

        if ($this->_class->hasField($fieldName) || $this->_class->hasAssociation($fieldName)) {
            return $this->$method(array($fieldName => $arguments[0]));
        } else {
            throw ORMException::invalidFindByCall($this->_entityName, $fieldName, $method . $by);
        }
    }
}