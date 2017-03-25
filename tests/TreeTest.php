<?php

use PHPUnit\Framework\TestCase;
use \tests\models\Item;

class TreeTest extends TestCase
{
    use PHPUnit_Extensions_Database_TestCase_Trait;

    // only instantiate pdo once for test clean-up/fixture load
    static private $pdo = null;

    // only instantiate PHPUnit_Extensions_Database_DB_IDatabaseConnection once per test
    private $conn = null;

    final public function getConnection()
    {
        if ($this->conn === null) {
            if (self::$pdo == null) {
                self::$pdo = new PDO( $GLOBALS['DB_DSN'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASSWD'] );
                self::$pdo->query('
CREATE TABLE IF NOT EXISTS `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL,
  `pos` int(11) NOT NULL,
  `bpath` blob,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
            }
            $this->conn = $this->createDefaultDBConnection(self::$pdo, $GLOBALS['DB_DBNAME']);
        }

        return $this->conn;
    }

    /**
     * @return PHPUnit_Extensions_Database_DataSet_IDataSet
     */
    public function getDataSet()
    {
        return $this->createArrayDataSet([
            'items' => [
                [
                    'id'=>1,
                    'pid'=>0,
                    'pos'=>1,
                    'name'=>'root',
                    'bpath'=>\shirase\tree\TreeBehavior::toBase255([1]),
                ],
                [
                    'id'=>2,
                    'pid'=>1,
                    'pos'=>2,
                    'name'=>'test-1',
                    'bpath'=>\shirase\tree\TreeBehavior::toBase255([1, 2]),
                ],
                [
                    'id'=>3,
                    'pid'=>1,
                    'pos'=>3,
                    'name'=>'test-2',
                    'bpath'=>\shirase\tree\TreeBehavior::toBase255([1, 3]),
                ],
                [
                    'id'=>4,
                    'pid'=>1,
                    'pos'=>4,
                    'name'=>'test-3',
                    'bpath'=>\shirase\tree\TreeBehavior::toBase255([1, 4]),
                ],
            ],
        ]);
    }

    public function testInsertBeforeFirst() {
        $model = Item::findOne(4);
        $model->insertBefore(2);
        $this->assertEquals(2, Item::findOne($model->id)->pos);
    }

    public function testInsertBeforeLast() {
        $model = Item::findOne(2);
        $model->insertBefore(4);
        $this->assertEquals(3, Item::findOne($model->id)->pos);
    }

    public function testInsertAfterFirst() {
        $model = Item::findOne(4);
        $model->insertAfter(2);
        $this->assertEquals(3, Item::findOne($model->id)->pos);
    }

    public function testInsertAfterLast() {
        $model = Item::findOne(2);
        $model->insertAfter(4);
        $this->assertEquals(5, Item::findOne($model->id)->pos);
    }
}