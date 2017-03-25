<?php
/**
 * Created by PhpStorm.
 * User: Andrey
 * Date: 25.03.2017
 * Time: 22:43
 */

namespace tests\models;

use yii\db\ActiveQuery;

class ItemQuery extends ActiveQuery
{
    public function behaviors() {
        return [
            'shirase\tree\TreeQueryBehavior'
        ];
    }
}