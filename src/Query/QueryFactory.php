<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\Query;

use Addiks\DoctrineTweaks\Query\QueryFactoryInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class QueryFactory implements QueryFactoryInterface
{

    /**
     * {@inheritDoc}
     */
    public function createQuery(EntityManagerInterface $entityManager, $dql = '')
    {
        $query = new Query($entityManager);

        if (!empty($dql)) {
            $query->setDql($dql);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function createNativeQuery(EntityManagerInterface $entityManager, $sql, ResultSetMapping $rsm)
    {
        $query = new NativeQuery($entityManager);

        $query->setSql($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

}
