<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\Proxy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Proxy\ProxyFactory;

interface ProxyFactoryFactoryInterface
{

    /**
     * Creates a proxy factory from given entity-manager and configuration.
     *
     * @param  EntityManagerInterface $entityManager
     * @param  Configuration          $configuration
     * @return ProxyFactory
     */
    public function createProxyFactory(EntityManagerInterface $entityManager, Configuration $configuration);

}
