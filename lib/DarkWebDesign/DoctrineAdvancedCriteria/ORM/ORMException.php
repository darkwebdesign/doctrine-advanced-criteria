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

use Doctrine\ORM\ORMException as DoctrineORMException;

/**
 * Base exception wrapper class for all ORM exceptions.
 *
 * @author Raymond Schouten
 */
class ORMException extends DoctrineORMException
{
    /**
     * Returns new "unknown field" exception. 
     * 
     * @param string $className
     * @param string $field
     * 
     * @return \Doctrine\ORM\ORMException
     */
    public static function unknownField($className, $field)
    {
        return new DoctrineORMException(sprintf('Unknown field: %s#%s', $className, $field));
    }

    /**
     * Returns new "unknown association" exception.
     *
     * @param string $className
     * @param string $association
     *
     * @return \Doctrine\ORM\ORMException
     */
    public static function unknownAssociation($className, $association)
    {
        return new DoctrineORMException(sprintf('Unknown association: %s#%s', $className, $association));
    }

    /**
     * Returns new "invalid operator" exception.
     *
     * @param string $operator
     *
     * @return \Doctrine\ORM\ORMException
     */
    public static function invalidOperator($operator)
    {
        return new DoctrineORMException(sprintf('Invalid operator: %s', $operator));
    }

    /**
     * Returns new "unrecognized operator value" exception.
     *
     * @param string $operator
     *
     * @return \Doctrine\ORM\ORMException
     */
    public static function invalidOperatorValue($operator)
    {
        return new DoctrineORMException(sprintf('Invalid value type specified for operator "%s".', $operator));
    }
}
