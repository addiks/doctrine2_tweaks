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

use BadMethodCallException;
use InvalidArgumentException;
use ReflectionProperty;
use Doctrine\Common\EventManager;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\TransactionRequiredException;
use Addiks\DoctrineTweaks\Expression\ExpressionBuilderFactoryInterface;
use Addiks\DoctrineTweaks\Expression\ExpressionBuilderFactory;
use Addiks\DoctrineTweaks\Query\QueryFactoryInterface;
use Addiks\DoctrineTweaks\Query\QueryFactory;
use Addiks\DoctrineTweaks\Hydrator\HydratorFactoryInterface;
use Addiks\DoctrineTweaks\Hydrator\HydratorFactory;
use Addiks\DoctrineTweaks\UnitOfWork\UnitOfWorkFactoryInterface;
use Addiks\DoctrineTweaks\UnitOfWork\UnitOfWorkFactory;
use Addiks\DoctrineTweaks\Proxy\ProxyFactoryFactoryInterface;
use Addiks\DoctrineTweaks\Proxy\ProxyFactoryFactory;

/**
 * Manages entities for persistence via ORM.
 *
 * Only use this implementation of the entity-manager if you know what you are doing. If someone told you to use this
 * because "it is better", make sure to understand what the differences are between this entity-manager and the original
 * entity-manager shipped in the doctrine ORM and what the implications are. This may cause errors and/or bugs if it is
 * directly replacing doctrine's original entity-manager for third-party-code that is written with the original in mind.
 *
 * This entity-manager poses an alternative to doctrine's own entity-manager. In contrast to doctrine's entity-manager,
 * this entity-manager never closes. Instead, on rollback it rolls the managed part of all managed entities back to the
 * point of when the transaction was created.
 *
 * It does this by managing not only one UnitOfWork, but a stack of UnitOfWork-instances. There is one UnitOfWork per
 * open transaction plus the root-UnitOfWork. Each UnitOfWork in this stack contains the state of managed entities from
 * the time when the next transaction started. The top UnitOfWork on the stack is always the one currently used. When a
 * transaction begins, the topmost UnitOfWork is cloned and the clone put on top of the stack becoming the new current
 * UnitOfWork. When a transaction get's committed, the secont-topmost UnitOfWork get's removed from the stack, replaced
 * by the current and topmost UnitOfWork (resulting in a cheap commit). When a transaction get's rolled back, the
 * topmost UnitOfWork get's discarded and it's previous UnitOfWork (which still contains the state of the entities of
 * when the transaction begun becomes the new topmost and current UnitOfWork. A rollback also rolls back the managed
 * part of the state of all managed entities using the UnitOfWork that still contains the state of the managed entities
 * when the transaction begun (resulting in an expensive rollback!).
 *
 * The process described in the paragraph above allows for a meaningful workflow using transactions. If a process fails
 * and causes a rollback, not only the state in the database get's roled back, but also the state of all managed
 * entities. This allows for the executed and failed process to be re-tried again or to continue with the next process
 * using the same entity-manager. This can be done because the state of the runtime is known, it is the same as at the
 * beginning of the transaction. This follows the meaning of a "rollback" => The return from a faulty state into a well-
 * known state. If correctly used, this entity-manager could save some developers that care deeply about transactions a
 * big headache.
 */
class TransactionalEntityManager implements EntityManagerInterface
{

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var Expr
     */
    private $expressionBuilder;

    /**
     * @var ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * @var QueryFactoryInterface
     */
    private $queryFactory;

    /**
     * @var ExpressionBuilderFactoryInterface
     */
    private $expressionBuilderFactory;

    /**
     * @var ProxyFactory
     */
    private $proxyFactory;

    /**
     * @var HydratorFactoryInterface
     */
    private $hydratorFactory;

    /**
     * @var UnitOfWorkFactoryInterface
     */
    private $unitOfWorkFactory;

    /**
     * @var UnitOfWork[]
     */
    private $unitOfWorkStack = array();

    /**
     * @var RepositoryFactory
     */
    private $repositoryFactory;

