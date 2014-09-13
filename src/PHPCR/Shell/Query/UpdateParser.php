<?php

namespace PHPCR\Shell\Query;

use PHPCR\Util\ValueConverter;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Util\QOM\Sql2ToQomQueryConverter;
use PHPCR\Util\QOM\Sql2Scanner;

/**
 * Parse "UPDATE" queries.
 *
 * This class extends the Sql2ToQomQueryConverter class and adapts it
 * to parse UPDATE queries.
 *
 * @author Daniel Leech <daniel@dantleech.com>
 */
class UpdateParser extends Sql2ToQomQueryConverter
{
    // Instruction for update operations to add array values
    const ARRAY_OPERATION_ADD = 'add';

    // Instruction for update operations to subsitute values
    const ARRAY_OPERATION_SUB = 'sub';

    public function parse($sql2)
    {
        $this->scanner = new Sql2Scanner($sql2);
        $this->sql2 = $sql2;
        return $this->doParse($sql2);
    }

    /**
     * Parse an "SQL2" UPDATE statement and construct a query builder
     * for selecting the rows and build a field => value mapping for the 
     * update.
     *
     * @param string $sql2
     *
     * @return array($query, $updates)
     */
    private function doParse($sql2)
    {
        $this->implicitSelectorName = null;
        $this->sql2 = $sql2;
        $source = null;
        $constraint = null;

        while ($this->scanner->lookupNextToken() !== '') {
            switch (strtoupper($this->scanner->lookupNextToken())) {
                case 'UPDATE':
                    $this->scanner->expectToken('UPDATE');
                    $source = $this->parseSource();
                    break;
                case 'SET':
                    $this->scanner->expectToken('SET');
                    $updates = $this->parseUpdates();
                    break;
                case 'WHERE':
                    $this->scanner->expectToken('WHERE');
                    $constraint = $this->parseConstraint();
                    break;
                default:
                    throw new InvalidQueryException('Expected end of query, got "' . $this->scanner->lookupNextToken() . '" in ' . $this->sql2);
            }
        }

        if (!$source instanceof SourceInterface) {
            throw new InvalidQueryException('Invalid query, source could not be determined: '.$sql2);
        }

        $query = $this->factory->createQuery($source, $constraint);

        $res = new \ArrayObject(array($query, $updates, $constraint));

        return $res;
    }

    /**
     * Parse the SET section of the query, returning
     * an array containing the property names (<selectorName.propertyName)
     * as keys and an array
     * 
     * array(
     *     'selector' => <selector>,
     *     'name' => <name>,
     *     '<value>' => <property value>,
     * )
     *
     * @return array
     */
    private function parseUpdates()
    {
        $updates = array();

        while (true) {
            $property = array(
                'array_op' => null,
                'selector' => null,
                'name' => null,
                'value' => null
            );

            // parse left side
            $selectorName = $this->scanner->fetchNextToken();
            $delimiter = $this->scanner->fetchNextToken();

            if ($delimiter !== '.') {
                $property['selector'] = null;
                $property['name'] = $selectorName;
                $next = $delimiter;
            } else {
                $property['selector'] = $selectorName;
                $property['name'] = $this->scanner->fetchNextToken();
                $next = $this->scanner->fetchNextToken();
            }

            // handle array operations
            if ($next === '[') {
                $next = $this->scanner->fetchNextToken();

                if ($next === ']') {
                    $property['array_op'] = self::ARRAY_OPERATION_ADD;
                } else {
                    if (is_numeric($next)) {
                        $property['array_op'] = self::ARRAY_OPERATION_SUB;;
                        $property['array_index'] = $next;
                    }
                    $this->scanner->expectToken(']');
                }

                // parse operator
                $this->scanner->expectToken('=');
            } else {
                if ($next !== '=') {
                    throw new InvalidQueryException(sprintf('Expected assignation operator "=", got "%s" in "%s"', $next, $this->sql2));
                }
            }

            // parse right side
            $property['value'] = $this->parseOperand();

            $updates[] = $property;

            $next = $this->scanner->lookupNextToken();

            if ($next == ',') {
                $next = $this->scanner->fetchNextToken();
            } elseif (strtolower($next) == 'where' || !$next) {
                break;
            }
        }

        return $updates;
    }

    private function isLiteral($token)
    {
        if (substr($token, 0, 1) === '\'') {
            return true;
        } elseif (substr($token, 0, 1) === '"') {
            return true;
        }

        return false;
    }

    private function parseOperand()
    {
        $token = strtoupper($this->scanner->lookupNextToken());

        if ('[' === $token) {
            return $this->parseArrayValue();
        }

        if ($this->scanner->lookupNextToken(1) == '(') {
            $functionData = $this->parseFunction();
            return new FunctionOperand($functionData[0], $functionData[1]);
        }

        if ($this->isLiteral($token)) {
            return $this->parseLiteralValue();
        }

        if ($token === 'NULL') {
            $this->scanner->fetchNextToken();
            return null;
        }

        $columnData = $this->scanColumn();
        return new ColumnOperand($columnData[0], $columnData[1]);
    }

    private function parseArrayValue()
    {
        $this->scanner->expectToken('[');

        $values = array();
        $next = true;
        while ($next && $next !== ']') {
            $values[] = $this->parseLiteralValue();

            $next = $this->scanner->fetchNextToken();
            if (!in_array($next, array(',', ']'))) {
                throw new InvalidQueryException(sprintf('Invalid array delimiter "%s" in "%s"', $next, $this->sql2));
            }
        }

        return $values;
    }

    private function parseFunction()
    {
        $functionName = $this->scanner->fetchNextToken();
        $this->scanner->expectToken('(');

        $args = array();
        $next = true;
        while ($next && $next !== ')') {
            $args[] = $this->parseOperand();

            $next = $this->scanner->fetchNextToken();
            if (!in_array($next, array(',', ')', ''))) {
                throw new InvalidQueryException(sprintf('Invalid function argument delimiter "%s" in "%s"', $next, $this->sql2));
            }
        }

        return array($functionName, $args);
    }
}
