<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\Hydrator;

use Addiks\DoctrineTweaks\Hydrator\HydratorFactoryInterface;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Internal\Hydration\SingleScalarHydrator;
use Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator;

class HydratorFactory implements HydratorFactoryInterface
{

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(
        Configuration $configuration,
        EntityManagerInterface $entityManager
    ) {
        $this->configuration = $configuration;
        $this->entityManager = $entityManager;
    }

    public function newHydrator($hydrationMode)
    {
        /* @var $hydrator AbstractHydrator */
        $hydrator = null;

        /* @var $configuration Configuration */
        $configuration = $this->configuration;

        /* @var $entityManager EntityManagerInterface */
        $entityManager = $this->entityManager;

        /* @var $hydratorClassMap array */
        $hydratorClassMap = [
            Query::HYDRATE_OBJECT        => ObjectHydrator::class,
            Query::HYDRATE_ARRAY         => ArrayHydrator::class,
            Query::HYDRATE_SCALAR        => ScalarHydrator::class,
            Query::HYDRATE_SINGLE_SCALAR => SingleScalarHydrator::class,
            Query::HYDRATE_SIMPLEOBJECT  => SimpleObjectHydrator::class,
        ];

        /* @var $hydratorClass string */
        $hydratorClass = null;

        if (isset($hydratorClassMap[$hydrationMode])) {
            $hydratorClass = $hydratorClassMap[$hydrationMode];

        } else {
            $hydratorClass = $configuration->getCustomHydrationMode($hydrationMode);
        }

        if (!is_null($hydratorClass)) {
            $hydrator = new $hydratorClass($entityManager);

        } else {
            throw ORMException::invalidHydrationMode($hydrationMode);
        }

        return $hydrator;
    }

}