    public function __construct(
        Connection $connection,
        Configuration $configuration,
        EventManager $eventManager,
        ClassMetadataFactory $metadataFactory = null,
        Expr $expressionBuilder = null,
        ProxyFactory $proxyFactory = null,
        Cache $cache = null,
        RepositoryFactory $repositoryFactory = null,
        UnitOfWorkFactoryInterface $unitOfWorkFactory = null,
        QueryFactoryInterface $queryFactory = null,
        ExpressionBuilderFactoryInterface $expressionBuilderFactory = null,
        HydratorFactoryInterface $hydratorFactory = null,
        ProxyFactoryFactoryInterface $proxyFactoryFactory = null
    ) {
        if (is_null($unitOfWorkFactory)) {
            $unitOfWorkFactory = new UnitOfWorkFactory();
        }

        if (is_null($queryFactory)) {
            $queryFactory = new QueryFactory();
        }

        if (is_null($expressionBuilderFactory)) {
            $expressionBuilderFactory = new ExpressionBuilderFactory();
        }

        if (is_null($proxyFactoryFactory)) {
            $proxyFactoryFactory = new ProxyFactoryFactory();
        }

        if (is_null($expressionBuilder)) {
            $expressionBuilder = new Expr();
        }

        if (is_null($metadataFactory)) {
            $metadataFactory = new ClassMetadataFactory();
            $metadataFactory->setEntityManager($this);
        }

        if (is_null($repositoryFactory)) {
            $repositoryFactory = $configuration->getRepositoryFactory();
        }

        $this->connection = $connection;
        $this->configuration = $configuration;
        $this->eventManager = $eventManager;
        $this->metadataFactory = $metadataFactory;
        $this->cache = $cache;
        $this->expressionBuilder = $expressionBuilder;
        $this->unitOfWorkFactory = $unitOfWorkFactory;
        $this->queryFactory = $queryFactory;
        $this->expressionBuilderFactory = $expressionBuilderFactory;
        $this->repositoryFactory = $repositoryFactory;

        $this->unitOfWorkStack[] = $this->createUnitOfWork();

        if (is_null($hydratorFactory)) {
            $hydratorFactory = new HydratorFactory($configuration, $this);
        }

        $this->hydratorFactory = $hydratorFactory;

        if (is_null($proxyFactory)) {
            $proxyFactory = $proxyFactoryFactory->createProxyFactory($this, $configuration);
        }

        $this->proxyFactory = $proxyFactory;
    }

