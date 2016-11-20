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

use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;

class SampleEmbeddable
{

    public function __construct($bar, $baz)
    {
        $this->bar = (int)$bar;
        $this->baz = (bool)$baz;
    }

    /**
     * @var integer
     */
    public $bar;

    /**
     * @var boolean
     */
    public $baz;

    public static function loadMetadata(ClassMetadata $classMetadata)
    {
        $reflectionClass = new ReflectionClass(SampleEmbeddable::class);

        $classMetadata->isEmbeddedClass = true;

        foreach ([
            'bar' => 'integer',
            'baz' => 'boolean',
        ] as $name => $type) {
            $classMetadata->fieldMappings[$name] = [
                'declared' => SampleEmbeddable::class,
                'columnName' => $name,
                'fieldName' => $name,
                'type' => $type
            ];

            $classMetadata->reflFields[$name] = $reflectionClass->getProperty($name);
            $classMetadata->fieldNames[$name] = $name;
        }

    }
}
