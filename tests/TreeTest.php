<?php

use PHPUnit\Framework\TestCase;
use \tests\models\Item;
use \shirase\tree\TreeBehavior;

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
                    'bpath'=>TreeBehavior::toBase255([1]),
                ],
                [
                    'id'=>2,
                    'pid'=>1,
                    'pos'=>10002,
                    'name'=>'test-1',
                    'bpath'=>TreeBehavior::toBase255([1, 10002]),
                ],
                [
                    'id'=>3,
                    'pid'=>1,
                    'pos'=>10003,
                    'name'=>'test-2',
                    'bpath'=>TreeBehavior::toBase255([1, 10003]),
                ],
                [
                    'id'=>4,
                    'pid'=>1,
                    'pos'=>10004,
                    'name'=>'test-3',
                    'bpath'=>TreeBehavior::toBase255([1, 10004]),
                ],
                [
                    'id'=>5,
                    'pid'=>4,
                    'pos'=>10005,
                    'name'=>'test-3-1',
                    'bpath'=>TreeBehavior::toBase255([1, 10004, 10005]),
                ],
            ],
        ]);
    }

    public function testBase() {
        $this->assertEquals(TreeBehavior::toBase255([256]), Yii::$app->db->createCommand('SELECT LPAD(CHAR(256), '.TreeBehavior::BPATH_LEN.', CHAR(0))')->queryScalar());
    }

    public function testInsertBeforeFirst() {
        $model = Item::findOne(4);
        $model->insertBefore(2);
        $this->assertEquals(10001, Item::findOne($model->id)->pos);
        $this->assertEquals(10002, Item::findOne(2)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10001]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10001, 10005]), Item::findOne(5)->bpath);
    }

    public function testInsertBeforeLast() {
        $model = Item::findOne(2);
        $model->insertBefore(4);
        $this->assertEquals(10003, Item::findOne($model->id)->pos);
        $this->assertEquals(10002, Item::findOne(3)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10002]), Item::findOne(3)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004, 10005]), Item::findOne(5)->bpath);
    }

    public function testInsertAfterFirst() {
        $model = Item::findOne(4);
        $model->insertAfter(2);
        $this->assertEquals(10003, Item::findOne($model->id)->pos);
        $this->assertEquals(10004, Item::findOne(3)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004]), Item::findOne(3)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003, 10005]), Item::findOne(5)->bpath);
    }

    public function testInsertAfterLast() {
        $model = Item::findOne(2);
        $model->insertAfter(4);
        $this->assertEquals(10004, Item::findOne($model->id)->pos);
        $this->assertEquals(10003, Item::findOne(4)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003]), Item::findOne(4)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003, 10005]), Item::findOne(5)->bpath);
    }

    public function testInsert() {
        $model = new Item();
        $model->name = 'test-3-2';
        $model->pid = 4;
        $model->save();

        $this->assertEquals(10006, Item::findOne($model->id)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004, 10006]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004, 10005]), Item::findOne(5)->bpath);
    }

    public function testBpath() {
        $model = Item::findOne(4);
        $model->insertBefore(3);
        $this->assertEquals(10003, Item::findOne($model->id)->pos);
        $this->assertEquals(10004, Item::findOne(3)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003, 10005]), Item::findOne(5)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004]), Item::findOne(3)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1]), Item::findOne(1)->bpath);

        $model = Item::findOne(4);
        $model->insertAfter(3);
        $this->assertEquals(10004, Item::findOne($model->id)->pos);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10004, 10005]), Item::findOne(5)->bpath);
    }

    public function testPath() {
        $model = Item::findOne(5);
        $path = $model->getPath();
        $this->assertEquals(1, $path[0]);
        $this->assertEquals(4, $path[1]);
        $this->assertEquals(5, $path[2]);
    }

    public function testMove() {
        $model = Item::findOne(4);
        $model->moveTo(3);

        $this->assertEquals(3, Item::findOne($model->id)->pid);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003, 10006]), Item::findOne($model->id)->bpath);
        $this->assertEquals(TreeBehavior::toBase255([1, 10003, 10006, 10005]), Item::findOne(5)->bpath);
    }
}