    /**
     * Creates an instance of this entity-manager class as a "sibling" of another entity-manager.
     *
     * @param  EntityManagerInterface $entityManager
     * @return AddiksEntityManager
     */
    public static function createFromDoctrineEntityManager(EntityManagerInterface $entityManager)
    {
        $addiksEntityManager = new AddiksEntityManager(
            $entityManager->getConnection(),
            $entityManager->getConfiguration(),
            $entityManager->getEventManager(),
            $entityManager->getMetadataFactory(),
            $entityManager->getExpressionBuilder(),
            $entityManager->getProxyFactory(),
            $entityManager->getCache()
        );

        return $addiksEntityManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadataFactory()
    {
        return $this->metadataFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function getExpressionBuilder()
    {
        return $this->expressionBuilder;
    }

    /**
     * {@inheritDoc}
     */
    public function getProxyFactory()
    {
        return $this->proxyFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function getEventManager()
    {
        return $this->eventManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional($function)
    {
        if (!is_callable($function)) {
            throw new InvalidArgumentException(sprintf(
                'Expected argument of type "callable", got "%s',
                gettype($function)
            ));
        }

        /* @var $returnValue mixed */
        $returnValue = null;

        $this->beginTransaction();

        try {
            $returnValue = call_user_func($function, $this);

            $this->commit();

        } catch (Exception $exception) {
            $this->rollback();

            throw $exception;
        }

        if (is_null($returnValue)) {
            $returnValue = true;
        }

        return $returnValue;
    }

    /**
     * Get the currently topmost UnitOfWork instance.
     *
     * @return UnitOfWork
     */
    public function getUnitOfWork()
    {
        /* @var $unitOfWorkStack UnitOfWork[] */
        $unitOfWorkStack = $this->unitOfWorkStack;

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = end($unitOfWorkStack);

        if (!$unitOfWork instanceof UnitOfWork) {
            $unitOfWork = null;
        }

        return $unitOfWork;
    }

    private function createUnitOfWork()
    {
        /* @var $unitOfWorkFactory UnitOfWorkFactoryInterface */
        $unitOfWorkFactory = $this->unitOfWorkFactory;

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $unitOfWorkFactory->createUnitOfWork($this);

        assert($unitOfWork instanceof UnitOfWork);

        return $unitOfWork;
    }

    /**
     * Begins a transaction.
     *
     * Begins a new transaction on the connection and then creates a clone of the current UnitOfWork and uses the new
     * one for operations within the transaction.
     */
    public function beginTransaction()
    {
        /* @var $connection Connection */
        $connection = $this->connection;

        $connection->beginTransaction();

        /* @var $unitOfWorkFactory UnitOfWorkFactoryInterface */
        $unitOfWorkFactory = $this->unitOfWorkFactory;

        /* @var $previousUnitOfWork UnitOfWork */
        $previousUnitOfWork = $this->getUnitOfWork();

        /* @var $nextUnitOfWork UnitOfWork */
        $nextUnitOfWork = $unitOfWorkFactory->cloneUnitOfWork($previousUnitOfWork);

        $this->unitOfWorkStack[] = $nextUnitOfWork;
    }

    /**
     * Commit's the current UnitOfWork.
     *
     * Removes it's previous UnitOfWork from the stack as it is not needed anymore.
     */
    public function commit()
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = array_pop($this->unitOfWorkStack);

        array_pop($this->unitOfWorkStack);

        $this->unitOfWorkStack[] = $unitOfWork;

        $unitOfWork->commit();
    }

    /**
     * Discards the current UnitOfWork.
     *
     * Reestablishes the previous UnitOfWork as the new current UnitOfWork.
     */
    public function rollback()
    {
        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        $this->rollbackWithoutEntityUpdates();

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        /* @var $identityMap array */
        $identityMap = $unitOfWork->getIdentityMap();

        foreach ($identityMap as $entityClass => $entities) {
            foreach ($entities as $entity) {
                /* @var $entity object */

                /* @var $classMetadata ClassMetadata */
                $classMetadata = $metadataFactory->getMetadataFor($entityClass);

                /* @var $objectId string */
                $objectId = spl_object_hash($entity);

                /* @var $originalStateData array */
                $originalStateData = $unitOfWork->getOriginalEntityData($entity);

                foreach ($classMetadata->reflFields as $name => $reflectionProperty) {
                    /* @var $reflectionProperty ReflectionProperty */

                    $reflectionProperty->setAccessible(true);
                    $reflectionProperty->setValue($entity, $originalStateData[$name]);
                }
            }
        }
    }

    /**
     * Performs a rollback without actually changing the states of the managed entities.
     * Use this if you have too many managed entities to rollback.
     *
     * (Although having too many entities managed could be a hint to bad design elsewhere.
     *  Remember to detach entities when you dont need them anymore.)
     */
    public function rollbackWithoutEntityUpdates()
    {
        /* @var $connection Connection */
        $connection = $this->connection;

        $connection->rollBack();

        array_pop($this->unitOfWorkStack);
    }

    /**
     * {@inheritDoc}
     */
    public function createQuery($dql = '')
    {
        /* @var $queryFactory QueryFactoryInterface */
        $queryFactory = $this->queryFactory;

        /* @var $query Query */
        $query = $queryFactory->createQuery($this, $dql);

        if (!empty($dql)) {
            $query->setDql($dql);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function createNamedQuery($name)
    {
        /* @var $configuration Configuration */
        $configuration = $this->configuration;

        /* @var $namedQuery string */
        $namedQuery = $configuration->getNamedQuery($name);

        return $this->createQuery($namedQuery);
    }

    /**
     * {@inheritDoc}
     */
    public function createNativeQuery($sql, ResultSetMapping $rsm)
    {
        /* @var $queryFactory QueryFactoryInterface */
        $queryFactory = $this->queryFactory;

        /* @var $query NativeQuery */
        $query = $queryFactory->createNativeQuery($this, $sql, $rsm);

        $query->setSql($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function createNamedNativeQuery($name)
    {
        /* @var $configuration Configuration */
        $configuration = $this->configuration;

        list($sql, $rsm) = $configuration->getNamedNativeQuery($name);

        return $this->createNativeQuery($sql, $rsm);
    }

    /**
     * {@inheritDoc}
     */
    public function createQueryBuilder()
    {
        /* @var $expressionBuilderFactory ExpressionBuilderFactoryInterface */
        $expressionBuilderFactory = $this->expressionBuilderFactory;

        /* @var $queryBuilder QueryBuilder */
        $queryBuilder = $expressionBuilderFactory->createQueryBuilder($this);

        return $queryBuilder;
    }

    /**
     * {@inheritDoc}
     */
    public function getReference($entityName, $id)
    {
        /* @var $entity object */
        $entity = null;

        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        /* @var $proxyFactory ProxyFactory */
        $proxyFactory = $this->proxyFactory;

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        /* @var $classMetadata ClassMetadata */
        $classMetadata = $metadataFactory->getMetadataFor($entityName);

        /* @var $entityClass string */
        $entityClass = $classMetadata->name;

        /* @var $idKeys string[] */
        $idKeys = $classMetadata->identifier;

        /* @var $rootClassName string */
        $rootClassName = $classMetadata->rootClassName;

        $id = $this->buildIdentifier($classMetadata, $id);

        $entity = $unitOfWork->tryGetById($id, $rootClassName);

        if (!$entity instanceof $entityClass) {
            $entity = $this->find($entityName, $id);
        }

        if (!$entity instanceof $entityClass) {
            $entity = $proxyFactory->getProxy($entityClass, $id);

            $unitOfWork->registerManaged($entity, $id, []);
        }

        if (!$entity instanceof $entityClass) {
            $entity = null;
        }

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function getPartialReference($entityName, $identifier)
    {
        /* @var $entity object */
        $entity = null;

        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        /* @var $classMetadata ClassMetadata */
        $classMetadata = $metadataFactory->getMetadataFor($entityName);

        /* @var $rootClassName string */
        $rootClassName = $classMetadata->rootClassName;

        $entity = $unitOfWork->tryGetById($identifier, $rootClassName);

        $classMetadata->setIdentifierValues($entity, $identifier);

        $unitOfWork->registerManaged($entity, $identifier, []);
        $unitOfWork->markReadOnly($entity);

        return $entity;
    }

    /**
     * This implementation of the entity-manager never closes.
     *
     * {@inheritDoc}
     */
    public function close()
    {
    }

    /**
     * This implementation of the entity-manager never closes.
     *
     * {@inheritDoc}
     */
    public function isOpen()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function copy($entity, $deep = false)
    {
        throw new BadMethodCallException("Not implemented.");
    }

    /**
     * {@inheritDoc}
     */
    public function lock($entity, $lockMode, $lockVersion = null)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->lock($entity, $lockMode, $lockVersion);
    }

    /**
     * {@inheritDoc}
     */
    public function getHydrator($hydrationMode)
    {
        return $this->newHydrator($hydrationMode);
    }

    /**
     * {@inheritDoc}
     */
    public function newHydrator($hydrationMode)
    {
        /* @var $hydratorFactory HydratorFactoryInterface */
        $hydratorFactory = $thiy->hydratorFactory;

        return $hydratorFactory->newHydrator($hydrationMode);
    }

    /**
     * {@inheritDoc}
     */
    public function getFilters()
    {
        if (is_null($this->filterCollection)) {
            $this->filterCollection = new FilterCollection($this);
        }

        return $this->filterCollection;
    }

    /**
     * {@inheritDoc}
     */
    public function isFiltersStateClean()
    {
        /* @var $isClean boolean */
        $isClean = true;

        if ($this->filterCollection instanceof FilterCollection) {
            $isClean = $this->filterCollection->isClean();
        }

        return $isClean;
    }

    /**
     * {@inheritDoc}
     */
    public function hasFilters()
    {
        return !is_null($this->filterCollection);
    }

    /**
     * {@inheritDoc}
     */
    public function find($className, $id, $lockMode = null, $lockVersion = null)
    {
        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        /* @var $proxyFactory ProxyFactory */
        $proxyFactory = $this->proxyFactory;

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        /* @var $classMetadata ClassMetadata */
        $classMetadata = $metadataFactory->getMetadataFor($entityName);

        /* @var $entityClass string */
        $entityClass = $classMetadata->name;

        /* @var $idKeys string[] */
        $idKeys = $classMetadata->identifier;

        /* @var $rootClassName string */
        $rootClassName = $classMetadata->rootClassName;

        $id = $this->buildIdentifier($classMetadata, $id);

        /* @var $entity object */
        $entity = $unitOfWork->tryGetById($id, $rootClassName);

        /* @var $persister EntityPersister */
        $persister = $unitOfWork->getEntityPersister($entityName);

        if ($entity !== false) {
            switch ($lockMode) {
                case LockMode::OPTIMISTIC:
                    $this->lock($entity, $lockMode, $lockVersion);
                    break;

                case LockMode::NONE:
                case LockMode::PESSIMISTIC_READ:
                case LockMode::PESSIMISTIC_WRITE:
                    $persister->refresh($id, $entity, $lockMode);
                    break;
            }

        } else {
            switch ($lockMode) {
                case LockMode::OPTIMISTIC:
                    if (!$classMetadata->isVersioned) {
                        throw OptimisticLockException::notVersioned($entityClass);
                    }
                    $entity = $persister->load($id);
                    $unitOfWork->lock($entity, $lockMode, $lockVersion);
                    break;

                case LockMode::NONE:
                case LockMode::PESSIMISTIC_READ:
                case LockMode::PESSIMISTIC_WRITE:
                    if (!$this->getConnection()->isTransactionActive()) {
                        throw TransactionRequiredException::transactionRequired();
                    }
                    $entity = $persister->load($id, null, null, [], $lockMode);
                    break;

                default:
                    $entity = $persister->loadById($id);
                    break;
            }
        }

        if (!$entity instanceof $entityClass) {
            $entity = null;
        }

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function persist($object)
    {
        if (!is_object($object)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#persist()' , $object);
        }

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->persist($object);
    }

    /**
     * {@inheritDoc}
     */
    public function remove($object)
    {
        if (!is_object($object)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#remove()' , $object);
        }

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->remove($object);
    }

    /**
     * {@inheritDoc}
     */
    public function merge($object)
    {
        if (!is_object($object)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#merge()' , $object);
        }

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->merge($object);
    }

    /**
     * {@inheritDoc}
     */
    public function clear($objectName = null)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->clear($objectName);
    }

    /**
     * {@inheritDoc}
     */
    public function detach($object)
    {
        if (!is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#detach()' , $entity);
        }

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->detach($object);
    }

    /**
     * {@inheritDoc}
     */
    public function refresh($object)
    {
        if (!is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#refresh()' , $entity);
        }

        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->refresh($object);
    }

    /**
     * {@inheritDoc}
     */
    public function flush($entity = null)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        if ($unitOfWork instanceof UnitOfWork) {
            $unitOfWork->commit($entity);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRepository($className)
    {
        /* @var $repositoryFactory RepositoryFactory */
        $repositoryFactory = $this->repositoryFactory;

        /* @var $repository object */
        $repository = $repositoryFactory->getRepository($this, $className);

        return $repository;
    }

    /**
     * {@inheritDoc}
     */
    public function getClassMetadata($entityClass)
    {
        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        return $metadataFactory->getMetadataFor($entityClass);
    }

    /**
     * {@inheritDoc}
     */
    public function initializeObject($object)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $unitOfWork->initializeObject($object);
    }

    /**
     * {@inheritDoc}
     */
    public function contains($object)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        $isScheduledForInsert = $unitOfWork->isScheduledForInsert($object);
        $isInIdentityMap      = $unitOfWork->isInIdentityMap($object);
        $isScheduledForDelete = $unitOfWork->isScheduledForDelete($object);

        return ($isScheduledForInsert || $isInIdentityMap) && !$isScheduledForDelete;
    }

    private function buildIdentifier(ClassMetadata $classMetadata, $id)
    {
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork = $this->getUnitOfWork();

        /* @var $metadataFactory ClassMetadataFactory */
        $metadataFactory = $this->metadataFactory;

        /* @var $entityClass string */
        $entityClass = $classMetadata->name;

        /* @var $idKeys string[] */
        $idKeys = $classMetadata->identifier;

        if (!is_array($id)) {
            if ($classMetadata->isIdentifierComposite) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$idKeys[0] => $id];
        }

        foreach ($id as $index => &$value) {
            if (is_object($value)) {
                /* @var $valueClass mixed */
                $valueClass = ClassUtils::getClass($value);

                /* @var $valueClassMetadata mixed */
                $valueClassMetadata = $metadataFactory->getMetadataFor($valueClass);

                $value = $unitOfWork->getSingleIdentifierValue($value);

                if (is_null($value)) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        /* @var $sortedId array */
        $sortedId = array();

        foreach ($idKeys as $idKey) {
            if (!isset($id[$idKey])) {
                throw ORMException::missingIdentifierField($entityClass, $idKey);
            }

            $sortedId[$idKey] = $id[$idKey];
            unset($id[$idKey]);
        }

        if (!empty($id)) {
            throw ORMException::unrecognizedIdentifierFields($class->name, array_keys($id));
        }

        return $sortedId;
    }

}
