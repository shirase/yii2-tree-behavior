<?php
namespace shirase\tree;

use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\web\HttpException;
use yii\db\Expression;

/**
 * Class TreeBehavior
 * @package shirase\tree
 * @property \yii\db\ActiveRecord $owner
 */
class TreeBehavior extends Behavior {

    public $pidAttribute = 'pid';
    public $bPathAttribute = 'bpath';
    public $posAttribute = 'pos';

    const BPATH_LEN = 4;

    public function attach($owner)
    {
        parent::attach($owner);

        /**
         * @var \yii\db\ActiveRecord $owner
         */
        if(!$owner->hasAttribute($this->pidAttribute)) {
            $this->pidAttribute = null;
        }
        if(!$owner->hasAttribute($this->bPathAttribute)) {
            $this->bPathAttribute = null;
        }
        if(!$owner->hasAttribute($this->posAttribute)) {
            $this->posAttribute = null;
        }

        if($this->bPathAttribute && !$this->posAttribute) {
            throw new HttpException(500);
        }
    }

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_DELETE=>'beforeDelete',
            ActiveRecord::EVENT_BEFORE_INSERT=>'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE=>'beforeSave',
        ];
    }

    public function beforeDelete($event) {
        if($this->pidAttribute && $this->owner->findOne([$this->pidAttribute=>$this->owner->primaryKey])) {
            $event->isValid = false;
        }
    }

    public function beforeSave($event) {
        if(!$this->owner->{$this->posAttribute}) {
            $this->owner->{$this->posAttribute} = $this->maxPos()+1;
        }

        if($this->bPathAttribute && !$this->owner->{$this->bPathAttribute}) {
            if($this->owner->isNewRecord) {
                $this->owner->{$this->bPathAttribute} = $this->buildBPath();
            } else {
                $this->reBuildBPath();
            }
        }
    }

    private function buildBPath() {
        $bpath = '';
        $parent = $this->owner;
        $path = array($parent->{$this->posAttribute});
        while($parent=$parent->getParent()) {
            if($bpath = $parent->{$this->bPathAttribute}) {
                break;
            }
            array_unshift($path, $parent->{$this->posAttribute});
        }
        return $bpath.self::toBase255($path);
    }

    public function reBuildBPath() {
        if(!$this->bPathAttribute || !$this->pidAttribute || !$this->posAttribute) {
            throw new HttpException(500, 'bpath, pid, pos is required');
        }

        $model = $this->owner;
        $this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' SET `'.$this->bPathAttribute.'`=NULL')->execute();
        while($this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' AS t SET `'.$this->bPathAttribute.'`=CONCAT_WS("", (SELECT `'.$this->bPathAttribute.'` FROM (SELECT '.$model::primaryKey()[0].', '.$this->bPathAttribute.' FROM '.$model::tableName().') AS p WHERE p.'.$model::primaryKey()[0].'=t.`'.$this->pidAttribute.'`), LPAD(CHAR(`'.$this->posAttribute.'`), '.self::BPATH_LEN.', CHAR(0))) ORDER BY `'.$this->posAttribute.'`;')->execute()) {}
    }

    private function maxPos() {
        $model = $this->owner;
        return $model->getDb()->createCommand('SELECT MAX(`'.$this->posAttribute.'`) FROM '.$model::tableName())->queryScalar();
    }

    public function moveTo($to) {
        if(!$this->posAttribute || !$this->pidAttribute) {
            throw new HttpException(500);
        }

        $db = $this->owner->getDb();

        if ($db->getTransaction()) {
            $transaction = null;
        } else {
            $transaction = $this->owner->getDb()->beginTransaction();
        }

        $this->moveToInner($to);

        if ($transaction)
            $transaction->commit();
    }

    private function moveToInner($to) {
        if(!$this->posAttribute || !$this->pidAttribute) {
            throw new HttpException(500);
        }
        $this->owner->{$this->posAttribute} = $this->maxPos()+1;
        $this->owner->{$this->pidAttribute} = $to;

        if($this->bPathAttribute) {
            $model = $this->owner;
            $bpathOld = $this->owner->{$this->bPathAttribute};
            $this->owner->{$this->bPathAttribute} = $this->buildBPath();
            $this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' SET `'.$this->bPathAttribute.'`=CONCAT(:bpath, RIGHT(`'.$this->bPathAttribute.'`, LENGTH(`'.$this->bPathAttribute.'`)-LENGTH(:bpathOld))) WHERE `'.$this->bPathAttribute.'` LIKE :bpathOld', [':bpath'=>$this->owner->{$this->bPathAttribute}, ':bpathOld'=>$bpathOld.'%'])->execute();
            $this->owner->save(false, array($this->pidAttribute, $this->posAttribute, $this->bPathAttribute));
        } else {
            $this->owner->save(false, array($this->pidAttribute, $this->posAttribute));
        }

        $this->owner->refresh();
    }

    public function insertAfter($id) {
        if(!$this->posAttribute) {
            throw new HttpException(500);
        }

        $db = $this->owner->getDb();

        if ($db->getTransaction()) {
            $transaction = null;
        } else {
            $transaction = $this->owner->getDb()->beginTransaction();
        }

        $target = $this->owner->findOne($id);

        if($this->pidAttribute && $target->{$this->pidAttribute} != $this->owner->{$this->pidAttribute}) $this->moveToInner($target->{$this->pidAttribute});

        $pos = $target->{$this->posAttribute};
        if($this->owner->findOne([$this->posAttribute=>$pos+1, $this->pidAttribute=>$target->{$this->pidAttribute}])) {
            $currentPos = $this->owner->{$this->posAttribute};
            $this->owner->{$this->posAttribute} = null;
            $db->createCommand()->update($this->owner->tableName(), [$this->posAttribute=>null], [$this->owner->primaryKey()[0]=>$this->owner->primaryKey])->execute();
            if($pos<$currentPos) {
                $db->createCommand("UPDATE {$this->owner->tableName()} SET `{$this->posAttribute}`=`{$this->posAttribute}`+1 WHERE `{$this->posAttribute}`>:pos1 AND `{$this->posAttribute}`<:pos2 AND `{$this->pidAttribute}`=:pid ORDER BY `{$this->posAttribute}` DESC", ['pos1'=>$pos, 'pos2'=>$currentPos, ':pid'=>$target->{$this->pidAttribute}])->execute();
                $this->owner->{$this->posAttribute} = $pos+1;
            } else {
                $db->createCommand("UPDATE {$this->owner->tableName()} SET `{$this->posAttribute}`=`{$this->posAttribute}`-1 WHERE `{$this->posAttribute}`>:pos1 AND `{$this->posAttribute}`<=:pos2 AND `{$this->pidAttribute}`=:pid ORDER BY `{$this->posAttribute}` ASC", ['pos1'=>$currentPos, 'pos2'=>$pos, ':pid'=>$target->{$this->pidAttribute}])->execute();
                $this->owner->{$this->posAttribute} = $pos;
            }
        } else {
            $this->owner->{$this->posAttribute} = $pos+1;
        }

        $db->createCommand()->update($this->owner->tableName(), [$this->posAttribute=>$this->owner->{$this->posAttribute}], [$this->owner->primaryKey()[0]=>$this->owner->primaryKey])->execute();

        if($this->bPathAttribute) {
            $command = $this->owner->getDb()->createCommand("
DROP TABLE IF EXISTS _zcupfro;
CREATE TEMPORARY TABLE _zcupfro AS (SELECT {$this->pidAttribute}, {$this->bPathAttribute}, {$this->posAttribute} FROM {$this->owner->tableName()});
UPDATE {$this->owner->tableName()}
SET
`{$this->bPathAttribute}`=CONCAT(
    :pbpath,
    (SELECT
      LPAD(
        CHAR(_zcupfro.{$db->quoteColumnName($this->posAttribute)}),
        ".self::BPATH_LEN.",
        CHAR(0)
      )
      FROM _zcupfro
      WHERE
        _zcupfro.`{$this->bPathAttribute}`=SUBSTRING({$this->owner->tableName()}.`{$this->bPathAttribute}`, 1, LENGTH(:pbpath)+".self::BPATH_LEN.")
      LIMIT 1
    ),
    SUBSTRING(
        `{$this->bPathAttribute}`,
        LENGTH(:pbpath)+".self::BPATH_LEN."+1
    )
)
WHERE `{$this->bPathAttribute}` LIKE CONCAT(:pbpath, '%') AND `{$this->bPathAttribute}`!=:pbpath;");
            $command->bindValue(':pbpath', $target->parent->bpath, \PDO::PARAM_LOB);
            $command->execute();
            $command->pdoStatement->closeCursor();
        }

        if ($transaction)
            $transaction->commit();
    }

    public function insertBefore($id) {
        if(!$this->posAttribute) {
            throw new HttpException(500);
        }

        $db = $this->owner->getDb();

        if ($db->getTransaction()) {
            $transaction = null;
        } else {
            $transaction = $this->owner->getDb()->beginTransaction();
        }

        $target = $this->owner->findOne($id);

        if($this->pidAttribute && $target->{$this->pidAttribute} != $this->owner->{$this->pidAttribute}) $this->moveToInner($target->{$this->pidAttribute});

        $pos = $target->{$this->posAttribute};
        if($this->owner->findOne([$this->posAttribute=>$pos-1, $this->pidAttribute=>$target->{$this->pidAttribute}]) || $pos<=1) {
            $currentPos = $this->owner->{$this->posAttribute};
            $this->owner->{$this->posAttribute} = null;
            $db->createCommand()->update($this->owner->tableName(), [$this->posAttribute=>null], [$this->owner->primaryKey()[0]=>$this->owner->primaryKey])->execute();
            if($pos<$currentPos) {
                $db->createCommand("UPDATE {$this->owner->tableName()} SET `{$this->posAttribute}`=`{$this->posAttribute}`+1 WHERE `{$this->posAttribute}`>=:pos1 AND `{$this->posAttribute}`<:pos2 AND `{$this->pidAttribute}`=:pid ORDER BY `{$this->posAttribute}` DESC", ['pos1'=>$pos, 'pos2'=>$currentPos, ':pid'=>$target->{$this->pidAttribute}])->execute();
                $this->owner->{$this->posAttribute} = $pos;
            } else {
                $db->createCommand("UPDATE {$this->owner->tableName()} SET `{$this->posAttribute}`=`{$this->posAttribute}`-1 WHERE `{$this->posAttribute}`>:pos1 AND `{$this->posAttribute}`<:pos2 AND `{$this->pidAttribute}`=:pid ORDER BY `{$this->posAttribute}` ASC", ['pos1'=>$currentPos, 'pos2'=>$pos, ':pid'=>$target->{$this->pidAttribute}])->execute();
                $this->owner->{$this->posAttribute} = $pos-1;
            }
        } else {
            $this->owner->{$this->posAttribute} = $pos-1;
        }

        $db->createCommand()->update($this->owner->tableName(), [$this->posAttribute=>$this->owner->{$this->posAttribute}], [$this->owner->primaryKey()[0]=>$this->owner->primaryKey])->execute();

        if($this->bPathAttribute) {
            $command = $this->owner->getDb()->createCommand("
DROP TABLE IF EXISTS _zcupfro;
CREATE TEMPORARY TABLE _zcupfro AS (SELECT {$this->pidAttribute}, {$this->bPathAttribute}, {$this->posAttribute} FROM {$this->owner->tableName()});
UPDATE {$this->owner->tableName()}
SET
`{$this->bPathAttribute}`=CONCAT(
    :pbpath,
    (SELECT
      LPAD(
        CHAR(_zcupfro.{$db->quoteColumnName($this->posAttribute)}),
        ".self::BPATH_LEN.",
        CHAR(0)
      )
      FROM _zcupfro
      WHERE
        _zcupfro.`{$this->bPathAttribute}`=SUBSTRING({$this->owner->tableName()}.`{$this->bPathAttribute}`, 1, LENGTH(:pbpath)+".self::BPATH_LEN.")
      LIMIT 1
    ),
    SUBSTRING(
        `{$this->bPathAttribute}`,
        LENGTH(:pbpath)+".self::BPATH_LEN."+1
    )
)
WHERE `{$this->bPathAttribute}` LIKE CONCAT(:pbpath, '%') AND `{$this->bPathAttribute}`!=:pbpath;");
            $command->bindValue(':pbpath', $target->parent->bpath, \PDO::PARAM_LOB);
            $command->execute();
            $command->pdoStatement->closeCursor();
        }

        if ($transaction)
            $transaction->commit();
    }

    public function deleteRecursive() {
        if(!$this->pidAttribute) {
            throw new HttpException(500);
        }

        if($rows = $this->owner->findAll([$this->pidAttribute=>$this->owner->{$this->owner->primaryKey}])) {
            foreach($rows as $row) {
                if(!$row->deleteRecursive()) return false;
            }
        }
        if(!$this->owner->delete()) return false;
        return true;
    }

    public function getNParent($level) {
        if(!$this->pidAttribute) {
            throw new HttpException(500);
        }

        $path = $this->getPath();
        $model = $this->owner;
        return $model::findOne($path[$level]);
    }

    public function getPath() {
        if(!$this->pidAttribute) {
            throw new HttpException(500);
        }

        if($this->bPathAttribute) {
            $model = $this->owner;
            $pos = array();
            $bpath = $this->owner->{$this->bPathAttribute};
            $parts = str_split($bpath, self::BPATH_LEN);
            foreach($parts as $part) {
                $pos[] = ord(ltrim($part, chr(0)));
            }
            return (array)$model::getDb()->createCommand('SELECT '.$model::primaryKey()[0].' FROM '.$model::tableName().' WHERE pos IN("'.implode('","', $pos).'") ORDER BY FIELD(`'.$this->posAttribute.'`, "'.implode('","', $pos).'")')->queryColumn();
        } else {
            $parent = $this->owner;
            $res = array($parent->primaryKey);
            while($parent=$parent->parent) {
                array_unshift($res, $parent->primaryKey);
            }
            return $res;
        }
    }

    public function getParent() {
        if(!$this->pidAttribute) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->findOne($model->{$this->pidAttribute});
    }

    public function getPrev() {
        if(!$this->posAttribute) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->find()->where(['<', $this->posAttribute, $model->{$this->posAttribute}])->orderBy($this->posAttribute.' DESC')->one();
    }

    public function getNext() {
        if(!$this->posAttribute) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->find()->where(['>', $this->posAttribute, $model->{$this->posAttribute}])->orderBy($this->posAttribute.' ASC')->one();
    }

    /**
     * @param $numbers int[] Array of position, include self position
     * @return string
     * @throws HttpException
     */
    public static function toBase255($numbers)
    {
        $c0 = chr(0);
        $toLen = 256;
        $res = array();

        foreach($numbers as $base10) {
            $base255='';
            if ($base10<$toLen) {
                $base255 = str_pad(chr($base10), self::BPATH_LEN, $c0, STR_PAD_LEFT);
            } else {
                while($base10 != '0')
                {
                    $base255 = chr(bcmod($base10,$toLen)).$base255;
                    $base10 = bcdiv($base10,$toLen,0);
                }

                if(strlen($base255)>self::BPATH_LEN) {
                    throw new HttpException(500);
                }
            }

            $res[] = str_pad($base255, self::BPATH_LEN, $c0, STR_PAD_LEFT);
        }

        return implode('', $res);
    }
}