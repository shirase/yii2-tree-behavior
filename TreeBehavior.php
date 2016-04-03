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
            ActiveRecord::EVENT_BEFORE_INSERT=>'beforeSave',
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
            $this->owner->{$this->bpath} = $this->makeBpath();
        }
    }

    private function makeBpath() {
        $bpath = '';
        $parent = $this->owner;
        $path[] = array($parent->{$this->pos});
        while($parent=$parent->getParent()) {
            if($bpath = $parent->{$this->bpath}) {
                break;
            }
            array_unshift($path, $parent->{$this->pos});
        }
        return $bpath.self::toBase255($path);
    }

    private function updateChildBpath($bpath) {
        if($this->bpath) {
            $model = $this->owner;
            while($this->owner->getDb()->createCommand('UPDATE '.$model::tableName().' SET `'.$this->bpath.'`=CONCAT((SELECT(`'.$this->bpath.'`) FROM (SELECT id, bpath FROM '.$model::tableName().') AS p WHERE t.`'.$this->pid.'=p.id`), LPAD(CHAR(`'.$this->pos.'`), '.self::BPATH_LEN.', CHAR(0))) WHERE `'.$this->bpath.'` LIKE :bpath ORDER BY `'.$this->bpath.'`')->execute([':bpath'=>$bpath.'%'])) {}
        }
    }

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
            $this->updateChildBpath($this->owner->{$this->bpath});
            $this->owner->{$this->bpath} = $this->makeBpath();
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
            $this->owner->{$this->bpath} = $this->makeBpath();
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
            $this->owner->{$this->bpath} = $this->makeBpath();
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

    private $_modelpath = array();
    public function getModelByLevel($level) {
        if(!$this->pid) {
            throw new HttpException(500);
        }

        if($this->_modelpath) return $this->_modelpath[$level];

        $model = $this->owner;
        array_unshift($this->_modelpath, $model);
        while($model->{$this->pid}) {
            $model = $model->findOne($model->{$this->pid});
            array_unshift($this->_modelpath, $model);
        }

        return $this->_modelpath[$level];
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
