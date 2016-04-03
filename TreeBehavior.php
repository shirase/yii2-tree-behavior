<?php
namespace shirase\tree;

use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\web\HttpException;
use yii\db\Expression;

/**
 * Class TreeBehavior
 * @package shirase\tree
 * @property \yii\db\ActiveRecord $owner
 */
class TreeBehavior extends Behavior {

    public $pid = 'pid';
    public $bpath = 'bpath';
    public $pos = 'pos';

    const BPATH_LEN = 4;

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_DELETE=>'beforeDelete',
            ActiveRecord::EVENT_BEFORE_INSERT=>'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE=>'beforeSave',
        ];
    }

    public function beforeDelete($event) {
        if($this->pid && $this->owner->findOne([$this->pid=>$this->owner->primaryKey])) {
            $event->isValid = false;
        }
    }

    public function beforeSave($event) {
        if(!$this->owner->{$this->pos}) {
            $this->owner->{$this->pos} = $this->maxPos()+1;
        }

        if($this->bpath && !$this->owner->{$this->bpath}) {
            $this->owner->{$this->bpath} = $this->makeBPath();
        }
    }

    private function makeBPath() {
        $bpath = '';
        $parent = $this->owner;
        $path = array($parent->{$this->pos});
        while($parent=$parent->getParent()) {
            if($bpath = $parent->{$this->bpath}) {
                break;
            }
            array_unshift($path, $parent->{$this->pos});
        }
        return $bpath.self::toBase255($path);
    }

    /*public function buildChildBPath($bpath='') {
        if(!$this->bpath || !$this->pid || !$this->pos) {
            throw new HttpException(500, 'Required bpath, pid, pos');
        }

        $model = $this->owner;
        $this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' SET `'.$this->bpath.'`=CONCAT(:bpath, LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0))) WHERE `'.$this->bpath.'` LIKE :bpath', [':bpath'=>$bpath])->execute();
        while($this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' AS t SET `'.$this->bpath.'`=CONCAT((SELECT `'.$this->bpath.'` FROM (SELECT * FROM '.$model::tableName().') AS p WHERE p.'.$model::primaryKey()[0].'=t.`'.$this->pid.'`), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0))) WHERE `'.$this->bpath.'` LIKE :bpath AND pid=0 ORDER BY `'.$this->bpath.'`', [':bpath'=>$bpath.'%'])->execute()) {}
    }*/

    private function maxPos() {
        $model = $this->owner;
        return $model->getDb()->createCommand('SELECT MAX(`'.$this->pos.'`) FROM '.$model::tableName())->queryScalar();
    }

    public function moveTo($to) {
        if(!$this->pos || !$this->pid) {
            throw new HttpException(500);
        }
        $this->owner->{$this->pos} = $this->maxPos()+1;
        $this->owner->{$this->pid} = $to;

        if($this->bpath) {
            $model = $this->owner;
            $bpathOld = $this->owner->{$this->bpath};
            $this->owner->{$this->bpath} = $this->makeBPath();
            $this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' SET `'.$this->bpath.'`=CONCAT(:bpath, RIGHT(`'.$this->bpath.'`, LENGTH(`'.$this->bpath.'`)-LENGTH(:bpathOld))) WHERE `'.$this->bpath.'` LIKE :bpathOld', [':bpath'=>$this->owner->{$this->bpath}, ':bpathOld'=>$bpathOld.'%'])->execute();
            $this->owner->save(false, array($this->pid, $this->pos, $this->bpath));
        } else {
            $this->owner->save(false, array($this->pid, $this->pos));
        }

        $this->owner->refresh();
    }

    public function insertAfter($id) {
        if(!$this->pos) {
            throw new HttpException(500);
        }

        $target = $this->owner->findOne($id);

        if($this->pid && $target->{$this->pid} != $this->owner->{$this->pid}) $this->moveTo($target->{$this->pid});

        $pos = $target->{$this->pos};
        if($this->owner->findOne([$this->pos=>$pos+1])) {
            if($pos<$this->owner->{$this->pos}) {
                $this->owner->updateAll(array($this->pos=>new Expression('`'.$this->pos.'`+1')), $this->pos.'>:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$pos, 'pos2'=>$this->owner->{$this->pos}]);
                if($this->bpath) {
                    $this->owner->updateAll(array($this->bpath=>new Expression('CONCAT(LEFT(`'.$this->bpath.'`, LENGTH(`'.$this->bpath.'`)-'.self::BPATH_LEN.'), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0)))')), $this->pos.'>:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$pos, 'pos2'=>$this->owner->{$this->pos}]);
                }
                $this->owner->{$this->pos} = $pos+1;
            } else {
                $this->owner->updateAll(array($this->pos=>new Expression('`'.$this->pos.'`-1')), $this->pos.'>:pos1 AND '.$this->pos.'<=:pos2', ['pos1'=>$this->owner->{$this->pos}, 'pos2'=>$pos]);
                if($this->bpath) {
                    $this->owner->updateAll(array($this->bpath=>new Expression('CONCAT(LEFT(`'.$this->bpath.'`, LENGTH(`'.$this->bpath.'`)-'.self::BPATH_LEN.'), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0)))')), $this->pos.'>:pos1 AND '.$this->pos.'<=:pos2', ['pos1'=>$this->owner->{$this->pos}, 'pos2'=>$pos]);
                }
                $this->owner->{$this->pos} = $pos;
            }
        } else {
            $this->owner->{$this->pos} = $pos+1;
        }

        if($this->bpath) {
            $this->owner->{$this->bpath} = $this->makeBPath();
            $this->owner->save(false, array($this->pos, $this->bpath));
        } else {
            $this->owner->save(false, array($this->pos));
        }
    }

    public function insertBefore($id) {
        if(!$this->pos) {
            throw new HttpException(500);
        }

        $target = $this->owner->findOne($id);

        if($this->pid && $target->{$this->pid} != $this->owner->{$this->pid}) $this->moveTo($target->{$this->pid});

        $pos = $target->{$this->pos};
        if($this->owner->find([$this->pos=>$pos-1]) || $pos<=1) {
            if($pos<$this->owner->{$this->pos}) {
                $this->owner->updateAll(array($this->pos=>new Expression('`'.$this->pos.'`+1')), $this->pos.'>=:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$pos, 'pos2'=>$this->owner->{$this->pos}]);
                if($this->bpath) {
                    $this->owner->updateAll(array($this->bpath=>new Expression('CONCAT(LEFT(`'.$this->bpath.'`, LENGTH(`'.$this->bpath.'`)-'.self::BPATH_LEN.'), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0)))')), $this->pos.'>=:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$pos, 'pos2'=>$this->owner->{$this->pos}]);
                }
                $this->owner->{$this->pos} = $pos;
            } else {
                $this->owner->updateAll(array($this->pos=>new Expression('`'.$this->pos.'`-1')), $this->pos.'>:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$this->owner->{$this->pos}, 'pos2'=>$pos]);
                if($this->bpath) {
                    $this->owner->updateAll(array($this->bpath=>new Expression('CONCAT(LEFT(`'.$this->bpath.'`, LENGTH(`'.$this->bpath.'`)-'.self::BPATH_LEN.'), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0)))')), $this->pos.'>:pos1 AND '.$this->pos.'<:pos2', ['pos1'=>$this->owner->{$this->pos}, 'pos2'=>$pos]);
                }
                $this->owner->{$this->pos} = $pos-1;
            }
        } else {
            $this->owner->{$this->pos} = $pos-1;
        }

        if($this->bpath) {
            $this->owner->{$this->bpath} = $this->makeBPath();
            $this->owner->save(false, array($this->pos, $this->bpath));
        } else {
            $this->owner->save(false, array($this->pos));
        }
    }

    public function deleteRecursive() {
        if(!$this->pid) {
            throw new HttpException(500);
        }

        if($rows = $this->owner->findAll([$this->pid=>$this->owner->{$this->owner->primaryKey}])) {
            foreach($rows as $row) {
                if(!$row->deleteRecursive()) return false;
            }
        }
        if(!$this->owner->delete()) return false;
        return true;
    }

    public function getModelByLevel($level) {
        if(!$this->pid) {
            throw new HttpException(500);
        }

        $path = $this->getPath();
        $model = $this->owner;
        return $model::findOne($path[$level]);
    }

    public function getPath() {
        if(!$this->pid) {
            throw new HttpException(500);
        }

        if($this->bpath) {
            $model = $this->owner;
            $pos = array();
            //$bpath = $this->owner->{$this->bpath};
            $bpath = $model::getDb()->createCommand('SELECT '.$this->bpath.' FROM '.$model::tableName().' WHERE '.$model::primaryKey()[0].'=:id', [':id'=>$this->owner->primaryKey])->queryScalar();
            $parts = str_split($bpath, self::BPATH_LEN);
            foreach($parts as $part) {
                $pos[] = ord(ltrim($part, chr(0)));
            }
            return (array)$model::getDb()->createCommand('SELECT '.$model::primaryKey()[0].' FROM '.$model::tableName().' WHERE pos IN("'.implode('","', $pos).'") ORDER BY FIELD(`'.$this->pos.'`, "'.implode('","', $pos).'")')->queryColumn();
        } else {
            $parent = $this->owner;
            $res = array($parent->primaryKey);
            while($parent=$parent->parant) {
                array_unshift($res, $parent->primaryKey);
            }
            return $res;
        }
    }

    public function getParent() {
        if(!$this->pid) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->findOne($model->{$this->pid});
    }

    public function getPrev() {
        if(!$this->pos) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->find()->where(['<', $this->pos, $model->{$this->pos}])->orderBy($this->pos.' DESC')->one();
    }

    public function getNext() {
        if(!$this->pos) {
            throw new HttpException(500);
        }
        $model = $this->owner;
        return $model->find()->where(['>', $this->pos, $model->{$this->pos}])->orderBy($this->pos.' ASC')->one();
    }

    private static function toBase255($numbers)
    {
        $c0 = chr(0);
        $toLen = 255;
        $res = array();

        foreach($numbers as $base10) {
            $base255='';
            if ($base10<=$toLen) {
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
