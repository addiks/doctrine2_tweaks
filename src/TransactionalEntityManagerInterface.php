<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages entities for persistence via ORM.
 *
 * This entity-manager poses an alternative to doctrine's own entity-manager. In contrast to doctrine's entity-manager,
 * this entity-manager never closes. Instead, on rollback it rolls the managed part of all managed entities back to the
 * point of when the transaction was created.
 *
 * It does this by managing not only one UnitOfWork, but a stack of UnitOfWork-instances. There is one UnitOfWork per
 * open transaction plus the root-UnitOfWork. Each UnitOfWork in this stack contains the state of managed entities from
 * the time when the next transaction started. The top UnitOfWork on the stack is always the one currently used. When a
 * transaction begins, the topmost UnitOfWork is cloned and the clone put on top of the stack becoming the new current
 * UnitOfWork.
 *
 * When a transaction get's committed, the secont-topmost UnitOfWork get's removed from the stack, replaced by the
 * current and topmost UnitOfWork. There is also an alternative method to commit called "commitAndDetachNewEntities"
 * that also detaches all entities that were not known at the beginning of the transaction, which may be what you want
 * to do for every iteration in batch-processing in order to prevent having an enourmous amount of entities managed.
 *
 * When a transaction get's rolled back, the topmost UnitOfWork get's discarded and it's previous UnitOfWork (which
 * still contains the state of the entities of  when the transaction begun becomes the new topmost and current one.
 *
 * There is also an alternative method for rolling back a transaction called "rollbackEntities" that rolls back the
 * managed part of the state of all managed entities using the UnitOfWork that still contains the state of the managed
 * entities when the transaction begun (resulting in an expensive rollback!).
 *
 * The process described in the paragraph above allows for a meaningful workflow using transactions. If a process fails
 * and causes a rollback, not only the state in the database get's rolled back, but also the state of all managed
 * entities. This allows for the executed and failed process to be re-tried again or to continue with the next process
 * using the same entity-manager. This can be done because the state of the runtime is known, it is the same as at the
 * beginning of the transaction. This follows the meaning of a "rollback" => The return from a faulty state into a well-
 * known state. If correctly used, this entity-manager could save some developers that care deeply about transactions a
 * big headache.
 */
interface TransactionalEntityManagerInterface extends EntityManagerInterface
{

    /**
     * Commit's the current UnitOfWork.
     *
     * Also detaches all entities that were not known at the beginning of this transaction.
     * Useful for batch-processing.
     */
    public function commitAndDetachNewEntities();

    /**
     * Rolls back the current transaction.
     * Discards the current UnitOfWork and reestablishes the previous UnitOfWork as the new current UnitOfWork.
     *
     * Also rolls back the state of all managed entities to the state of the beginning of the transaction.
     */
    public function rollbackEntities();

}
