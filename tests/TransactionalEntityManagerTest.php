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

use PHPUnit\Framework\TestCase;
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
use Addiks\DoctrineTweaks\TransactionalEntityManagerInterface;
use Doctrine\Tests\TestUtil;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;

class TransactionalEntityManagerTest extends TestCase
{

    /**
     * @var TransactionalEntityManagerInterface
     */
    protected $entityManager;

    public function setUp(): void
    {
        $GLOBALS['db_driver'] = 'pdo_sqlite';
        
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
        /* @var $entityManager TransactionalEntityManagerInterface */
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
            $entityManager->rollbackEntities();
        }

        $this->assertExpectedValuesOnKnownEntities($expectedValues, $knownEntities);
    }

    public function dataProviderBasicTransaction()
    {
        $buildFixtures = function () {
            $a = new SampleEntity("Lorem ipsum",    31415, true);

            return [
                'a' => $a,
                'b' => new SampleEntity("dolor sit amet", 92653, false, $a),
                'c' => new SampleEntity("consetetur",     58979, true, $a),
            ];
        };

        /* @var $entityUpdates array */
        $entityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'embeddable' => [
                    'bar' => 32384,
                ]
            ],
            'c' => [
                'embeddable' => [
                    'baz' => false,
                ],
                'parent' => function (array $knownEntities) {
                    return $knownEntities['b'];
                }
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ]
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => false,
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 92653,
                            'baz' => false,
                        ],
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
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
        /* @var $entityManager TransactionalEntityManagerInterface */
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
            $entityManager->rollbackEntities();
        }

        $entityManager->beginTransaction();

        $this->applyEntityUpdatesOnKnownEntities($secondEntityUpdates, $knownEntities);

        $entityManager->flush();

        if ($doCommitSecond) {
            $entityManager->commit();

        } else {
            $entityManager->rollbackEntities();
        }

        $this->assertExpectedValuesOnKnownEntities($expectedValues, $knownEntities);
    }

    public function dataProviderTwoTransactionsSequential()
    {
        $buildFixtures = function () {
            $a = new SampleEntity("Lorem ipsum",    31415, true);

            return [
                'a' => $a,
                'b' => new SampleEntity("dolor sit amet", 92653, false, $a),
                'c' => new SampleEntity("consetetur",     58979, true, $a),
            ];
        };

        $firstEntityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'embeddable' => [
                    'bar' => 32384,
                ]
            ],
            'c' => [
                'embeddable' => [
                    'baz' => false,
                ],
                'parent' => function (array $knownEntities) {
                    return $knownEntities['b'];
                }
            ]
        ];

        $secondEntityUpdates = [
            'a' => [
                'embeddable' => [
                    'bar' => 62643
                ]
            ],
            'b' => [
                'embeddable' => [
                    'baz' => true,
                ],
                'parent' => function (array $knownEntities) {
                    return $knownEntities['c'];
                }
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
                        'embeddable' => [
                            'bar' => 62643,
                            'baz' => true,
                        ]
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "elitr, sed diam"
                        ]
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "sadipscing"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
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
                        'embeddable' => [
                            'bar' => 62643,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 92653,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "elitr, sed diam"
                        ]
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 92653,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
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
        /* @var $entityManager TransactionalEntityManagerInterface */
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
            $entityManager->rollbackEntities();
        }

        $this->assertExpectedValuesOnKnownEntities($innerExpectedValues, $knownEntities);

        if ($doCommitOuter) {
            $entityManager->commit();

        } else {
            $entityManager->rollbackEntities();
        }

        $this->assertExpectedValuesOnKnownEntities($outerExpectedValues, $knownEntities);
    }

    public function dataProviderTwoTransactionsNested()
    {
        $buildFixtures = function () {
            $a = new SampleEntity("Lorem ipsum",    31415, true);

            return [
                'a' => $a,
                'b' => new SampleEntity("dolor sit amet", 92653, false, $a),
                'c' => new SampleEntity("consetetur",     58979, true, $a),
            ];
        };

        $outerEntityUpdates = [
            'a' => [
                'foo' => "sadipscing"
            ],
            'b' => [
                'embeddable' => [
                    'bar' => 32384,
                ],
            ],
            'c' => [
                'embeddable' => [
                    'baz' => false,
                ],
                'parent' => function (array $knownEntities) {
                    return $knownEntities['b'];
                }
            ]
        ];

        $innerEntityUpdates = [
            'a' => [
                'embeddable' => [
                    'bar' => 62643
                ],
            ],
            'b' => [
                'embeddable' => [
                    'baz' => true,
                ],
                'parent' => function (array $knownEntities) {
                    return $knownEntities['c'];
                }
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
                        'embeddable' => [
                            'bar' => 62643,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "elitr, sed diam"
                        ]
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
                    ]
                ],
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'embeddable' => [
                            'bar' => 62643,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "elitr, sed diam"
                        ]
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
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
                        'embeddable' => [
                            'bar' => 62643,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "elitr, sed diam"
                        ]
                    ],
                    'c' => [
                        'foo' => "elitr, sed diam",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
                    ]
                ],
                false,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 92653,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "sadipscing"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
                    ]
                ],
                true,
                [
                    'a' => [
                        'foo' => "sadipscing",
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "sadipscing"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
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
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 32384,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "sadipscing"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "dolor sit amet"
                        ]
                    ]
                ],
                false,
                [
                    'a' => [
                        'foo' => "Lorem ipsum",
                        'embeddable' => [
                            'bar' => 31415,
                            'baz' => true,
                        ],
                    ],
                    'b' => [
                        'foo' => "dolor sit amet",
                        'embeddable' => [
                            'bar' => 92653,
                            'baz' => false,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
                    ],
                    'c' => [
                        'foo' => "consetetur",
                        'embeddable' => [
                            'bar' => 58979,
                            'baz' => true,
                        ],
                        'parent' => [
                            'foo' => "Lorem ipsum"
                        ]
                    ]
                ]
            ],
        );
    }

    public function testCommitAndDetachNewEntities()
    {
        /* @var $entityManager TransactionalEntityManagerInterface */
        $entityManager = $this->entityManager;

        $beforeEntity = new SampleEntity("Lorem ipsum", 31415, true);

        $entityManager->persist($beforeEntity);
        $entityManager->flush($beforeEntity);

        for ($counter = 0; $counter < 100; $counter++) {
            $entityManager->beginTransaction();

            $entity = new SampleEntity("Lorem ipsum", 31415, true);

            $entityManager->persist($entity);
            $entityManager->flush($entity);

            $entityManager->commitAndDetachNewEntities();
        }

        $this->assertEquals(
            count($entityManager->getUnitOfWork()->getIdentityMap()),
            1
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

            $this->applyEntityUpdatesOnEntity($updates, $entity, $knownEntities);
        }
    }

    private function applyEntityUpdatesOnEntity(array $updates, $entity, array $knownEntities = array())
    {
        foreach ($updates as $memberName => $newValue) {
            if (is_callable($newValue)) {
                $newValue = $newValue($knownEntities);
            }

            if (is_array($newValue)) {
                $subEntity = $entity->{$memberName};

                $this->applyEntityUpdatesOnEntity($newValue, $subEntity, $knownEntities);

            } else {
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

            $this->assertExpectedValuesOnEntity($values, $entity, $entityId);
        }
    }

    private function assertExpectedValuesOnEntity(
        array $expectedValues,
        $entity,
        $entityId
    ) {
        foreach ($expectedValues as $memberName => $expectedValue) {
            /* @var $actualValue mixed */
            $actualValue = $entity->{$memberName};

            if (is_array($expectedValue)) {
                $this->assertExpectedValuesOnEntity($expectedValue, $actualValue, "{$entityId}.{$memberName}");

            } else {
                $this->assertEquals($expectedValue, $actualValue, sprintf(
                    "Expected different value in entity %s!",
                    $entityId
                ));
            }
        }
    }

}
