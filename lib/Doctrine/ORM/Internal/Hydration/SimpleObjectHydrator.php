<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */


namespace Doctrine\ORM\Internal\Hydration;

use \PDO;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;

class SimpleObjectHydrator extends AbstractHydrator
{
    const REFRESH_ENTITY = 'doctrine_refresh_entity';

    /**
     * @var ClassMetadata
     */
    private $class;

    protected function _hydrateAll()
    {
        $result = array();
        $cache = array();

        while ($row = $this->_stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->_hydrateRow($row, $cache, $result);
        }

        $this->_em->getUnitOfWork()->triggerEagerLoads();

        return $result;
    }

    protected function _prepare()
    {
        if (count($this->_rsm->aliasMap) == 1) {
            $this->class = $this->_em->getClassMetadata(current($this->_rsm->aliasMap));
        } else {
            throw new \RuntimeException("Cannot use SimpleObjectHydrator with a ResultSetMapping not containing exactly one object result.");
        }
        if ($this->_rsm->scalarMappings) {
            throw new \RuntimeException("Cannot use SimpleObjectHydrator with a ResultSetMapping that contains scalar mappings.");
        }
    }

    protected function _hydrateRow(array $sqlResult, array &$cache, array &$result)
    {
        $data = array();
        if ($this->class->inheritanceType == ClassMetadata::INHERITANCE_TYPE_NONE) {
            foreach ($sqlResult as $column => $value) {

                if (isset($this->_rsm->fieldMappings[$column])) {
                    $column = $this->_rsm->fieldMappings[$column];
                    $field = $this->class->fieldNames[$column];
                    if (isset($data[$field])) {
                        $data[$column] = $value;
                    } else {
                        $data[$field] = Type::getType($this->class->fieldMappings[$field]['type'])
                                ->convertToPHPValue($value, $this->_platform);
                    }
                } else {
                    $column = $this->_rsm->metaMappings[$column];
                    $data[$column] = $value;
                }
            }
            $entityName = $this->class->name;
        } else {
            $discrColumnName = $this->_platform->getSQLResultCasing($this->class->discriminatorColumn['name']);
            $entityName = $this->class->discriminatorMap[$sqlResult[$discrColumnName]];
            unset($sqlResult[$discrColumnName]);
            foreach ($sqlResult as $column => $value) {
                if (isset($this->_rsm->fieldMappings[$column])) {
                    $realColumnName = $this->_rsm->fieldMappings[$column];
                    $class = $this->_em->getClassMetadata($this->_rsm->declaringClasses[$column]);
                    if ($class->name == $entityName || is_subclass_of($entityName, $class->name)) {
                        $field = $class->fieldNames[$realColumnName];
                        if (isset($data[$field])) {
                            $data[$realColumnName] = $value;
                        } else {
                            $data[$field] = Type::getType($class->fieldMappings[$field]['type'])
                                    ->convertToPHPValue($value, $this->_platform);
                        }
                    }
                } else if (isset($this->_rsm->relationMap[$column])) {
                    if ($this->_rsm->relationMap[$column] == $entityName || is_subclass_of($entityName, $this->_rsm->relationMap[$column])) {
                        $data[$realColumnName] = $value;
                    }
                } else {
                    $column = $this->_rsm->metaMappings[$column];
                    $data[$realColumnName] = $value;
                }
            }
        }

        if (isset($this->_hints[self::REFRESH_ENTITY])) {
            $this->_hints[Query::HINT_REFRESH] = true;
            $id = array();
            if ($this->_class->isIdentifierComposite) {
                foreach ($this->_class->identifier as $fieldName) {
                    $id[$fieldName] = $data[$fieldName];
                }
            } else {
                $id = array($this->_class->identifier[0] => $data[$this->_class->identifier[0]]);
            }
            $this->_em->getUnitOfWork()->registerManaged($this->_hints[self::REFRESH_ENTITY], $id, $data);
        }

        $result[] = $this->_em->getUnitOfWork()->createEntity($entityName, $data, $this->_hints);
    }
}