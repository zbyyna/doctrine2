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
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Tools\Export\Driver;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * ClassMetadata exporter for PHP code.
 *
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Jonathan Wage <jonwage@gmail.com>
 */
class PhpExporter extends AbstractExporter
{
    /**
     * @var string
     */
    protected $_extension = '.php';

    /**
     * {@inheritdoc}
     */
    public function exportClassMetadata(ClassMetadata $metadata)
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = null;
        $lines[] = 'use Doctrine\DBAL\Types\Type;';
        $lines[] = 'use Doctrine\ORM\Mapping\ClassMetadata;';
        $lines[] = 'use Doctrine\ORM\Mapping;';
        $lines[] = null;

        if ($metadata->isMappedSuperclass) {
            $lines[] = '$metadata->isMappedSuperclass = true;';
        }

        if ($metadata->inheritanceType) {
            $lines[] = '$metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_' . $this->_getInheritanceTypeString($metadata->inheritanceType) . ');';
        }

        if ($metadata->customRepositoryClassName) {
            $lines[] = "\$metadata->customRepositoryClassName = '" . $metadata->customRepositoryClassName . "';";
        }

        if ($metadata->table) {
            $lines[] = '$metadata->setPrimaryTable(' . $this->_varExport($metadata->table) . ');';
        }

        if ($metadata->discriminatorColumn) {
            $discrColumn = $metadata->discriminatorColumn;

            $lines[] = '$discrColumn = new Mapping\DiscriminatorColumnMetadata();';
            $lines[] = '$discrColumn->setTableName("' . $discrColumn->getTableName() . '");';
            $lines[] = '$discrColumn->setColumnName("' . $discrColumn->getColumnName() . '");';
            $lines[] = '$discrColumn->setColumnDefinition("' . $discrColumn->getColumnDefinition() . '");';
            $lines[] = '$discrColumn->setType(Type::getType("' . $discrColumn->getTypeName() . '"));';
            $lines[] = '$discrColumn->setLength(' . $discrColumn->getLength() . ');';
            $lines[] = '$discrColumn->setScale(' . $discrColumn->getScale() . ');';
            $lines[] = '$discrColumn->setPrecision(' . $discrColumn->getPrecision() . ');';
            $lines[] = '$discrColumn->setOptions(' . $this->_varExport($discrColumn->getOptions()) . ');';
            $lines[] = '$discrColumn->setNullable(' . $this->_varExport($discrColumn->isNullable()) . ');';
            $lines[] = '$discrColumn->setUnique(' . $this->_varExport($discrColumn->isUnique()) . ');';
            $lines[] = '$metadata->setDiscriminatorColumn($discrColumn);';
        }

        if ($metadata->discriminatorMap) {
            $lines[] = '$metadata->setDiscriminatorMap(' . $this->_varExport($metadata->discriminatorMap) . ');';
        }

        if ($metadata->changeTrackingPolicy) {
            $lines[] = '$metadata->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_' . $this->_getChangeTrackingPolicyString($metadata->changeTrackingPolicy) . ');';
        }

        if ($metadata->lifecycleCallbacks) {
            foreach ($metadata->lifecycleCallbacks as $event => $callbacks) {
                foreach ($callbacks as $callback) {
                    $lines[] = "\$metadata->addLifecycleCallback('$callback', '$event');";
                }
            }
        }

        foreach ($metadata->getProperties() as $property) {
            $lines[] = sprintf(
                '$metadata->addProperty("%s", Type::getType("%s"), %s);',
                $property->getName(),
                $property->getType()->getName(),
                $this->_varExport($property->getMapping())
            );
        }

        if ( ! $metadata->isIdentifierComposite && $generatorType = $this->_getIdGeneratorTypeString($metadata->generatorType)) {
            $lines[] = '$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_' . $generatorType . ');';
        }

        foreach ($metadata->associationMappings as $associationMapping) {
            $cascade = ['remove', 'persist', 'refresh', 'merge', 'detach'];
            foreach ($cascade as $key => $value) {
                if ( ! $associationMapping['isCascade'.ucfirst($value)]) {
                    unset($cascade[$key]);
                }
            }

            if (count($cascade) === 5) {
                $cascade = ['all'];
            }

            $method = null;
            $associationMappingArray = [
                'fieldName'    => $associationMapping['fieldName'],
                'targetEntity' => $associationMapping['targetEntity'],
                'cascade'      => $cascade,
            ];

            if (isset($associationMapping['fetch'])) {
                $associationMappingArray['fetch'] = $associationMapping['fetch'];
            }

            switch ($associationMapping['type'] ) {
                case ClassMetadata::ONE_TO_ONE:
                    $method = 'mapOneToOne';
                    $specificMappingArray = [
                        'mappedBy'      => $associationMapping['mappedBy'],
                        'inversedBy'    => $associationMapping['inversedBy'],
                        'joinColumns'   => $associationMapping['isOwningSide'] ? $associationMapping['joinColumns'] : [],
                        'orphanRemoval' => $associationMapping['orphanRemoval'],
                    ];
                    break;

                case ClassMetadata::MANY_TO_ONE:
            $method = 'mapManyToOne';
                    $specificMappingArray = [
                        'mappedBy'      =>$associationMapping['mappedBy'],
                        'inversedBy'    => $associationMapping['inversedBy'],
                        'joinColumns'   => $associationMapping['isOwningSide'] ? $associationMapping['joinColumns'] : [],
                        'orphanRemoval' => $associationMapping['orphanRemoval'],
                    ];
                    break;

                case ClassMetadata::ONE_TO_MANY:
                $method = 'mapOneToMany';
                $specificMappingArray = [];
                        $potentialAssociationMappingIndexes = [
                    'mappedBy',
                    'orphanRemoval',
                    'orderBy',
                ];
$oneToManyMappingArray = [];                foreach ($potentialAssociationMappingIndexes as $index) {
                    if (!isset($associationMapping[$index])) {
                        continue;
                        }

                        $specificMappingArray[$index] = $associationMapping[$index];
                    }
                break;

            case ClassMetadata::MANY_TO_MANY:
                $method = 'mapManyToMany';
                $specificMappingArray = [];
                        $potentialAssociationMappingIndexes = [
                    'mappedBy',
                    'joinTable',
                    'orderBy',
                ];$manyToManyMappingArray = [];
                foreach ($potentialAssociationMappingIndexes as $index) {
                    if (!isset($associationMapping[$index])) {
                        continue;
                        }

                        $specificMappingArray[$index] = $associationMapping[$index];
                    }
                break;
            }

            $associationMappingArray = array_merge($associationMappingArray, $specificMappingArray);

            $lines[] = '$metadata->' . $method . '(' . $this->_varExport($associationMappingArray) . ');';
        }

        return implode("\n", $lines);
    }

    /**
     * @param mixed $var
     *
     * @return string
     */
    protected function _varExport($var)
    {
        $export = var_export($var, true);
        $export = str_replace("\n", PHP_EOL . str_repeat(' ', 8), $export);
        $export = str_replace('  ', ' ', $export);
        $export = str_replace('array (', 'array(', $export);
        $export = str_replace('array( ', 'array(', $export);
        $export = str_replace(',)', ')', $export);
        $export = str_replace(', )', ')', $export);
        $export = str_replace('  ', ' ', $export);

        return $export;
    }
}
