<?php
/**
 * Copyright (C) 2015  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 */

namespace Addiks\Doctrine\Tests;

use PHPUnit_Framework_TestCase;
use Doctrine\Common\Persistence\Mapping\Driver\StaticPHPDriver;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Addiks\DoctrineTweaks\Tests\SampleEntity;
use Addiks\DoctrineTweaks\TransactionalEntityManager;
use Doctrine\Tests\TestUtil;

class TransactionalEntityManagerTest extends PHPUnit_Framework_TestCase
{

    /**
     * @var AddiksEntityManager
     */
    protected $entityManager;

    public function setUp()
    {
        /* @var $connection Connection */
        $connection = TestUtil::getConnection();

        $metadataDriverImpl = new StaticPHPDriver([]);

        $ormConfiguration = new ORMConfiguration();
        $ormConfiguration->setProxyDir(sys_get_temp_dir());
        $ormConfiguration->setProxyNamespace("Addiks\\DoctrineTweaks\\Proxy");
        $ormConfiguration->setMetadataDriverImpl($metadataDriverImpl);

        $eventManager = new EventManager();

        $entityManager = new TransactionalEntityManager(
            $connection,
            $ormConfiguration,
            $eventManager
        );

        /* @var $schemaTool mixed */
        $schemaTool = new SchemaTool($entityManager);

        /* @var $sampleEntityMetadata ClassMetadata */
        $sampleEntityMetadata = $entityManager->getClassMetadata(SampleEntity::class);

        $schemaTool->updateSchema([$sampleEntityMetadata]);

        $this->entityManager = $entityManager;
    }

    /**
     * Tests commit and rollback on simple one-level deep transactions.
     *
     * @dataProvider dataProviderBasicTransaction
     */
    public function testBasicTransaction(
        array $fixtureEntities,
        array $entityUpdates,
        $doCommit,
        array $expectedValues
    ) {
        /* @var $entityManager EntityManagerInterface */
        $entityManager = $this->entityManager;

        /* @var $knownEntities object[] */
        $knownEntities = $this->setUpFixtureEntities($fixtureEntities);

        $entityManager->flush();

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($entityUpdates, $knownEntities);

        $entityManager->flush();

        if ($doCommit) {
            $entityManager->commit();

        } else {
            $entityManager->rollback();
        }

        $this->assertExpectedValuesOnKnownEntities($expectedValues, $knownEntities);
    }

