<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\UnitOfWork;

use Addiks\DoctrineTweaks\UnitOfWork\UnitOfWorkFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

class UnitOfWorkFactory implements UnitOfWorkFactoryInterface
{

    /**
     * {@inheritDoc}
     */
    public function createUnitOfWork(EntityManagerInterface $entityManager)
    {
        return new UnitOfWork($entityManager);
    }

    /**
     * {@inheritDoc}
     */
    public function cloneUnitOfWork(UnitOfWork $unitOfWork)
    {
        /* @var $newUnitOfWork UnitOfWork */
        $newUnitOfWork = clone $unitOfWork;

        return $newUnitOfWork;
    }

}
