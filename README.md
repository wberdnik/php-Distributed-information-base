# Data exhange component for Yii2

The Data exchange component for replication entityes between yii2 sites with pattern similarly 1C:Distributed information base

ExchangePlan
------------

ExchangePlan class extending the ActiveRecord class is a Model to replicate changes and exchanges entityes. One implements ExchangeInterface with methoods for
registration of changes(touch, multiTouch), procesing a exchage packet (fetch, apply), acknowledgment (acknowledge), other (addExchError)

SerializableActiveRecord
------------------------

SerializableActiveRecord class extending the ActiveRecord class is a parent class for entity models who participate in exchange. This class implements the SerializationInterface. Child classes should configure the lists of attributes to be passed and how to serialize them.

Excluded on public
------------------
Classes: ExchangeControllerBase, ExchangeAction, command/ExchangeController, helper/CryptoHelper. File: config
I'm sorry


Example
-------
Controller of the destination side

```PHP
<?php

namespace app\modules\example\controllers;

use Yii;
use app\modules\example\models\Entity;
use yii\web\Controller;
use yii\filters\AccessControl;

class ExampleController extends Controller {

    /**
     * {@inheritdoc}
     */
    public function behaviors() {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'actions' => ['transfer'],
                        'allow' => true,
                    ]

                ],
            ],
        ];
    }

    public function actions() {
        return [
            'transfer' => [
                'class' => 'app\components\DBexchangePlan\ExchangeAction', // or other path where u replace the ExchangeAction
             ],
        ];
    }

    public function beforeAction($action) {
        if ($action->id == 'transfer') {
            $this->enableCsrfValidation = false;
            $this->layout = false;           
        }
        return parent::beforeAction($action);
    }
}
```

Entity model

```PHP
<?php
namespace app\modules\example\models;

use app\components\DBexchangePlan\SerializableActiveRecord;

/**
 * This is the model class for table "{{%example}}".
 *
 * @property int $id
 * @property string $name - payload
 * @property int $active - simulate deletion
 */
class Example extends SerializableActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%example}}';
    }

           
    /**
     * Atrributes for exchange
     */
   public function Attributes2Send(string $node): array{
       return ['name', 'active'];
   }
   
   /** Action when a deleting other side 
   *
   */
   public static function onRemoteDeletes(array $uids, string $node): void{
       self::updateAll(['active' => 0], ['uid' => $uids]);
   }
   
   /** Getter of unmutable a entity name
   *
   */
   public static function ModelUniversalName(string $node): string{
       return 'onCreate_Test';
   }
    
    // Regular functions of the ActiveRecord
    
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['active'], 'integer'],
            [['name'], 'string', 'max' => 200],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Строковое поле',
            'active' => 'Active',
        ];
    }
}
```
