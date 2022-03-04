<?php
namespace app\components\DBexchangePlan\interfaces;

/**
 * Интерфейс: отправка и прием пакетов
 *
 * @author <wberdnik@gmail.com>
 */
interface ExchangeInterface {

    
    /** Регистрация изменений моделей SerializationInterface  Model::afterSave
     * 
     * @param bool $insert - запись ActiveRecord в режиме insert
     * @param SerializationInterface $model
     */
    public static function touch(bool $insert, SerializationInterface $model): void;
    
    
    /** Массовая регистрация изменений моделей SerializationInterface  Model::UpdateAll, DeleteAll
     * 
     * @param array $condition - отбор по модели
     * @param string $className
     */
    public static function multiTouch(array $condition, string $className): void;

    
    /** Извлечь пакет для отправки
     * 
     * @param string $node - consumer
     * @param int $startCanary - начало интервала канарееечного отбора
     * @param int $endCanary - начало интервала канарееечного отбора
     */
    public static function fetch(string $node,int $startCanary = 0, int $endCanary = 100): ?array;

    
    /** Применить пакет
     * 
     * @param string $node - producer
     * @param array $pack - данные
     * @return int Номер квитанции подтверждения
     */
    public static function apply(string $node, array $pack): ?int;

    
    /** Фиксация квитанции пакета 
     * 
     * @param string $node - consumer
     * @param int $ackQueueNumber - подтверждаемая квитанция
     */
    public static function acknowledge(string $node, int $ackQueueNumber): void;
    
    
    /** Регистрация ошибки при обмене
     * 
     * @param string $text
     * @return void
     */
    public static function addExchError(string $text):void;
}
