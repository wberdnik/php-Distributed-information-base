<?php

namespace app\components\DBexchangePlan;

use app\components\DBexchangePlan\interfaces\SerializationInterface;
use app\helpers\CryptoHelper;
use yii\db\ActiveRecord;
use yii\web\HttpException;

/**
 * Родительский класс для моделей, которые позволяют обмениваться данными 
 *
 * @author <wberdnik@gmail.com>
 */
abstract class SerializableActiveRecord extends ActiveRecord implements SerializationInterface {

    private $_RegisterPlanObj = true;
    private static $bks = [];

    
    /** При однонаправленном обмене, получатель может отключать регистрацию в плане обмена? переопределив этот метод
      * @var bool 
     */
    public static function toRegisterPlan() {
        return true;
    }

    
    /** При однонаправленном обмене, получатель может отключать генерацию бизнесс-ключа
     *
     * @var bool 
     */
    public static function toGenerateUID() {
        return true;
    }

        
    /**
     * {@inheritdoc} 
     */
    abstract public static function ModelUniversalName(string $node): string;

    
    /**
     * {@inheritdoc}
     */
    public static function decode(array $jPack, string $producer = ''): ?SerializationInterface {
        $uName = static::ModelUniversalName($producer);
        if (empty($jPack[$uName])) {
            return null;
        }
        $data = $jPack[$uName];

        if (empty($data['uid'])) { // нет uid - нет РИБ
            return null;
        }
        $uid = $data['uid'];
        // убираем зацепление пользовательского кода от бизнесс ключей
        $bkColumns = static::getBkColumns();
        foreach ($data as $key => $value) {
            if (in_array($key, $bkColumns)) {
                unset($data[$key]);
            }
        }
        $AR = static::findOne([static::getColumnBusinesKey($producer) => $uid]);
        if ($rez = static::assigmentValues($AR, $data, $producer)) {
            // сами ставим бизнес ключи
            $rez[static::getColumnBusinesKey($producer)] = $uid;
            return $rez;
        }

        return null;
    }

    
    /** Заполнение полей при получении
     * 
     * @param SerializationInterface|null $AR  Если NULL - новая запись, иначе найдена в БД
     * @param array $values - ключ-значение массив
     * @param string $producer - откуда пришли данные
     * @return SerializationInterface|null - AR для записи или NULL если запись не требуется
     */
    public static function assigmentValues(?SerializationInterface $AR, array $values, string $producer): ?SerializationInterface {
        if ($AR) { //founded ActiveRecord
            foreach ($values as $key => $value) {
                $AR->$key = $value;
            }
            return $AR;
        } else { // Inserted Active Record
            $className = static::className();
            return new $className($values);
        }
    }


