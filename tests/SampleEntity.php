<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\Tests;

use DateTime;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Addiks\DoctrineTweaks\Tests\SampleEmbeddable;

class SampleEntity
{

    public function __construct($foo, $bar, $baz, SampleEntity $parent = null)
    {
        static $id = 0;

        $this->id = $id++;

        $this->foo = (string)$foo;

        $this->embeddable = new SampleEmbeddable($bar, $baz);

        $this->parent = $parent;
        $this->children = new ArrayCollection();

        if (!is_null($parent)) {
            $parent->children->add($this);
        }
    }

    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $foo;

    /**
     * @var SampleEmbeddable
     */
    public $embeddable;

    /**
     * @var SampleEntity
     */
    public $parent;

    /**
     * @var Collection
     */
    public $children;

    public static function loadMetadata(ClassMetadata $classMetadata)
    {
        $reflectionClass = new ReflectionClass(SampleEntity::class);

        $classMetadata->identifier = ['id'];

        foreach ([
            'id' => 'integer',
            'foo' => 'string',
            'parent' => 'integer'
        ] as $name => $type) {
            $classMetadata->fieldMappings[$name] = [
                'columnName' => $name,
                'fieldName' => $name,
                'type' => $type
            ];

            $classMetadata->reflFields[$name] = $reflectionClass->getProperty($name);
            $classMetadata->fieldNames[$name] = $name;
        }

        $classMetadata->reflFields['children'] = $reflectionClass->getProperty('children');
        $classMetadata->fieldMappings['parent']['nullable'] = true;

        $classMetadata->reflFields['embeddable'] = $reflectionClass->getProperty('embeddable');
        $classMetadata->embeddedClasses['embeddable'] = [
            'class' => SampleEmbeddable::class,
            'columnPrefix'  => '',
            'declaredField' => null,
            'originalField' => null,
        ];

        $classMetadata->associationMappings = [
            'parent' => [
                'columnName' => 'parent',
                'fieldName' => 'parent',
                'type' => ClassMetadata::MANY_TO_ONE,
                'sourceEntity' => SampleEntity::class,
                'targetEntity' => SampleEntity::class,
                'isOwningSide' => true,
                'mappedBy' => 'children',
                'joinColumns' => [
                    'parent' => [
                        'name' => 'parent',
                        'referencedColumnName' => 'id',
                    ],
                ],
                'orphanRemoval' => false,
                'isCascadePersist' => true,
                'isCascadeDetach' => false,
            ],
            'children' => [
                'fieldName' => 'children',
                'type' => ClassMetadata::ONE_TO_MANY,
                'sourceEntity' => SampleEntity::class,
                'targetEntity' => SampleEntity::class,
                'inversedBy' => 'parent',
                'orphanRemoval' => false,
                'isCascadeRemove' => false,
                'isCascadePersist' => true,
                'isCascadeDetach' => false,
                'isOwningSide' => false,
            ],
        ];
    }

}
