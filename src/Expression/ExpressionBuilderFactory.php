<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\Expression;

use Addiks\DoctrineTweaks\Expression\ExpressionBuilderFactoryInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;

class ExpressionBuilderFactory implements ExpressionBuilderFactoryInterface
{

    /**
     * {@inheritDoc}
     */
    public function createQueryBuilder(EntityManagerInterface $entityManager)
    {
        return new QueryBuilder($entityManager);
    }

}