    /** Маппит Бизнес-ключ на локальное значение Primary key
     * 
     * @param array $values - входной пакет
     * @param array $translateMap Пример ['city_id' => \app\models\City::className(),]
     * @param string $thisNode - для коммента Текущий узел
     * @param string $producerNode - от куда пришел пакет (для определения имени uid)
     * @return array $values
     */
    public static function uid2id4Assigments(
            array $values,
            array $translateMap,
            string $thisNode,
            string $producerNode,
            bool $showErrors = true): array {
        foreach ($translateMap as $field => $className) {
            if (empty($values[$field])) {
                if ($showErrors) {
                    ExchangePlan::addExchError(static::className() . ' Пришла на ' . $thisNode . ' из ' . $producerNode . ' запись без ' . $field . "\n" . var_export($values, true));
                }
                $values[$field] = null;
                continue;
            }
            if (!$model = $className::findOne([$className::getColumnBusinesKey($producerNode) => $values[$field]])) {
                if ($showErrors) {
                    ExchangePlan::addExchError(static::className() . ' Не удалось на ' . $thisNode . ' из ' . $producerNode . ' востановить ' . $field . ' по ключу ' . $values[$field] . "\n" . var_export($values, true));
                }
                $values[$field] = null;
                continue;
            }
            $pk = $model->getTableSchema()->primaryKey[0];
            $values[$field] = $model->$pk;
        }
        return $values;
    }

    
    /**
     * Атрибуты для серилизации и отправки
     */
    public function Attributes2Send(string $node): array {
        $list = $this->attributes();
        $keys = $this->getTableSchema()->primaryKey;
        $keys2 = self::getBkColumns();
        
        $ans =[]; // глючит по человечьи
        foreach ($list as $key) {
            if(in_array($key, $keys)){
                continue;
            }
            if(in_array($key, $keys2)){
                continue;
            }
            $ans[] = $key;
        }      
        return $ans;
    }

    
    /** Действие, если другая сторона удалила сущность
     * 
     */
    abstract public static function onRemoteDeletes(array $uids, string $node): void;

    
    /** Идентификаторы сайтов Yii, которые получают пакеты обмена из текущего
     * @return array of string Список получателей. Если null - ВСЕ получатели из config
     */
    public static function shallTouchCustomers(): ?array {
        return null;
    }

    
    /** Универсальный идентификатор
     * 
     * @return string - универсальная ссылка, из типа (UniversalName) и бизнес ключа
     */
    public function getULink(string $node): string {
        return static::getModelUniversalName($node) . ':::' . $this->getBusinesKeyValue($node);
    }

    
    /**
     * {@inheritdoc}
     */
    public function beforeSave($insert) {
        if (static::toGenerateUID() && $insert) {
            $bks = static::getBkColumns();
            foreach ($bks as $bk) {
                if (empty($this->$bk)) {
                    $this->$bk = $this->newBusinesKey($bk);
                }
            }
        }
        return parent::beforeSave($insert);
    }

    
    /**
     * {@inheritdoc}
     */
    public function getPlan() {
        return $this->hasMany(ExchangePlan::className(), ['uid' => static::getColumnBusinesKey()]);
    }

    
    /**
     * {@inheritdoc}
     */
    public function afterSave($insert, $changedAttributes) {
        if (static::toRegisterPlan() && $this->_RegisterPlanObj) {
            ExchangePlan::touch($insert, $this);
        } else {
            \Yii::info('Skip touch Plan because $this->_RegisterPlanObj: ' . $this->_RegisterPlanObj . ' static::toRegisterPlan:' . static::toRegisterPlan());
        }
        parent::afterSave($insert, $changedAttributes);
    }

    
    /** Запись модели без регистрации в плане обмена
     * 
     */
    public function pureSave(bool $runValidation = true, ?array $attributeNames = null): bool {
        $this->_RegisterPlanObj = false;
        $res = parent::save($runValidation, $attributeNames);
        $this->_RegisterPlanObj = true;
        // ExchangePlan::addExchError(static::className(). 'Записан при обмене объект ('.$res.')  '.var_export($this,true));
        return $res;
    }

    
    /**
     * {@inheritdoc}
     */
    public static function updateAll($attributes, $condition = '', $params = array()) {
        if (static::toRegisterPlan()) {
            ExchangePlan::multiTouch($condition, static::class);
        }
        return parent::updateAll($attributes, $condition, $params);
    }

    
    /**
     * {@inheritdoc}
     */
    public static function deleteAll($condition = null, $params = array()) {
        if (static::toRegisterPlan()) {
            ExchangePlan::multiTouch($condition, static::class);
        }
        return parent::deleteAll($condition, $params);
    }

    
    /**
     * {@inheritdoc}
     */
    public function beforeDelete() {
        if (static::toRegisterPlan()) {
            ExchangePlan::touch(false, $this);
        }
        return parent::beforeDelete();
    }

    
    /** Геттер списка полей для генерации бизнес-ключа
     * 
     * @return array - список всех КОЛОНОК бизнес-ключей, которые должны быть в таблице
     */
    protected static function getBkColumns() {
        $cln = static::className();
        if (!empty(self::$bks[$cln])) {
            return self::$bks[$cln];
        }
        $config = include __DIR__ . '/config.php';
        self::$bks [$cln] = array_unique(array_map(
                        function($item) {
                    return static::getColumnBusinesKey($item);
                },
                        $config['consumers']))
        ;
        return self::$bks [$cln];
    }

    
     /**
     * {@inheritdoc}
     */
    public function encode(string $node): array {
        $list = $this->Attributes2Send($node);
        $bk = static::getColumnBusinesKey($node);
        $pack = $this->readAR($list, $node);
        $pack[$bk] = $this->$bk;
        return [static::ModelUniversalName($node) => $pack];
    }

    
    /** Чтение модели при отправке данных
     * 
     * @param array $list - ожидаемый список полей отправки
     * @return array - массив атрибут->значение
     */
    public function readAR(array $list, string $node): array {
        $pack = [];
        foreach ($list as $attr) {
            $pack[$attr] = $this->$attr;
        }
        return $pack;
    }

    
    /** Расчет бизнес-ключа по атрибутам
     * 
     * @return string
     */
    protected function newBusinesKey(string $column): string {
        return CryptoHelper::getGUID();
    }

    
    /**
     * {@inheritdoc}
     */
    public function getBusinesKeyValue(string $node): ?string {
        $bk = static::getColumnBusinesKey($node);
        return $this->$bk;
    }

    
    /** Имя бизнес-ключа в модели
     * 
     * @return string - имя атрибута бизнес ключа для участника, Если $node = '', то тот, который должен показываться по внешнему ключу getPlan
     */
    public static function getColumnBusinesKey(string $node = ''): string {
        return 'uid';
    }

}
