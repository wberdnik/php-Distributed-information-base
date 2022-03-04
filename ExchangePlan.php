<?php

namespace app\components\DBexchangePlan;

use app\components\DBexchangePlan\interfaces\ExchangeInterface;
use app\components\DBexchangePlan\interfaces\SerializationInterface;
use Exception;
use Throwable;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Transaction;
use yii\helpers\ArrayHelper;
use yii\web\HttpException;

/**
 * This is the model class for table a ExchangePlan
 */
class ExchangePlan extends ActiveRecord implements ExchangeInterface {

	/** enum of kind of record
	*
	*/
    CONST SPEC_REGULAR = 0;
    CONST SPEC_SENDED = 1;
    CONST SPEC_OUT_NUMBER = 2;
    CONST SPEC_IN_NUMBER = 3;
    CONST SPEC_ALL_WITH_UID = [self::SPEC_REGULAR, self::SPEC_SENDED];
    CONST NONE_SEND = -1;

    private static $config = null;
    private static $errors = [];

	
    /**
     * @inheritdoc
     */
    public function init() {
        self::reconfig();
        parent::init();
    }

	
    /**
     * @inheritdoc
     */
    public static function tableName() {
        return '{{%excange_plan}}';
    }

	
    /**
     * @inheritdoc
     */
    public function rules() {
        return [
            [['node'], 'required'],
            [['queueNumber', 'special', 'canary'], 'integer'],
            [['node',], 'string',],
            [['className',], 'string', 'default', 'value' => ''],
            [['uid'], 'string', 'max' => 36, 'default', 'value' => ''],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels() {
        return [
            'id' => 'ID',
            'node' => 'Имя узла РИБ из config',
            'className' => 'Путь к модели БД',
            'uid' => 'Универсальный идентификатор',
            'canary' => 'Просто случайное число от 0 до 100, единое для сущности. Позволяет конореечно отправлять данные по узлам',
            'queueNumber' => 'Номер отправляемого пакета',
            'special' => 'Признак специальной записи.CONST SPEC_*' //+ *
        ];
    }

	
    private static function reconfig(): void {
        if (!self::$config) {
            self::$config = include __DIR__ . '/config.php'; //$params = require(__DIR__ . '/params.php');           
        }
    }


    /** Регистрация ошибки при обмене
     * 
     * @param string $text
     * @return void
     */
    public static function addExchError(string $text): void {
        if (Yii::$app->request->isConsoleRequest) {
            echo "\nERROR: $text";
        } else {
            self::$errors[] = $text;
        }
    }

	
    /** Единый алгоритм touch для методов интерфейса
     * Внимание, должен быть настроен config
     * 
     * @param array $node2bks 
     * @param SerializationInterface $model
     * @param bool $insert
     * @return void
     */
    private static function _touch(array $node2bks, SerializationInterface $model, bool $insert = false): void {

        if (!count($node2bks)) {
            return;
        }

        // словари
        $bk2canary = [];
        $iHaveBk2nodes = [];
        if (!$insert) { // при insert-e мы ничего не найдем, нет смысла делать запрос
            $condition = ['OR'];
            foreach ($node2bks as $node => $bks) {
                $condition[] = ['node' => $node, 'uid' => array_filter($bks)];
            }

            $yetReq = self::find()->select(['id', 'node', 'canary', 'uid'])
                    ->where([
                        'className' => $model::className(),
                        'special' => self::SPEC_REGULAR,
                    ])->andWhere($condition)
                    ->all();


            foreach ($yetReq as $value) {
                $bk2canary[$value->uid] = $value->canary;
                if (empty($iHaveBk2nodes[$value->uid])) {
                    $iHaveBk2nodes[$value->uid] = [];
                }
                $iHaveBk2nodes[$value->uid][] = $value->node;
            }
        }

        $toTouchNodes = array_keys($node2bks);


        $data = [];

        foreach ($toTouchNodes as $node) {

            $bks = $node2bks[$node];
            foreach ($bks as $bk) {
                // нормализуем словарь для следующих нодов
                if (!isset($bk2canary[$bk])) {
                    $bk2canary[$bk] = rand(0, 100);
                }

                if (isset($iHaveBk2nodes[$bk]) && in_array($node, $iHaveBk2nodes[$bk])) {
                    continue;
                }
                // batchInsert журнала регистрации
                $data[] = [
                    $node,
                    $model::className(),
                    $bk,
                    0, //$node2queueNumber[$node] ?? 1,
                    self::SPEC_REGULAR,
                    $bk2canary[$bk],
                ];
            }
        }

        if (count($data)) {


            static::getDb()->createCommand()->batchInsert(self::tableName(),
                    [
                        'node',
                        'className',
                        'uid',
                        'queueNumber',
                        'special',
                        'canary',
                    ], $data)->execute();
        }
    }

	
    /** Массовая регистрация изменений моделей SerializationInterface  Model::UpdateAll, DeleteAll
     * 
     * @param array $condition - отбор по модели
     * @param string $className
     */
    public static function multiTouch(array $condition, string $className): void {

        $model = new $className;
        if (!$model instanceof SerializationInterface) {
            throw new Exception('Модель(' . $className . ') не имплементирует SerializationInterface ');
        }

        $insert = false;
        self::reconfig();

        if (!in_array($className, self::$config['modelsWithSerializationInterface'])) {
            throw new Exception('Модель с SerializationInterface (' . $className . ') не зарегистрирована в config.php DbExchangePlan');
        }

        // найдем затронутые uids

        $toCustomers = $model::shallTouchCustomers() ?? self::$config['consumers'];
        $node2bks = [];
        $columns2bks = []; //кэш - что бы не делать 100500 запросов.
        foreach ($toCustomers as $node) {
            $bkColumn = $model::getColumnBusinesKey($node);

            if (!isset($columns2bks[$bkColumn])) {//нет в кэше - добавим
                $columns2bks[$bkColumn] = ArrayHelper::getColumn($model::find()->where($condition)->
                                        andWhere(['NOT', ['OR', [$bkColumn => ''], [$bkColumn => null]]])->select(['id', $bkColumn])->all(),
                                $bkColumn);
            }
            if (count($columns2bks[$bkColumn])) {
                $node2bks[$node] = $columns2bks[$bkColumn];
            }
        }
        if (count($node2bks)) {
            self::_touch($node2bks, $model, $insert);
        }
    }

	
    /** Регистрация изменений/удалений моделей SerializationInterface
     * 
     * @param bool $insert - запись ActiveRecord в режиме insert
     * @param SerializationInterface $model
     * &&web
     */
    public static function touch(bool $insert, SerializationInterface $model): void {

        self::reconfig();

        if (!in_array($model::className(), self::$config['modelsWithSerializationInterface'])) {
            throw new Exception('Модель с SerializationInterface (' . $model::className() . ') не зарегистрирована в config.php DbExchangePlan');
        }
        $toCustomers = $model::shallTouchCustomers() ?? self::$config['consumers'];
        $node2bks = [];
        foreach ($toCustomers as $node) {
            $bk = $model->getBusinesKeyValue($node);
            if ($bk === null || trim($bk) === '') {
                continue;
            }
            $node2bks[$node] = [$bk];
        }
        if (count($node2bks)) {
            self::_touch($node2bks, $model, $insert);
        }
    }

	
    /** Получение номера с инициализацией
     * 
     * @param string $node узел
     * @param int $special - Константа - входящий/исходящий
     * @param int $defaultValue - начальное значение номера очереди входящий/исходящий
     */
    private static function getQueueAR(string $node, int $special, int $defaultValue): ExchangePlan {
        if (!$AR = self::find()->select(['id', 'queueNumber'])
                        ->where(['special' => $special, 'node' => $node,])->one()) {

            $AR = new ExchangePlan([
                'special' => $special,
                'node' => $node,
                'queueNumber' => $defaultValue,
            ]);
            $AR->save(false);
        }
        return $AR;
    }

	
    /** Извлечь массив для отправки
     * 
     * @param string $node - consumer
     * @param int $startCanary - начало интервала канарееечного отбора
     * @param int $endCanary - начало интервала канарееечного отбора
     */
    public static function fetch(string $node, int $startCanary = 0, int $endCanary = 100): ?array {

        self::reconfig();

        if (!in_array($node, self::$config['consumers'])) {
            self::CrashAndAlert('ExchangePlan::fetch Отправителя нет в списке customers в config.php');
        }
        self::deleteAll(['AND', // Нет uid - нет обмена
            ['special' => self::SPEC_ALL_WITH_UID,],
            ['OR', ['uid' => null,], ['uid' => '',],],
        ]);

        // Заберем кого поймали
        $notAckPlan = self::find()->where(
                        [
                            'special' => self::SPEC_REGULAR,
                            'node' => $node,
                            'className' => self::$config['modelsWithSerializationInterface'],
                        ]
                )
                ->all();

        // Нечего забирать - не будем инкрементировать номер пакета
        if (!count($notAckPlan)) {         
            if (Yii::$app->request->isConsoleRequest) {
                echo " => Send _empty_ packet";
            }

            $answerPack = self::getOutPackTemplate($node);
            $answerPack['packNumber'] = self::NONE_SEND;
            if (self::$errors) {
                $answerPack['errors'] = self::$errors;
                self::$errors = [];
            }

            // $answer['test'] = 'empty qeury '.$currentOutQueueNumber;
            return $answerPack;
        }


        // сделаем атомарность инкремента номера исходящего пакета
        $db = static::getDb();
        $transaction = $db->beginTransaction(Transaction::SERIALIZABLE);
        try {
            $QueuesAR = self::getQueueAR($node, self::SPEC_OUT_NUMBER, 1);

            $currentOutQueueNumber = $QueuesAR->queueNumber;

            // Сразу подвинем номер пакета, что бы другие могли регистрироваться уже с большим номером
            $QueuesAR->queueNumber = $currentOutQueueNumber + 1;
            $QueuesAR->save(false);

            $transaction->commit();
        } catch (Exception $e) {
            $transaction->rollBack();
            self::CrashAndAlert('ExchangePlan::fetch ошибка транзакции ' . var_export($e, true));
        } catch (Throwable $e) {
            $transaction->rollBack();
            self::CrashAndAlert('ExchangePlan::fetch ошибка транзакции ' . var_export($e, true));
        }

        if (Yii::$app->request->isConsoleRequest) {
            echo " => Send packet N WE$currentOutQueueNumber";
        }

        // рассортируем по моделям
        $PlanByTable_all_activeCanary = [];
        foreach ($notAckPlan as $rec) {
            if (!isset($PlanByTable_all_activeCanary[$rec->className])) {
                $PlanByTable_all_activeCanary[$rec->className] = ['activeCanary' => [], 'all' => []];
            }
            $PlanByTable_all_activeCanary[$rec->className]['all'][] = $rec->uid;

            if ($rec->canary >= $startCanary && $rec->canary <= $endCanary) {
                $PlanByTable_all_activeCanary[$rec->className]['activeCanary'][] = $rec->uid;
            }
        }

        // Формируем пакет обмена - заголовок
        $answerPack = self::getOutPackTemplate($node);
        $answerPack['packNumber'] = $currentOutQueueNumber;

        foreach ($PlanByTable_all_activeCanary as $className => $pairs) {
            if (empty($pairs['all'])) {
                continue;
            }
            $model = new $className;
            $uName = $model::ModelUniversalName($node);
            $bkColumn = $model::getColumnBusinesKey($node);

            $answerPack['data'][$uName] = [];
            $answerPack['remove'][$uName] = [];

            $tableData = $model::find()->where([$bkColumn => $pairs['all']])->all();

            foreach ($tableData as $candidate) {
                if (in_array($candidate->$bkColumn, $pairs['activeCanary'])) {
                    $answerPack['data'][$uName][$candidate->$bkColumn] = $candidate->encode($node);
                }
            }
            // Найдем удаленные
            $founded = ArrayHelper::getColumn($tableData, $bkColumn);
            foreach ($pairs['all'] as $uid) {
                if (!in_array($uid, $founded)) {
                    $answerPack['remove'][$uName][] = $uid;
                }
            }
        }

        // пометим как как отправленные но не подтвержденные
        self::updateAll([
            'special' => self::SPEC_SENDED,
            'queueNumber' => $currentOutQueueNumber,
                ],
                ['id' => ArrayHelper::getColumn($notAckPlan, 'id'),]
        );

        if (self::$errors) {
            $answerPack['errors'] = self::$errors;
            self::$errors = [];
        }

        return $answerPack;
    }

	
	
    /** Применить пакет
     * 
     * @param string $node - producer
     * @param array $pack - данные
     * @return int Номер квитанции подтверждения
     * &&web
     */
    public static function apply(string $node, array $pack): ?int {
        if ((int) $pack['packNumber'] == self::NONE_SEND) {
            return self::NONE_SEND;
        }

        if (empty($pack['packNumber']) || empty($pack['producer']) || empty($pack['customer'])) {
            self::CrashAndAlert('ExchangePlan::apply Неверный заголовка у пакета');
        }

        if ($pack['producer'] !== $node) {
            self::CrashAndAlert('ExchangePlan::apply Отправитель в пакете и отправитель транспортного модуля не совпадают');
        }

        self::reconfig();

        if (!in_array($node, self::$config['consumers'])) {
            self::CrashAndAlert('ExchangePlan::apply Отправителя нет в списке customers в config.php');
        }

        if ($pack['customer'] !== self::$config['root']) {
            self::CrashAndAlert('ExchangePlan::apply Имя текущего узла не соответствует получателю в пакете');
        }

        // Текущий входящий номер 
        $QueueAR = self::getQueueAR($node, self::SPEC_IN_NUMBER, 0);

        $currentInQueueNumber = $QueueAR->queueNumber;

        if ((int) $currentInQueueNumber >= (int) $pack['packNumber']) {
            self::addExchError("ExchangePlan::apply Пакет устарел (" . (int) $pack['packNumber'] . ')- ранее был получен пакет с большим номером '
                    . (int) $currentInQueueNumber);
            return (int) $pack['packNumber'];
        }

        if (is_array($pack['data'])) {
            //self::addExchError('apply Получен пакет '.var_export($pack['data'],true));
            $uNamesIn = array_keys($pack['data']);

            // Возьмем известные нам модели
            $models = self::$config['modelsWithSerializationInterface'];
            $uName2Model = [];
            $ClassName2uName = [];
            foreach ($models as $className) {
                $model = new $className;
                $uName = $model::ModelUniversalName($node);
                $uName2Model[$uName] = $model;
                $ClassName2uName[$className] = $uName;
            }

            $errorUNames = array_diff($uNamesIn, array_keys($uName2Model));
            if (count($errorUNames)) {
                self::CrashAndAlert('ExchangePlan::apply Получен пакет с неизвестными uName моделей: ' . explode(', ', $errorUNames));
            }
        }

        // Фиксируем факт принятия корректного пакета
        $QueueAR->queueNumber = (int) $pack['packNumber'];
        $QueueAR->save(false);

        if (empty($pack['data']) && empty($pack['remove'])) { // данных нет - корректно завершаемся
            return (int) $pack['packNumber'];
        }

        if (is_array($pack['data'])) {
            $orderLoad = self::$config['modelsWithSerializationInterface'];
            foreach ($orderLoad as $className) { // Зададим порядок загрузки
                $uName = $ClassName2uName[$className];

                if (empty($pack['data'][$uName])) {
                    continue;
                }
                $list = $pack['data'][$uName];
                $model = $uName2Model[$uName];

                foreach ($list as $item) {
                    if (empty($item[$uName]['uid'])) {
                        continue;
                    }
                    if ($obj = $model::decode($item, $node)) {
                        if (!$obj->pureSave()) { // без регистрации в плане обмена                        
			    $fp = fopen(\Yii::getAlias('@app').'/crash',"a+");
			    fwrite($fp,var_export($obj,true));
			    fclose($fp); 
                            self::CrashAndAlert('Не удалось записать из транзакции ' . var_export($obj->errors, true));
                        }
                    }
                }
            }
        }

        // отправим списки к удалению, без сортировки
        foreach ($pack['remove'] as $uName => $list) {
            $mini = array_filter($list); // фильтр от падения (снос счетчиков) и логических ошибок
            if (is_array($mini) && count($mini)) {
                $model = $uName2Model[$uName];
                $model::onRemoteDeletes($mini, $node);

                self::deleteAll([
                    'uid' => $mini,
                    'className' => self::$config['modelsWithSerializationInterface'],
                    'node' => $node,
                    'special' => self::SPEC_ALL_WITH_UID,
                ]); // Удалим круговую регистрацию, мы не знаем что сделает конечник, регистрацию тупо удаляем
            }
        }

        return (int) $pack['packNumber'];
    }

	
    /** Получение подтвеждения пакета 
     * 
     * @param string $node - consumer
     * @param int $ackQueueNumber - подтверждаемая квитанция
     * &&console
     */
    public static function acknowledge(string $node, int $ackQueueNumber): void {

        self::reconfig();

        if (!in_array($node, self::$config['consumers'])) {
            self::CrashAndAlert('ExchangePlan::apply Отправителя нет в списке customers в config.php');
        }
        // узнаем текущие исходящие номера пакетов 
        if (self::find()
                        ->where([
                            'AND',
                            ['special' => self::SPEC_SENDED,
                                'className' => self::$config['modelsWithSerializationInterface'],
                                'node' => $node,],
                            ['<', 'queueNumber', $ackQueueNumber],
                        ])->exists()) {

            // сложный случай - предыдущие пакеты потеряны
            // расформируем старые пакеты. Удалим, что обновилось, остальное добавим в новый пакет

            $currentPlanToSend = self::find()->select(['id', 'className', 'uid'])
                            ->where([
                                'special' => self::SPEC_SENDED,
                                'node' => $node,
                                'className' => self::$config['modelsWithSerializationInterface'],
                                'queueNumber' => $ackQueueNumber,
                            ])->all();

            if (count($currentPlanToSend)) {

                //  убираем текущий пакет, что бы мускул меньше идти по итерации
                self::deleteAll(['id' => ArrayHelper::getColumn($currentPlanToSend, 'id')]);

                $ass = array_map(
                        function($rec) {
                    return "('" . $rec->className . "', '" . $rec->uid . "')";
                },
                        $currentPlanToSend
                );
                self::deleteAll([
                    'AND',
                    ['special' => self::SPEC_SENDED,
                        'node' => $node,
                    ],
                    ['<', 'queueNumber', $ackQueueNumber],
                    '(className, uid) IN (' . implode(', ', $ass) . ')',
                ]);
            }

            // перепишем старые изменения в новый пакет  
            $QueueAR = self::getQueueAR($node, self::SPEC_OUT_NUMBER, 1);

            self::updateAll(['special' => self::SPEC_REGULAR,],
                    ['AND',
                        ['special' => self::SPEC_SENDED,
                            'className' => self::$config['modelsWithSerializationInterface'],
                            'node' => $node,],
                        ['<', 'queueNumber', $ackQueueNumber],
            ]);
        } else {
            // простой случай - просто убираем пакет
            self::deleteAll([
                'special' => self::SPEC_SENDED,
                'node' => $node,
                'queueNumber' => $ackQueueNumber,
                'className' => self::$config['modelsWithSerializationInterface'],
            ]);
        }
    }

}
