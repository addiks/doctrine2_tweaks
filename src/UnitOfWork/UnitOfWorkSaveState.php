<?php
/**
 * Copyright (C) 2019 Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 *
 * @license GPL-3.0
 *
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\DoctrineTweaks\UnitOfWork;

use Doctrine\ORM\UnitOfWork;
use Webmozart\Assert\Assert;
use Addiks\DoctrineTweaks\UnitOfWork\Restorable;

/**
 * This represents a save-state of a doctrine unit-of-work instance.
 * Using this, you can always revert the state of a unit-of-work instance back to a previous state.
 *
 * This can be useful for bulk-operations:
 * Create a save-state at the beginning of the bulk-process and then restore it after every flush.
 * That way the unit-of-work will never contain objects that were already flushed,
 * saving memory and (execution-)time.
 *
 * Remember to also call "gc_collect_cycles()" when restoring to actually free the memory.
 *
 * Example:
 *
 *      $saveState = new UnitOfWorkSaveState($entityManager->getUnitOfWork());
 *
 *      foreach ($thingsToImport as $counter => $thing) {
 *
 *          $importer->import($thing); # Import a thing, add it to a collection or something
 *
 *          if ($counter % 100 === 0) {
 *              $entityManager->flush(); # Send new entities to database
 *              $saveState->restore(); # Remove imported entities from unit-of-work
 *              gc_collect_cycles(); # Clean up memory
 *          }
 *      }
 *
 * #################################################################
 * ### WARNING: This is considered EXPERIMENTAL (at the moment)! ###
 * #################################################################
 */
final class UnitOfWorkSaveState implements Restorable
{

    /**
     * A reference to the unit-of-work that actually performs the actions.
     * This one will be reverted back (modified) on restoration.
     *
     * @var UnitOfWork
     */
    private $targetUnitOfWork;

    /**
     * This is a (deep) clone of the target unit-of-work that contains the saved state.
     * It's data will be used to overwrite the data of the (current) target unit-of-work.
     *
     * @var UnitOfWork
     */
    private $unitOfWorkInOriginalState;

    public function __construct(UnitOfWork $unitOfWork)
    {
        Assert::null($this->previousUnitOfWork);
        $this->targetUnitOfWork = $unitOfWork;
        $this->unitOfWorkInOriginalState = clone $unitOfWork;
    }

    public function restore(): void
    {
        /** @var array<string, array<string, mixed>> $originalIdentityMap */
        $originalIdentityMap = $this->unitOfWorkInOriginalState->getIdentityMap();

        /** @var array<string, array<string, mixed>> $currentIdentityMap */
        $currentIdentityMap = $this->targetUnitOfWork->getIdentityMap();

        foreach ($currentIdentityMap as $entityName => $identities) {
            /** @var array<string, mixed> $originalIdentities */
            $originalIdentities = $originalIdentityMap[$entityName] ?? [];

            foreach ($identities as $objectHash => $entity) {
                if (!isset($originalIdentities[$objectHash])) {
                    $this->targetUnitOfWork->removeFromIdentityMap($entity);
                    $this->targetUnitOfWork->setOriginalEntityData($entity, []);
                }
            }
        }

        foreach ($originalIdentityMap $entityName => $identities) {

            foreach ($identities as $objectHash => $entity) {
                $this->targetUnitOfWork->setOriginalEntityData(
                    $entity,
                    $this->unitOfWorkInOriginalState->getOriginalEntityData($entity)
                );
            }
        }
    }

}
