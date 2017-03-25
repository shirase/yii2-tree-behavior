<?php

namespace shirase\tree;

use yii\base\Behavior;
use yii\web\HttpException;

/**
 * Class TreeQueryBehavior
 * @package shirase\tree
 */
class TreeQueryBehavior extends Behavior {

    /**
     * Query all children of parent
     * @param $parent_id Parent ID
     * @return \yii\base\Component
     */
    public function children($parent_id)
    {
        /**
         * @var \yii\db\ActiveQuery $query
         * @var \yii\db\ActiveRecord $model
         */
        $query = $this->owner;
        $model = new $this->owner->modelClass();
        $db = $model->getDb();

        if(!$model->bPathAttribute) {
            throw new HttpException(500, 'bPathAttribute not sets');
        }

        $query->andWhere($db->quoteColumnName($model->bPathAttribute).' LIKE CONCAT((SELECT '.$db->quoteColumnName($model->bPathAttribute).' FROM '.$model->tableName().' WHERE '.$db->quoteColumnName($model->primaryKey()[0]).'=:parent), "%")', [':parent'=>$parent_id]);
        $query->andWhere($db->quoteColumnName($model->primaryKey()[0]).'!=:parent', [':parent'=>$parent_id]);

        return $query;
    }
} 