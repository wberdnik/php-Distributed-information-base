<?php

use yii\db\Migration;
use yii\db\Schema;

/**
 * Class m201110_120918_exchange_plan
 */
class m201110_120918_exchange_plan extends Migration {

    private $tableExchangePlan = '{{%excange_plan}}';

    public function up() {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_general_ci ENGINE=InnoDB';
        }

        $this->createTable($this->tableExchangePlan, [
             'id' => Schema::TYPE_PK,
            'node' => Schema::TYPE_STRING . '(100) NOT NULL', //+
            'className' => Schema::TYPE_STRING . '(512) DEFAULT NULL', //+         
            'uid' => Schema::TYPE_STRING . '(36) DEFAULT NULL',
            'queueNumber' => $this->integer()->notNull(),
            'canary' => $this->integer(),
            'special' => $this->integer()->notNull(),
       
           
                ], $tableOptions);
        
         $this->createIndex('inx_exchange_type', $tableExchangePlan, ['special',], FALSE); 
         $this->createIndex('inx_exchange_one_record', $tableExchangePlan, ['special', 'uid', 'className'], FALSE); 
         $this->createIndex('inx_exchange_common', $tableExchangePlan, ['special', 'node', ], FALSE); 
        return true;
    }

    public function down() {
        $this->dropTable($this->tableExchangePlan);

        return true;
    }

}
