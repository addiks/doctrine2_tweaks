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

class SampleEntity
{

    public function __construct($foo, $bar, $baz, SampleEntity $parent = null)
    {
        static $id = 0;

        $this->id = $id++;

        $this->foo = (string)$foo;
        $this->bar = (int)$bar;
        $this->baz = (bool)$baz;

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
     * @var integer
     */
    public $bar;

    /**
     * @var boolean
     */
    public $baz;

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
        $classMetadata->reflFields = [
            'id'  => $reflectionClass->getProperty('id'),
            'foo' => $reflectionClass->getProperty('foo'),
            'bar' => $reflectionClass->getProperty('bar'),
            'baz' => $reflectionClass->getProperty('baz'),
            'parent' => $reflectionClass->getProperty('parent'),
            'children' => $reflectionClass->getProperty('children'),
        ];
        $classMetadata->fieldMappings = [
            'id' => [
                'columnName' => 'id',
                'fieldName' => 'id',
                'type' => 'integer'
            ],
            'foo' => [
                'columnName' => 'foo',
                'fieldName' => 'foo',
                'type' => 'string'
            ],
            'bar' => [
                'columnName' => 'bar',
                'fieldName' => 'bar',
                'type' => 'integer'
            ],
            'baz' => [
                'columnName' => 'baz',
                'fieldName' => 'baz',
                'type' => 'boolean'
            ],
            'parent' => [
                'columnName' => 'parent',
                'fieldName' => 'parent',
                'type' => 'integer',
                'nullable' => true
            ],
        ];
        $classMetadata->fieldNames = [
            'id' => 'id',
            'foo' => 'foo',
            'bar' => 'bar',
            'baz' => 'baz',
            'parent' => 'parent',
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
                'isOwningSide' => false,
            ],
        ];
    }

}