    public function dataProviderBasicTransaction()
    {
        $buildFixtures = function () {
            return [
                'a' => new SampleEntity("Lorem ipsum",    31415, true),
                'b' => new SampleEntity("dolor sit amet", 92653, false),
                'c' => new SampleEntity("consetetur",     58979, true),
            ];
        };

        /* @var $entityUpdates array */
        $entityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'bar' => 32384
            ],
            'c' => [
                'baz' => false
            ]
        ];

        return array(
            [
                $buildFixtures(),
                $entityUpdates,
                true, # doCommit
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $entityUpdates,
                false, # doCommit
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 92653,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => true,
                    ]
                ]
            ]
        );
    }

    /**
     * @dataProvider dataProviderTwoTransactionsSequential
     */
    public function testTwoTransactionsSequential(
        array $fixtureEntities,
        array $firstEntityUpdates,
        $doCommitFirst,
        array $secondEntityUpdates,
        $doCommitSecond,
        array $expectedValues
    ) {
        /* @var $entityManager EntityManagerInterface */
        $entityManager = $this->entityManager;

        /* @var $knownEntities object[] */
        $knownEntities = $this->setUpFixtureEntities($fixtureEntities);

        $entityManager->flush();

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($firstEntityUpdates, $knownEntities);

        $entityManager->flush();

        if ($doCommitFirst) {
            $entityManager->commit();

        } else {
            $entityManager->rollback();
        }

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($secondEntityUpdates, $knownEntities);

        $entityManager->flush();

        if ($doCommitSecond) {
            $entityManager->commit();

        } else {
            $entityManager->rollback();
        }

        $this->assertExpectedValuesOnKnownEntities($expectedValues, $knownEntities);
    }

    public function dataProviderTwoTransactionsSequential()
    {
        $buildFixtures = function () {
            return [
                'a' => new SampleEntity("Lorem ipsum",    31415, true),
                'b' => new SampleEntity("dolor sit amet", 92653, false),
                'c' => new SampleEntity("consetetur",     58979, true),
            ];
        };

        $firstEntityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'bar' => 32384
            ],
            'c' => [
                'baz' => false
            ]
        ];

        $secondEntityUpdates = [
            'a' => [
                'bar' => 62643
            ],
            'b' => [
                'baz' => true
            ],
            'c' => [
                'foo' => "elitr, sed diam"
            ]
        ];

        return array(
            [
                $buildFixtures(),
                $firstEntityUpdates,
                true,
                $secondEntityUpdates,
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 62643,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => true,
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $firstEntityUpdates,
                true,
                $secondEntityUpdates,
                false,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $firstEntityUpdates,
                false,
                $secondEntityUpdates,
                true,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'bar' => 62643,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 92653,
                        'baz' => true,
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'bar' => 58979,
                        'baz' => true,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $firstEntityUpdates,
                false,
                $secondEntityUpdates,
                false,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 92653,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => true,
                    ]
                ]
            ],
        );
    }


    /**
     * @dataProvider dataProviderTwoTransactionsNested
     */
    public function testTwoTransactionsNested(
        array $fixtureEntities,
        array $outerEntityUpdates,
        array $innerEntityUpdates,
        $doCommitInner,
        array $innerExpectedValues,
        $doCommitOuter,
        array $outerExpectedValues
    ) {
        /* @var $entityManager EntityManagerInterface */
        $entityManager = $this->entityManager;

        /* @var $knownEntities object[] */
        $knownEntities = $this->setUpFixtureEntities($fixtureEntities);

        $entityManager->flush();

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($outerEntityUpdates, $knownEntities);

        $entityManager->flush();

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($innerEntityUpdates, $knownEntities);

        $entityManager->flush();

        if ($doCommitInner) {
            $entityManager->commit();

        } else {
            $entityManager->rollback();
        }

        $this->assertExpectedValuesOnKnownEntities($innerExpectedValues, $knownEntities);

        if ($doCommitOuter) {
            $entityManager->commit();

        } else {
            $entityManager->rollback();
        }

        $this->assertExpectedValuesOnKnownEntities($outerExpectedValues, $knownEntities);
    }

    public function dataProviderTwoTransactionsNested()
    {
        $buildFixtures = function () {
            return [
                'a' => new SampleEntity("Lorem ipsum",    31415, true),
                'b' => new SampleEntity("dolor sit amet", 92653, false),
                'c' => new SampleEntity("consetetur",     58979, true),
            ];
        };

        $outerEntityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'bar' => 32384
            ],
            'c' => [
                'baz' => false
            ]
        ];

        $innerEntityUpdates = [
            'a' => [
                'bar' => 62643
            ],
            'b' => [
                'baz' => true
            ],
            'c' => [
                'foo' => "elitr, sed diam"
            ]
        ];

        return array(
            [
                $buildFixtures(),
                $outerEntityUpdates,
                $innerEntityUpdates,
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 62643,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => true,
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ],
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 62643,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => true,
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ],
            ],
            [
                $buildFixtures(),
                $outerEntityUpdates,
                $innerEntityUpdates,
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 62643,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => true,
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ],
                false,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 92653,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => true,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $outerEntityUpdates,
                $innerEntityUpdates,
                false,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ],
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ]
            ],
            [
                $buildFixtures(),
                $outerEntityUpdates,
                $innerEntityUpdates,
                false,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 32384,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => false,
                    ]
                ],
                false,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'bar' => 31415,
                        'baz' => true,
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'bar' => 92653,
                        'baz' => false,
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'bar' => 58979,
                        'baz' => true,
                    ]
                ]
            ],
        );
    }

    private function setUpFixtureEntities(array $fixtureEntities)
    {
        /* @var $entityManager EntityManagerInterface */
        $entityManager = $this->entityManager;

        /* @var $knownEntities object[] */
        $knownEntities = array();

        foreach ($fixtureEntities as $entityId => $entity) {
            /* @var $entity object */

            $entityManager->persist($entity);

            $knownEntities[$entityId] = $entity;
        }

        return $knownEntities;
    }

    private function applyEntityUpdatesOnKnownEntities(array $entityUpdates, array $knownEntities)
    {
        foreach ($entityUpdates as $entityId => $updates) {
            $entity = $knownEntities[$entityId];

            foreach ($updates as $memberName => $newValue) {
                $entity->{$memberName} = $newValue;
            }
        }
    }

    private function assertExpectedValuesOnKnownEntities(
        array $expectedValues,
        array $knownEntities
    ) {
        foreach ($expectedValues as $entityId => $values) {
            $entity = $knownEntities[$entityId];

            foreach ($values as $memberName => $expectedValue) {
                /* @var $actualValue mixed */
                $actualValue = $entity->{$memberName};

                $this->assertEquals($expectedValue, $actualValue, sprintf(
                    "Expected different value in entity %s!",
                    $entityId
                ));
            }
        }
    }

}
