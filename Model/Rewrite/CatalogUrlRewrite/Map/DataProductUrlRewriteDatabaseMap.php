<?php
/**
 * Copyright © 2017 Chad A. Carino. All rights reserved.
 * See LICENSE file for license details.
 *
 * @package    Bangerkuwranger/GtidSafeUrlRewriteTables
 * @author     Chad A. Carino <artist@chadacarino.com>
 * @author     Burak Bingollu <burak.bingollu@gmail.com>
 * @copyright  2017 Chad A. Carino
 * @license    https://opensource.org/licenses/MIT  MIT License
 */
namespace Bangerkuwranger\GtidSafeUrlRewriteTables\Model\Rewrite\CatalogUrlRewrite\Map;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TemporaryTableService;
use Magento\UrlRewrite\Model\MergeDataProvider;

/**
 * Map that holds data for category url rewrites entity
 */
class DataProductUrlRewriteDatabaseMap implements DatabaseMapInterface
{
    /**
     * Entity type for queries.
     *
     * @var string
     */
    private $entityType = 'product';

    /**
     * Names of the temporary tables.
     *
     * @var string[]
     */
    private $createdTableAdapters = [];

    /**
     * Pool for hash maps.
     *
     * @var HashMapPool
     */
    private $hashMapPool;

    /**
     * Resource connection.
     *
     * @var ResourceConnection
     */
    private $connection;

    /**
     * Creates a temporary table in mysql.
     *
     * @var TemporaryTableService
     */
    private $temporaryTableService;

    /**
     * @param ResourceConnection $connection
     * @param HashMapPool $hashMapPool,
     * @param TemporaryTableService $temporaryTableService
     */
    public function __construct(
        ResourceConnection $connection,
        HashMapPool $hashMapPool,
        TemporaryTableService $temporaryTableService
    ) {
        $this->connection = $connection;
        $this->hashMapPool = $hashMapPool;
        $this->temporaryTableService = $temporaryTableService;
    }

    /**
     * Generates data from categoryId and stores it into a temporary table.
     *
     * @param int $categoryId
     * @return void
     */
    private function generateTableAdapter($categoryId)
    {
        if (!isset($this->createdTableAdapters[$categoryId])) {
            $this->createdTableAdapters[$categoryId] = $this->generateData($categoryId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getData($categoryId, $key)
    {
        $this->generateTableAdapter($categoryId);
        $urlRewritesConnection = $this->connection->getConnection();
        $select = $urlRewritesConnection->select()
            ->from(['e' => $this->createdTableAdapters[$categoryId]])
            ->where('hash_key = ?', $key);

        return $urlRewritesConnection->fetchAll($select);
    }

    /**
     * Queries the database for all category url rewrites that are affected by the category identified by $categoryId.
     * It returns the name of the temporary table where the resulting data is stored.
     *
     * @param int $categoryId
     * @return string
     */
    private function generateData($categoryId)
    {
        $urlRewritesConnection = $this->connection->getConnection();
        $select = $urlRewritesConnection->select()
            ->from(
                ['e' => $this->connection->getTableName('url_rewrite')],
                ['e.*', 'hash_key' => new \Zend_Db_Expr(
                    "CONCAT(e.store_id,'" . MergeDataProvider::SEPARATOR . "', e.entity_id)"
                )
                ]
            )
            ->where('entity_type = ?', $this->entityType)
            ->where(
                $urlRewritesConnection->prepareSqlCondition(
                    'entity_id',
                    [
                        'in' => $this->hashMapPool->getDataMap(DataProductHashMap::class, $categoryId)
                            ->getAllData($categoryId)
                    ]
                )
            );
        $mapName = $this->temporaryTableService->createFromSelect(
            $select,
            $this->connection->getConnection(),
            [
                'PRIMARY' => ['url_rewrite_id'],
                'HASHKEY_ENTITY_STORE' => ['hash_key'],
                'ENTITY_STORE' => ['entity_id', 'store_id']
            ]
        );

        return $mapName;
    }

    /**
     * {@inheritdoc}
     */
    public function destroyTableAdapter($categoryId)
    {
        $this->hashMapPool->resetMap(DataProductHashMap::class, $categoryId);
        if (isset($this->createdTableAdapters[$categoryId])) {
            $this->temporaryTableService->dropTable($this->createdTableAdapters[$categoryId]);
            unset($this->createdTableAdapters[$categoryId]);
        }
    }
}
