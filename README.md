Doctrine2-Tweaks
===================================

[![Build Status](https://travis-ci.org/addiks/doctrine2_tweaks.svg?branch=master)](https://travis-ci.org/addiks/doctrine2_tweaks)

This repository represents a collection of alternative components with tweaks and/or changed behaviour for the
doctrine2-project.


# Transactional Entity-Manager:

**Addiks\DoctrineTweaks\TransactionalEntityManager** (Alternative for [Doctrine\ORM\EntityManager](https://github.com/doctrine/doctrine2/blob/master/lib/Doctrine/ORM/EntityManager.php))

Manages entities for persistence via ORM.

Only use this implementation of the entity-manager if you know what you are doing. If someone told you to use this
because "it is better", make sure to understand what the differences are between this entity-manager and the original
entity-manager shipped in the doctrine ORM and what the implications are. This may cause errors and/or bugs if it is
directly replacing doctrine's original entity-manager for third-party-code that is written with the original in mind.

This entity-manager poses an alternative to doctrine's own entity-manager. In contrast to doctrine's entity-manager,
this entity-manager never closes. Instead, on rollback it rolls the managed part of all managed entities back to the
point of when the transaction was created.

It does this by managing not only one UnitOfWork, but a stack of UnitOfWork-instances. There is one UnitOfWork per
open transaction plus the root-UnitOfWork. Each UnitOfWork in this stack contains the state of managed entities from
the time when the next transaction started. The top UnitOfWork on the stack is always the one currently used. When a
transaction begins, the topmost UnitOfWork is cloned and the clone put on top of the stack becoming the new current
UnitOfWork. When a transaction get's committed, the secont-topmost UnitOfWork get's removed from the stack, replaced
by the current and topmost UnitOfWork (resulting in a cheap commit). When a transaction get's rolled back, the
topmost UnitOfWork get's discarded and it's previous UnitOfWork (which still contains the state of the entities of
when the transaction begun becomes the new topmost and current UnitOfWork. A rollback also rolls back the managed
part of the state of all managed entities using the UnitOfWork that still contains the state of the managed entities
when the transaction begun (resulting in an expensive rollback!).

The process described in the paragraph above allows for a meaningful workflow using transactions. If a process fails
and causes a rollback, not only the state in the database get's roled back, but also the state of all managed
entities. This allows for the executed and failed process to be re-tried again or to continue with the next process
using the same entity-manager. This can be done because the state of the runtime is known, it is the same as at the
beginning of the transaction. This follows the meaning of a "rollback" => The return from a faulty state into a well-
known state. If correctly used, this entity-manager could save some developers that care deeply about transactions a
big headache.
